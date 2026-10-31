<?php

declare(strict_types=1);

if (!defined('_PS_VERSION_')) {
    exit;
}
require_once __DIR__ . '/autoload.php';

use Lunixi\PrestaShop\{Access, Bridge, MailOutbox, Runtime, Settings, Storage};
use PrestaShop\PrestaShop\Core\Payment\PaymentOption;

class Lunixipay extends PaymentModule
{
    /** Set only around native financial application; emails become transactional outbox entries. */
    public static bool $mailTransaction = false;

    public function __construct()
    {
        $this->name = 'lunixipay';
        $this->tab = 'payments_gateways';
        $this->version = '0.1.5';
        $this->author = 'Lunixi';
        $this->need_instance = 0;
        $this->bootstrap = true;
        $this->currencies = true;
        $this->currencies_mode = 'checkbox';
        $this->ps_versions_compliancy = ['min' => '8.1.0', 'max' => '9.99.99'];
        $this->controllers = ['checkout', 'return', 'webhook'];
        parent::__construct();
        $this->displayName = $this->trans('Lunixi Payments', [], 'Modules.Lunixipay.Admin');
        $this->description = $this->trans('Secure Lunixi checkout with merchant-managed payment methods.', [], 'Modules.Lunixipay.Admin');
        $this->confirmUninstall = $this->trans('Disable the module? Payment history and encrypted credentials are retained for reconciliation.', [], 'Modules.Lunixipay.Admin');
    }
    public function isUsingNewTranslationSystem()
    {
        return true;
    }
    public function install(): bool
    {
        if (PHP_VERSION_ID < 80100 || !extension_loaded('sodium') || !extension_loaded('curl') || PHP_INT_SIZE !== 8) {
            return false;
        }
        try {
            Storage::install();
            if (!parent::install()) {
                return false;
            }
            foreach (['paymentOptions', 'paymentReturn', 'displayAdminOrderMainBottom', 'actionEmailSendBefore'] as $hook) {
                if (!$this->registerHook($hook)) {
                    return false;
                }
            }
            $this->states();
            if (Tab::getIdFromClassName('AdminLunixiPayments')) {
                return true;
            }
            $tab = new Tab();
            $tab->active = true;
            $tab->class_name = 'AdminLunixiPayments';
            $tab->id_parent = -1;
            $tab->module = $this->name;
            foreach (Language::getLanguages(false, false, false) as $language) {
                if (!is_array($language)) {
                    throw new RuntimeException('Invalid native language record.');
                }
                $tab->name[(int) $language['id_lang']] = 'Lunixi';
            }
            return $tab->add();
        } catch (Throwable $e) {
            return false;
        }
    }
    public function uninstall()
    {
        $id = (int) Tab::getIdFromClassName('AdminLunixiPayments');
        if ($id) {
            (new Tab($id))->delete();
        }
        // Do not destroy financial ledger, native order states or encrypted credentials.
        return parent::uninstall();
    }
    private function states(): void
    {
        $labels = ['PENDING' => ['Awaiting Lunixi payment', 'Lunixi ödemesi bekleniyor'],
                   'AUTHORIZED' => ['Lunixi authorization on hold', 'Lunixi provizyonu beklemede'],
                   'REVIEW' => ['Lunixi reconciliation required', 'Lunixi mutabakatı gerekli'],
                   'PARTIAL_REFUND' => ['Lunixi partially refunded', 'Lunixi kısmi iade']];
        foreach ($labels as $suffix => $label) {
            if (Configuration::get('LUNIXI_OS_' . $suffix)) {
                continue;
            }
            $state = new OrderState();
            $state->module_name = $this->name;
            $state->color = '#5C4AB5';
            $state->send_email = false;
            $state->invoice = false;
            $state->logable = $suffix === 'PARTIAL_REFUND';
            $state->paid = $suffix === 'PARTIAL_REFUND';
            $state->shipped = false;
            $state->delivery = false;
            $state->unremovable = true;
            foreach (Language::getLanguages(false, false, false) as $language) {
                if (!is_array($language)) {
                    throw new RuntimeException('Invalid native language record.');
                }
                $state->name[(int) $language['id_lang']] = $language['iso_code'] === 'tr' ? $label[1] : $label[0];
            }
            if (!$state->add() || !Configuration::updateValue('LUNIXI_OS_' . $suffix, (int) $state->id)) {
                throw new RuntimeException('Cannot install native states.');
            }
        }
    }
    public function getContent(): string
    {
        $shopId = (int) Runtime::shop($this->context)->id;
        $message = '';
        if (Shop::getContext() !== Shop::CONTEXT_SHOP) {
            return $this->displayError($this->trans('Select one shop before configuring Lunixi.', [], 'Modules.Lunixipay.Admin'));
        }
        if (Tools::isSubmit('submitLunixi')) {
            try {
                Access::post(
                    (string) ($_SERVER['REQUEST_METHOD'] ?? ''),
                    (string) Tools::getValue('token'),
                    (string) Tools::getAdminTokenLite('AdminModules'),
                    Runtime::employee($this->context)->isLoggedBack() && Runtime::employee($this->context)->can('edit', 'AdminModules')
                );
                Settings::save(
                    $shopId,
                    (string) Tools::getValue('LUNIXI_MERCHANT'),
                    (string) Tools::getValue('LUNIXI_KEY_ID'),
                    (string) Tools::getValue('LUNIXI_PRIVATE_KEY'),
                    (string) Tools::getValue('LUNIXI_WEBHOOK_SECRET'),
                    (string) Tools::getValue('LUNIXI_MODE'),
                    Tools::getValue('LUNIXI_PREAUTH') === '1'
                );
                $message = $this->displayConfirmation($this->trans('Settings saved.', [], 'Modules.Lunixipay.Admin'));
            } catch (Throwable $e) {
                $message = $this->displayError($this->trans('Configuration could not be saved. Check permissions, server encryption key and merchant credentials.', [], 'Modules.Lunixipay.Admin'));
            }
        }
        $data = [];
        try {
            $data = Settings::secrets($shopId);
        } catch (Throwable $e) {
        /* Empty form when not configured; no secret output. */
        }
        $helper = new HelperForm();
        $helper->module = $this;
        $helper->show_toolbar = false;
        $helper->default_form_language = (int) Runtime::language($this->context)->id;
        $helper->identifier = $this->identifier;
        $helper->submit_action = 'submitLunixi';
        $helper->currentIndex = AdminController::$currentIndex . '&configure=' . $this->name;
        $helper->token = Tools::getAdminTokenLite('AdminModules');
        $helper->fields_value = ['LUNIXI_MERCHANT' => $data['merchantId'] ?? '', 'LUNIXI_KEY_ID' => $data['keyId'] ?? '',
            'LUNIXI_PRIVATE_KEY' => '', 'LUNIXI_WEBHOOK_SECRET' => '', 'LUNIXI_MODE' => Settings::mode($shopId),
            'LUNIXI_PREAUTH' => (int) Configuration::get('LUNIXI_PREAUTH', null, null, $shopId)];
        $t = fn(string $s): string => $this->trans($s, [], 'Modules.Lunixipay.Admin');
        $form = ['form' => ['legend' => ['title' => $this->displayName], 'input' => [
            ['type' => 'text', 'name' => 'LUNIXI_MERCHANT', 'label' => $t('Merchant ID'), 'required' => true],
            ['type' => 'text', 'name' => 'LUNIXI_KEY_ID', 'label' => $t('Key ID'), 'required' => true],
            ['type' => 'textarea', 'name' => 'LUNIXI_PRIVATE_KEY', 'label' => $t('Private Ed25519 key'), 'desc' => $t('Leave empty to keep the saved key. Secrets are never displayed.')],
            ['type' => 'password', 'name' => 'LUNIXI_WEBHOOK_SECRET', 'label' => $t('Webhook secret'), 'desc' => $t('Leave empty to keep the saved secret.')],
            ['type' => 'select', 'name' => 'LUNIXI_MODE', 'label' => $t('Checkout mode'), 'options' => ['query' => [
                ['id' => 'embedded', 'name' => $t('Embedded Lunixi form')], ['id' => 'hosted', 'name' => $t('Hosted Lunixi form')]], 'id' => 'id', 'name' => 'name']],
            ['type' => 'switch', 'name' => 'LUNIXI_PREAUTH', 'label' => $t('Authorize first, capture later'), 'is_bool' => true,
             'values' => [['id' => 'preauth_on', 'value' => 1, 'label' => $t('Enabled')], ['id' => 'preauth_off', 'value' => 0, 'label' => $t('Disabled')]]]
        ], 'submit' => ['title' => $t('Save')]]];
        Runtime::smarty($this->context)->assign(['lunixi_webhook' => Runtime::link($this->context)->getModuleLink((string) $this->name, 'webhook', ['shop_id' => $shopId], true)]);
        return $message . $this->fetch('module:lunixipay/views/templates/admin/instructions.tpl') . $helper->generateForm([$form]);
    }
    /**
     * @param array<string,mixed> $params
     * @return list<PaymentOption>
     */
    public function hookPaymentOptions(array $params): array
    {
        if (!$this->active || !Configuration::get('LUNIXI_CREDENTIALS') || !Runtime::customer($this->context)->isLogged(true)) {
            return [];
        }
        $option = new PaymentOption();
        $option->setModuleName((string) $this->name)->setCallToActionText($this->trans('Pay securely with Lunixi', [], 'Modules.Lunixipay.Shop'))
            ->setAction(Runtime::link($this->context)->getModuleLink((string) $this->name, 'checkout', [], true))
            ->setInputs([['name' => 'lunixi_csrf', 'type' => 'hidden', 'value' => Tools::getToken(false)]])
            ->setLogo($this->_path . 'views/img/lunixi.svg')
            ->setAdditionalInformation($this->fetch('module:lunixipay/views/templates/front/option.tpl'));
        return [$option];
    }
    /** @param array<string,mixed> $params */
    public function hookPaymentReturn(array $params): string
    {
        $order = $params['order'] ?? null;
        try {
            if (!$order instanceof Order) {
                return '';
            } Access::buyer(Runtime::context($this->context), $order);
        } catch (Throwable $e) {
            return '';
        }
        return $this->fetch('module:lunixipay/views/templates/front/return.tpl');
    }
    /** @param array<string,mixed> $params */
    public function hookDisplayAdminOrderMainBottom(array $params): string
    {
        $order = new Order((int) ($params['id_order'] ?? 0));
        if ($order->module !== $this->name) {
            return '';
        }
        try {
            Access::employee(Runtime::context($this->context), $order);
            $record = Storage::attempt(Bridge::key($order));
        } catch (Throwable $e) {
            return '';
        }
        $pending = Storage::unresolved((int) $order->id, $record);
        Runtime::smarty($this->context)->assign(['lunixi_record' => $record, 'lunixi_order_id' => (int) $order->id,
            'lunixi_admin_url' => Runtime::link($this->context)->getAdminLink('AdminLunixiPayments'),
            'lunixi_token' => Tools::getAdminTokenLite('AdminLunixiPayments'), 'lunixi_pending' => $pending, 'lunixi_operation' => $pending['operation_id'] ?? bin2hex(random_bytes(24)),
            'lunixi_amount' => $pending && $pending['amount'] !== null ? \Lunixi\PrestaShop\Vendor\Lunixi\Sdk\Commerce\Money::decimal((int) $pending['amount'], Bridge::exponent($order)) : '']);
        return $this->fetch('module:lunixipay/views/templates/admin/order.tpl');
    }
    /** @param array<string,mixed> $params */
    public function hookActionEmailSendBefore(array $params): bool
    {
        if (self::$mailTransaction) {
            MailOutbox::capture($params);
            return false;
        }
        return true;
    }
    /** Serialize initial native cart conversion; payment reservations are committed separately before I/O. */
    public function pendingOrder(Cart $cart): Order
    {
        $pdo = Storage::pdo();
        $lock = 'lunixi-cart-' . (int) $cart->id_shop . '-' . (int) $cart->id;
        $q = $pdo->prepare('SELECT GET_LOCK(?, 10)');
        $q->execute([$lock]);
        if ((int) $q->fetchColumn() !== 1) {
            throw new RuntimeException('Checkout is already being processed.');
        }
        try {
            $select = $pdo->prepare('SELECT id_order,module FROM `' . _DB_PREFIX_ . 'orders` WHERE id_cart = ?');
            $select->execute([(int) $cart->id]);
            $rows = $select->fetchAll(PDO::FETCH_ASSOC);
            if (!$rows) {
                $packages = $cart->getPackageList();
                $count = 0;
                foreach ($packages as $byAddress) {
                    $count += count($byAddress);
                }
                if ($count !== 1) {
                    throw new RuntimeException('One shipping package per checkout is required.');
                }
                $customer = new Customer((int) $cart->id_customer);
                $originalCustomer = Runtime::customer($this->context);
                try {
                    $this->validateOrder(
                        (int) $cart->id,
                        (int) Configuration::get('LUNIXI_OS_PENDING'),
                        $cart->getOrderTotal(true, Cart::BOTH),
                        $this->displayName,
                        null,
                        [],
                        (int) $cart->id_currency,
                        false,
                        $customer->secure_key
                    );
                } finally {
                    Runtime::context($this->context)->customer = $originalCustomer;
                }
                $select = $pdo->prepare('SELECT id_order,module FROM `' . _DB_PREFIX_ . 'orders` WHERE id_cart = ?');
                $select->execute([(int) $cart->id]);
                $rows = $select->fetchAll(PDO::FETCH_ASSOC);
            }
            if (count($rows) !== 1 || $rows[0]['module'] !== $this->name) {
                throw new RuntimeException('Cart has an incompatible existing order.');
            }
            return new Order((int) $rows[0]['id_order']);
        } finally {
            $pdo->prepare('SELECT RELEASE_LOCK(?)')->execute([$lock]);
        }
    }
}
