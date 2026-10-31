<?php

declare(strict_types=1);

use Lunixi\PrestaShop\Runtime;
use Lunixi\PrestaShop\{Access, Bridge};
use Lunixi\PrestaShop\Vendor\Lunixi\Sdk\Commerce\Money;

class AdminLunixiPaymentsController extends ModuleAdminController
{
    public function postProcess(): void
    {
        try {
            Access::post((string) ($_SERVER['REQUEST_METHOD'] ?? ''), (string) Tools::getValue('token'), (string) Tools::getAdminTokenLite('AdminLunixiPayments'), (bool) $this->access('edit'));
            $order = new Order((int) Tools::getValue('id_order'));
            if ($order->module !== 'lunixipay') {
                throw new RuntimeException('Module mismatch.');
            }
            Access::employee($this->context, $order);
            $bridge = new Bridge((int) $order->id_shop);
            $action = (string) Tools::getValue('lunixi_action');
            if ($action === 'reconcile') {
                $bridge->reconcile($order);
            } else {
                $amount = $action === 'void' ? null : Money::minor((string) Tools::getValue('amount'), Bridge::exponent($order));
                $bridge->mutate($order, (string) Tools::getValue('operation_id'), $action, $amount);
                $status = $bridge->operationStatus($order, (string) Tools::getValue('operation_id'));
                if (($status['state'] ?? '') !== 'confirmed') {
                    throw new RuntimeException('Settlement is not confirmed.');
                }
            }
            Tools::redirectAdmin(Runtime::link($this->context)->getAdminLink('AdminOrders') . '&vieworder&id_order=' . (int) $order->id);
        } catch (Throwable $e) {
            http_response_code(503);
            $this->errors[] = $this->trans('Operation is unconfirmed. Reconcile and retry using the same operation ID and amount.', [], 'Modules.Lunixipay.Admin');
        }
    }
}
