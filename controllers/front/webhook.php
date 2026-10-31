<?php

declare(strict_types=1);

use Lunixi\PrestaShop\Runtime;
use Lunixi\PrestaShop\{Bridge, Settings, Storage};

class LunixipayWebhookModuleFrontController extends ModuleFrontController
{
    public $ssl = true;
    public $display_header = false;
    public $display_footer = false;
    public $ajax = true;
    public function postProcess(): void
    {
        header('Content-Type: application/json');
        header('Cache-Control: no-store');
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            http_response_code(405);
            exit('{"ok":false}');
        }
        try {
            $shopId = (int) Tools::getValue('shop_id');
            if ($shopId < 1 || $shopId !== (int) Runtime::shop($this->context)->id) {
                throw new RuntimeException('Shop mismatch.');
            }
            $raw = file_get_contents('php://input', false, null, 0, 1048577);
            if (!is_string($raw) || strlen($raw) > 1048576) {
                throw new RuntimeException('Body too large.');
            }
            $headers = [];
            foreach (['event-id', 'event-type', 'signature-timestamp', 'signature'] as $name) {
                $key = 'HTTP_X_LUNIXI_' . strtoupper(str_replace('-', '_', $name));
                if (isset($_SERVER[$key])) {
                    $headers['x-lunixi-' . $name] = (string) $_SERVER[$key];
                }
            }
            $bridge = new Bridge($shopId);
            try {
                $event = $bridge->webhook($raw, $headers, $shopId);
            } catch (Throwable $e) {
                http_response_code(400);
                exit('{"ok":false}');
            }
            // Locate only by the authenticated payment id persisted in our ledger.
            $paymentId = $event->data()['paymentId'] ?? $event->data()['paymentIntentId'] ?? $event->data()['intentId'] ?? $event->data()['id'] ?? '';
            if (!is_string($paymentId) || $paymentId === '') {
                throw new RuntimeException('Payment identity missing.');
            }
            $q = Storage::pdo()->prepare('SELECT order_key FROM `' . _DB_PREFIX_ . 'lunixi_attempts` WHERE JSON_UNQUOTE(JSON_EXTRACT(payload, "$.paymentId")) = ?');
            $q->execute([$paymentId]);
            $keys = $q->fetchAll(PDO::FETCH_COLUMN);
            if (count($keys) !== 1 || !preg_match('/^ps:' . $shopId . ':order:([0-9]+)$/D', $keys[0], $match)) {
                throw new RuntimeException('Unbound payment.');
            }
            $order = new Order((int) $match[1]);
            if ($order->module !== 'lunixipay' || (int) $order->id_shop !== $shopId) {
                throw new RuntimeException('Order mismatch.');
            }
            $bridge->reconcile($order, $event);
            exit('{"ok":true}');
        } catch (Throwable $e) {
            http_response_code(503);
            exit('{"ok":false}');
        }
    }
}
