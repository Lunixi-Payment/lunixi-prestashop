<?php

declare(strict_types=1);

use Lunixi\PrestaShop\Runtime;
use Lunixi\PrestaShop\{Access, Bridge, Settings};

class LunixipayCheckoutModuleFrontController extends ModuleFrontController
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
        try {
            if (!Runtime::module($this->module)->active) {
                throw new RuntimeException('Module is disabled.');
            }
            Access::post((string) ($_SERVER['REQUEST_METHOD'] ?? ''), (string) Tools::getValue('lunixi_csrf'), Tools::getToken(false), true);
            if (Tools::getValue('id_order')) {
                $order = new Order((int) Tools::getValue('id_order'));
                Access::buyer($this->context, $order);
            } else {
                Access::post(
                    (string) ($_SERVER['REQUEST_METHOD'] ?? ''),
                    (string) Tools::getValue('lunixi_csrf'),
                    Tools::getToken(false),
                    (int) Runtime::cart($this->context)->id_customer === (int) Runtime::customer($this->context)->id
                );
                $order = Runtime::module($this->module)->pendingOrder(Runtime::cart($this->context));
                Access::buyer($this->context, $order);
            }
            $callback = Runtime::link($this->context)->getModuleLink('lunixipay', 'return', ['id_order' => (int) $order->id], true);
            $session = (new Bridge((int) $order->id_shop))->start($order, $callback);
            header('Cache-Control: private, no-store');
            header('Referrer-Policy: no-referrer');
            if (Settings::mode((int) $order->id_shop) === 'hosted') {
                Tools::redirect($session->hostedUrl());
            }
            Runtime::smarty($this->context)->assign(['lunixi_browser_config' => $session->browserConfig()]);
            $this->setTemplate('module:lunixipay/views/templates/front/checkout.tpl');
        } catch (Throwable $e) {
            http_response_code(503);
            if (isset($order)) {
                try {
                    Access::buyer($this->context, $order);
                    Runtime::smarty($this->context)->assign([
                    'lunixi_retry_url' => Runtime::link($this->context)->getModuleLink('lunixipay', 'checkout', ['id_order' => (int) $order->id], true),
                    'lunixi_retry_csrf' => Tools::getToken(false),
                    ]);
                } catch (Throwable $ignored) {
/* Never expose retry controls for another buyer's order. */
                }
            }
            $this->errors[] = $this->trans('Payment is pending. Please retry this checkout or contact the shop.', [], 'Modules.Lunixipay.Shop');
            $this->setTemplate('module:lunixipay/views/templates/front/error.tpl');
        }
    }
    public function setMedia()
    {
        parent::setMedia();
        $this->registerJavascript('lunixi-official-checkout', 'https://cdn.lunixi.com/lunixi-checkout.min.js', ['server' => 'remote', 'position' => 'bottom', 'priority' => 150]);
        $this->registerJavascript('lunixi-checkout-mount', 'modules/lunixipay/views/js/checkout.js', ['position' => 'bottom', 'priority' => 160]);
        return true;
    }
}
