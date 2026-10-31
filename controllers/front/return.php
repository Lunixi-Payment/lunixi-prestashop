<?php

declare(strict_types=1);

use Lunixi\PrestaShop\Runtime;
use Lunixi\PrestaShop\{Access, Bridge};

class LunixipayReturnModuleFrontController extends ModuleFrontController
{
    public $ssl = true;
    // Native guest sessions are authenticated by isLogged(true) and ownership checks below.
    public $auth = false;
    public function postProcess(): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
            // Gateway browser returns can arrive without SameSite=Lax cookies.
            // Ignore every submitted field and restore the session on a GET.
            $id = filter_var($_GET['id_order'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            $raw = file_get_contents('php://input', false, null, 0, 1048577);
            if ($raw === false || strlen($raw) > 1048576) {
                http_response_code(413);
                exit;
            }
            if ($id === false) {
                http_response_code(400);
                exit;
            }
            $location = Runtime::link($this->context)->getModuleLink('lunixipay', 'return', ['id_order' => $id], true);
            header('Cache-Control: no-store');
            header('Location: ' . $location, true, 303);
            exit;
        }
        if (!in_array($_SERVER['REQUEST_METHOD'] ?? '', ['GET', 'HEAD'], true)) {
            http_response_code(405);
            exit;
        }
        if (!Runtime::customer($this->context)->isLogged(true)) {
            Tools::redirect(Runtime::link($this->context)->getPageLink('authentication', true));
            return;
        }
        $order = new Order((int) ($_GET['id_order'] ?? 0));
        try {
            Access::buyer($this->context, $order);
            // Client-submitted status is ignored; this is an authenticated gateway GET.
            try {
                (new Bridge((int) $order->id_shop))->reconcile($order);
            } catch (Throwable $e) {
            /* Preserve pending/review and let cron/webhook recover. */
            }
            Tools::redirect(Runtime::link($this->context)->getPageLink(
                'order-confirmation',
                true,
                null,
                ['id_cart' => (int) $order->id_cart, 'id_module' => (int) Runtime::module($this->module)->id, 'id_order' => (int) $order->id, 'key' => $order->secure_key]
            ));
        } catch (Throwable $e) {
            http_response_code(403);
            $this->setTemplate('module:lunixipay/views/templates/front/error.tpl');
        }
    }
}
