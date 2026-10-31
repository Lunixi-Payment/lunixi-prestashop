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
        if (!Runtime::customer($this->context)->isLogged(true)) {
            Tools::redirect(Runtime::link($this->context)->getPageLink('authentication', true));
            return;
        }
        $order = new Order((int) Tools::getValue('id_order'));
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
