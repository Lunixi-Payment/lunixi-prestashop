<?php

declare(strict_types=1);

use Lunixi\PrestaShop\Runtime;
use Lunixi\PrestaShop\{Access, Bridge, IdentityNumber, OrderDetails, OrderDetailsException, Settings};

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
            // The buyer's browser address of this request; PrestaShop orders do not store one.
            $buyerIp = (string) Tools::getRemoteAddr();
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
                $cart = Runtime::cart($this->context);
                // A cart already converted keeps its order's addresses; Bridge::start() checks that order.
                if (!$cart->orderExists()) {
                    try {
                        IdentityNumber::ensureOnCart($cart, (string) Tools::getValue(IdentityNumber::FIELD));
                        OrderDetails::assertCart($cart, $buyerIp);
                    } catch (OrderDetailsException $missing) {
                        // No order exists yet: back to the payment step with the native notification.
                        $this->errors[] = $this->detailsMessage($missing);
                        $this->redirectWithNotifications(Runtime::link($this->context)->getPageLink('order', true));
                        return;
                    }
                }
                $order = Runtime::module($this->module)->pendingOrder($cart);
                Access::buyer($this->context, $order);
            }
            $callback = Runtime::link($this->context)->getModuleLink('lunixipay', 'return', ['id_order' => (int) $order->id], true);
            $session = (new Bridge((int) $order->id_shop))->start($order, $callback, $buyerIp);
            header('Cache-Control: private, no-store');
            header('Referrer-Policy: no-referrer');
            if (Settings::mode((int) $order->id_shop) === 'hosted') {
                Tools::redirect($session->hostedUrl());
            }
            Runtime::smarty($this->context)->assign(['lunixi_browser_config' => $session->browserConfig()]);
            $this->setTemplate('module:lunixipay/views/templates/front/checkout.tpl');
        } catch (OrderDetailsException $missing) {
            // The native order lacks a required detail; no payment attempt was reserved or sent.
            http_response_code(422);
            $this->errors[] = $this->detailsMessage($missing);
            Runtime::smarty($this->context)->assign(['lunixi_details_error' => true]);
            $this->setTemplate('module:lunixipay/views/templates/front/error.tpl');
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
    /** Customer message naming the missing details; never their values. */
    private function detailsMessage(OrderDetailsException $missing): string
    {
        $fields = $missing->fields();
        if ($fields === ['buyer.identityNumber']) {
            return $this->trans('Enter your 11-digit Turkish ID number or your passport number to pay.', [], 'Modules.Lunixipay.Shop');
        }
        if ($fields === ['buyer.ip']) {
            return $this->trans('Your connection could not be verified. Please try again.', [], 'Modules.Lunixipay.Shop');
        }
        if (in_array('basketItems', $fields, true)) {
            return $this->trans('This order cannot be paid online because its totals could not be itemized. Please contact the shop.', [], 'Modules.Lunixipay.Shop');
        }
        // Whole literals keep the translation keys extractable.
        $labels = [
            'name' => $this->trans('first name', [], 'Modules.Lunixipay.Shop'),
            'surname' => $this->trans('last name', [], 'Modules.Lunixipay.Shop'),
            'contactName' => $this->trans('name', [], 'Modules.Lunixipay.Shop'),
            'identityNumber' => $this->trans('Turkish ID or passport number', [], 'Modules.Lunixipay.Shop'),
            'email' => $this->trans('email address', [], 'Modules.Lunixipay.Shop'),
            'gsmNumber' => $this->trans('phone number', [], 'Modules.Lunixipay.Shop'),
            'address' => $this->trans('street address', [], 'Modules.Lunixipay.Shop'),
            'city' => $this->trans('city', [], 'Modules.Lunixipay.Shop'),
            'country' => $this->trans('country', [], 'Modules.Lunixipay.Shop'),
            'zipCode' => $this->trans('postal code', [], 'Modules.Lunixipay.Shop'),
            'billingAddress' => $this->trans('invoice address', [], 'Modules.Lunixipay.Shop'),
            'shippingAddress' => $this->trans('delivery address', [], 'Modules.Lunixipay.Shop'),
        ];
        $names = [];
        foreach ($fields as $path) {
            $field = substr((string) strrchr('.' . $path, '.'), 1);
            if (isset($labels[$field]) && !in_array($labels[$field], $names, true)) {
                $names[] = $labels[$field];
            }
        }
        return $this->trans(
            'Some details required for payment are missing from your address: %fields%. Please update your address and try again.',
            ['%fields%' => implode(', ', $names)],
            'Modules.Lunixipay.Shop'
        );
    }
}
