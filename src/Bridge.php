<?php

declare(strict_types=1);

namespace Lunixi\PrestaShop;

use Lunixi\PrestaShop\Vendor\Lunixi\Sdk\Commerce\{CheckoutService, CheckoutSession, Money, OrderSnapshot, PaymentDecision};
use Lunixi\PrestaShop\Vendor\Lunixi\Sdk\LunixiClient;
use Lunixi\PrestaShop\Vendor\Lunixi\Sdk\Webhook\WebhookEvent;

/** Thin native order adapter; all financial evidence and signing live in the SDK. */
final class Bridge
{
    private CheckoutService $service;
    public function __construct(int $shopId, ?LunixiClient $client = null)
    {
        $this->service = new CheckoutService($client ?? Settings::client($shopId), Storage::ledger(), Settings::box());
    }
    public static function key(\Order $order): string
    {
        return 'ps:' . (int) $order->id_shop . ':order:' . (int) $order->id;
    }
    public static function exponent(\Order $order): int
    {
        $currency = new \Currency((int) $order->id_currency);
        $exponent = Money::exponent((string) $currency->iso_code);
        if ((int) $currency->precision !== $exponent) {
            throw new \RuntimeException('Native currency precision must match the gateway ISO currency exponent.');
        }
        return $exponent;
    }
    public static function total(\Order $order): int
    {
        // Read the native DECIMAL column as text; never multiply a PHP float.
        $q = Storage::pdo()->prepare('SELECT total_paid_tax_incl FROM `' . _DB_PREFIX_ . 'orders` WHERE id_order = ?');
        $q->execute([(int) $order->id]);
        $decimal = (string) $q->fetchColumn();
        $exponent = self::exponent($order);
        try {
            return Money::minor($decimal, $exponent);
        } catch (\InvalidArgumentException $error) {
            // Native totals keep computing precision beyond currency display precision.
            // Delegate rounding to PrestaShop's own exact decimal implementation.
            $modes = [PS_ROUND_UP => \PrestaShop\Decimal\Operation\Rounding::ROUND_CEIL,
                PS_ROUND_DOWN => \PrestaShop\Decimal\Operation\Rounding::ROUND_FLOOR,
                PS_ROUND_HALF_UP => \PrestaShop\Decimal\Operation\Rounding::ROUND_HALF_UP,
                PS_ROUND_HALF_DOWN => \PrestaShop\Decimal\Operation\Rounding::ROUND_HALF_DOWN,
                PS_ROUND_HALF_EVEN => \PrestaShop\Decimal\Operation\Rounding::ROUND_HALF_EVEN];
            if (!isset($modes[(int) $order->round_mode])) {
                throw new \RuntimeException('Native rounding mode requires manual reconciliation.');
            }
            $rounded = (new \PrestaShop\Decimal\DecimalNumber($decimal))->round($exponent, $modes[(int) $order->round_mode]);
            return Money::minor($rounded, $exponent);
        }
    }
    public function start(\Order $order, string $callback): CheckoutSession
    {
        $currency = new \Currency((int) $order->id_currency);
        $credentials = Settings::secrets((int) $order->id_shop);
        $snapshot = new OrderSnapshot($credentials['merchantId'], self::key($order), self::total($order), (string) $currency->iso_code);
        $hosted = Settings::mode((int) $order->id_shop) === 'hosted';
        $preauth = (bool) \Configuration::get('LUNIXI_PREAUTH', null, null, (int) $order->id_shop);
        $locale = (new \Language((int) $order->id_lang))->iso_code === 'tr' ? 'tr' : 'en';
        return $this->service->start(self::key($order), $snapshot, $callback, $hosted, $preauth, $locale);
    }
    /** @param array<string,string> $headers */
    public function webhook(string $body, array $headers, int $shopId): WebhookEvent
    {
        foreach (Settings::webhookSecrets($shopId) as $secret) {
            try {
                return $this->service->verifyWebhook($body, $headers, $secret);
            } catch (\Throwable $error) {
            /* Try retained rotation secret. */
            }
        }
        throw new \RuntimeException('Webhook verification failed.');
    }
    public function reconcile(\Order $order, ?WebhookEvent $event = null): PaymentDecision
    {
        return $this->service->reconcile(self::key($order), function (PaymentDecision $decision) use ($order): void {
            $this->apply($order, $decision);
        }, $event);
    }
    public function mutate(\Order $order, string $id, string $action, ?int $amount): PaymentDecision
    {
        $nativeId = Storage::operation((int) $order->id, $id, $action, $amount);
        return $this->service->mutate(
            self::key($order),
            $nativeId,
            $action,
            $amount,
            function (PaymentDecision $decision) use ($order): void {
                $this->apply($order, $decision);
            }
        );
    }
    /** @return array<string,mixed>|null */
    public function operationStatus(\Order $order, string $id): ?array
    {
        return $this->service->operationStatus(self::key($order), 'prestashop-operation:' . $id);
    }

    /** Called inside the SDK ledger's transaction, on the exact native PDO connection. */
    public function apply(\Order $oldOrder, PaymentDecision $decision): void
    {
        $lock = Storage::pdo()->prepare('SELECT id_order FROM `' . _DB_PREFIX_ . 'orders` WHERE id_order = ? FOR UPDATE');
        $lock->execute([(int) $oldOrder->id]);
        $oldOrder->clearCache();
        $order = new \Order((int) $oldOrder->id);
        if ($order->module !== 'lunixipay') {
            throw new \RuntimeException('Payment module mismatch.');
        }
        $query = Storage::pdo()->prepare('SELECT payload FROM `' . _DB_PREFIX_ . 'lunixi_attempts` WHERE order_key = ?');
        $query->execute([self::key($order)]);
        $record = json_decode((string) $query->fetchColumn(), true, 64, JSON_THROW_ON_ERROR);
        if (
            !is_array($record) || !is_array($record['snapshot'] ?? null) ||
            Money::integer($record['snapshot']['amount'] ?? null) !== self::total($order) ||
            ($record['snapshot']['currency'] ?? null) !== (new \Currency((int) $order->id_currency))->iso_code ||
            ($record['snapshot']['orderId'] ?? null) !== self::key($order)
        ) {
            throw new \RuntimeException('Native order changed after checkout reservation; reconciliation required.');
        }
        $stateKey = ['pending' => 'LUNIXI_OS_PENDING', 'review' => 'LUNIXI_OS_REVIEW', 'authorized' => 'LUNIXI_OS_AUTHORIZED', 'partially_captured' => 'LUNIXI_OS_AUTHORIZED',
                     'paid' => 'PS_OS_PAYMENT', 'partially_refunded' => 'LUNIXI_OS_PARTIAL_REFUND', 'refunded' => 'PS_OS_REFUND', 'failed' => 'PS_OS_ERROR'][$decision->state] ?? 'LUNIXI_OS_REVIEW';
        if ($decision->state === 'partially_refunded' && $decision->capturedAmount < $decision->chargedAmount) {
            $stateKey = 'LUNIXI_OS_AUTHORIZED';
        }
        $state = (int) \Configuration::get($stateKey, null, null, (int) $order->id_shop);
        if ($state < 1) {
            throw new \RuntimeException('Native order state is not configured.');
        }
        \Lunixipay::$mailTransaction = true;
        try {
            if (in_array($decision->state, ['paid', 'partially_refunded', 'refunded'], true) && $decision->capturedAmount >= $decision->chargedAmount) {
                $q = Storage::pdo()->prepare('SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'order_payment` WHERE order_reference = ? AND transaction_id = ?');
                $q->execute([$order->reference, $decision->paymentId]);
                if ((int) $q->fetchColumn() === 0 && !$order->addOrderPayment(Money::decimal(self::total($order), self::exponent($order)), 'Lunixi', $decision->paymentId, new \Currency((int) $order->id_currency))) {
                    throw new \RuntimeException('Native payment could not be saved.');
                }
            }
            if ((int) $order->current_state !== $state) {
                $history = new \OrderHistory();
                $history->id_order = (int) $order->id;
                $history->changeIdOrderState($state, $order, true);
                if (!$history->add()) {
                    throw new \RuntimeException('Native order history could not be saved.');
                }
                // Native status emails are queued as references, not sent pre-commit.
                MailOutbox::history((int) $history->id, (int) $order->id);
            }
        } finally {
            \Lunixipay::$mailTransaction = false;
        }
    }
}
