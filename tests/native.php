<?php
/** Real PrestaShop models/database with a deterministic synthetic gateway transport. */
declare(strict_types=1);
if (getenv('LUNIXI_NATIVE_TEST') !== '1') { throw new RuntimeException('Refusing native test mutations; set LUNIXI_NATIVE_TEST=1 only for an isolated disposable shop.'); }
$_SERVER['HTTP_HOST'] = 'localhost:8080';
$_SERVER['SERVER_PORT'] = '8080';
$_SERVER['REQUEST_METHOD'] = 'GET';
require ($argv[1] ?? '/var/www/prestashop') . '/config/config.inc.php';
if (!getenv('LUNIXI_ENCRYPTION_KEY')) { putenv('LUNIXI_ENCRYPTION_KEY=' . base64_encode(str_repeat('T', 32))); }

Context::getContext()->employee = new Employee(1);
require_once _PS_ROOT_DIR_ . '/app/AppKernel.php';
if (is_file(_PS_ROOT_DIR_ . '/app/AdminKernel.php')) {
    require_once _PS_ROOT_DIR_ . '/app/AdminKernel.php';
    $kernel = new AdminKernel('prod', false);
} else { $kernel = new AppKernel('prod', false); }
$kernel->boot();
Context::getContext()->container = $kernel->getContainer();
$module = Module::getInstanceByName('lunixipay');
if (!$module instanceof Lunixipay) { throw new RuntimeException('Standalone module autoload failed.'); }
if (!$module->id && !$module->install()) { throw new RuntimeException('Native module install failed.'); }
// Reconstruct through the core constructor after native activation in SQL.
// The object loaded before install can still have active=false on 8.2.
$module = new Lunixipay();
if (!$module->id || !$module->active) { throw new RuntimeException('Native module must be installed and active.'); }

use Lunixi\PrestaShop\{Access, Basket, Bridge, IdentityNumber, OrderDetails, OrderDetailsException, Settings, Storage};
use Lunixi\PrestaShop\Vendor\Lunixi\Sdk\{Configuration as SdkConfiguration, LunixiClient};
use Lunixi\PrestaShop\Vendor\Lunixi\Sdk\Http\{HttpClientInterface, HttpResponse};
use Lunixi\PrestaShop\Vendor\Lunixi\Sdk\Commerce\Money;

final class NativeGateway implements HttpClientInterface
{
    public ?string $paymentPageUrl = 'https://pay.lunixi.com/payment/server-issued-page-0123456789';
    public array $calls = [];
    public array $intent = [];
    public string $captured = '0';
    public string $refunded = '0';
    public string $status = 'INITIATED';
    public bool $lag = false;
    public bool $failIntent = false;
    public array $keys = [];
    /** @var array<string,string> other captured payments of the same order: id => captured minor */
    public array $linked = [];
    public function send(string $method, string $url, array $headers, ?string $body, float $timeout): HttpResponse
    {
        $path = parse_url($url, PHP_URL_PATH);
        $data = $body === null ? [] : json_decode($body, true, 32, JSON_THROW_ON_ERROR);
        $this->calls[] = [$method, $path, $headers, $data];
        if ($path === '/api/v1/auth/token') { return $this->json(['access_token' => 'at_synthetic_native_token', 'expires_in' => 1800]); }
        if ($path === '/api/v1/payments/intents') {
            if (!isset($headers['X-Signature'], $headers['Digest'], $headers['Idempotency-Key'])) { throw new RuntimeException('Signed idempotent intent required.'); }
            $this->keys[] = $headers['Idempotency-Key'];
            if ($this->failIntent) { throw new RuntimeException('Synthetic uncertain network outcome.'); }
            $this->intent = $data;
            $result = ['paymentId' => 'native_intent_' . $data['orderId'], 'token' => 'native_checkout_synthetic_TOKEN', 'status' => $this->status];
            if ($this->paymentPageUrl !== null) { $result['paymentPageUrl'] = $this->paymentPageUrl; }
            return $this->json(['data' => $result]);
        }
        if ($method === 'POST') {
            $key = $headers['Idempotency-Key'] ?? '';
            if (!isset($this->keys[$key]) && !$this->lag) {
                if (str_ends_with($path, '/refund')) { $this->refunded = \Lunixi\PrestaShop\Vendor\Lunixi\Sdk\Commerce\Money::add($this->refunded, $data['amount']); $this->status = $this->refunded === $this->captured ? 'REFUNDED' : 'PARTIALLY_REFUNDED'; }
                elseif (str_ends_with($path, '/capture')) { $this->captured = \Lunixi\PrestaShop\Vendor\Lunixi\Sdk\Commerce\Money::add($this->captured, $data['amount']); $this->status = $this->captured === $this->intent['amount'] ? 'CAPTURED' : 'PARTIALLY_CAPTURED'; }
                elseif (str_ends_with($path, '/void')) { $this->status = 'VOIDED'; }
                $this->keys[$key] = true;
            }
            return $this->json(['data' => ['status' => $this->status]]);
        }
        if (isset($this->linked[basename((string) $path)])) {
            $id = basename((string) $path);
            return $this->json(['data' => ['id' => $id, 'status' => 'CAPTURED', 'orderId' => $this->intent['orderId'], 'merchantId' => 'native_merchant',
                'amount' => $this->intent['amount'], 'currency' => $this->intent['currency'], 'metadata' => []],
                'details' => ['capturedAmount' => $this->linked[$id], 'refundedAmount' => '0']]);
        }
        return $this->json(['data' => ['id' => 'native_intent_' . $this->intent['orderId'], 'status' => $this->status,
            'orderId' => $this->intent['orderId'], 'merchantId' => 'native_merchant', 'amount' => $this->intent['amount'],
            'currency' => $this->intent['currency'], 'metadata' => $this->intent['metadata']],
            'details' => ['capturedAmount' => $this->captured, 'refundedAmount' => $this->refunded]]);
    }
    private function json(array $value): HttpResponse { return new HttpResponse(200, ['Content-Type' => 'application/json'], json_encode($value, JSON_THROW_ON_ERROR)); }
}

final class NativeAcceptance
{
    private int $assertions = 0;
    public function ok(bool $condition, string $message): void
    {
        ++$this->assertions;
        if (!$condition) { throw new RuntimeException('FAILED: ' . $message); }
    }
    public function rejects(callable $call, string $message): void
    {
        try { $call(); } catch (Throwable $e) { ++$this->assertions; return; }
        throw new RuntimeException('FAILED: ' . $message);
    }
    public function run(Lunixipay $module): void
    {
        $context = Context::getContext(); $context->employee = new Employee(1);
        $context->shop = new Shop(1); Shop::setContext(Shop::CONTEXT_SHOP, 1);
        $context->currency = new Currency((int) Configuration::get('PS_CURRENCY_DEFAULT'));
        $context->language = new Language((int) Configuration::get('PS_LANG_DEFAULT'));
        $context->controller = new FrontController();
        $context->container = \PrestaShop\PrestaShop\Adapter\ContainerBuilder::getContainer('front', false);
        Configuration::updateValue('PS_MAIL_METHOD', 3); // Synthetic tests never send email.
        $seed = sodium_crypto_sign_seed_keypair(str_repeat('S', 32));
        Configuration::deleteByName('LUNIXI_MODE');
        $this->ok(Settings::mode(1) === 'embedded', 'missing mode defaults to on-site form');
        Settings::save(1, 'native_merchant', 'mk_test_sk_synthetic_native', base64_encode(sodium_crypto_sign_secretkey($seed)), 'native_webhook_secret', 'hosted', false);
        $this->ok(Settings::mode(1) === 'hosted', 'explicit existing hosted preference retained');
        $cipher = (string) Configuration::get('LUNIXI_CREDENTIALS');
        $this->ok(!str_contains($cipher, 'native_webhook_secret'), 'admin credentials encrypted');
        $this->ok(Settings::secrets(1)['merchantId'] === 'native_merchant', 'credentials decrypt');
        $this->ok(Storage::pdo() === Db::getInstance()->getLink(), 'same native PDO connection');
        $cart = $this->cart($context);
        $order = $module->pendingOrder($cart);
        $this->ok($order->module === 'lunixipay', 'native PaymentModule order created');
        $this->ok((int) $order->current_state === (int) Configuration::get('LUNIXI_OS_PENDING'), 'order starts unpaid');
        $this->ok((int) $module->pendingOrder($cart)->id === (int) $order->id, 'cart retry creates one order');
        $this->ok(Bridge::total($order) === '10050', 'native decimal converts exactly');
        Storage::pdo()->prepare('UPDATE ps_orders SET total_paid_tax_incl = ? WHERE id_order = ?')->execute(['100.505000', $order->id]);
        $this->ok(Bridge::total($order) === '10051', 'native exact decimal half-up rounding before minor conversion');
        Storage::pdo()->prepare('UPDATE ps_orders SET total_paid_tax_incl = ? WHERE id_order = ?')->execute(['100.50', $order->id]);
        $this->ok(\Lunixi\PrestaShop\Vendor\Lunixi\Sdk\Commerce\Money::exponent('TRY') === 2, 'gateway ISO currency exponent enforced');
        Access::buyer($context, $order);
        $authenticatedCustomer = $context->customer;
        $context->customer = new Customer();
        $this->rejects(fn() => Access::buyer($context, $order), 'unauthenticated order access');
        $context->customer = $authenticatedCustomer;
        foreach ([['GET', 't', 't', true], ['POST', 'bad', 't', true], ['POST', 't', 't', false]] as $args) {
            $this->rejects(fn() => Access::post(...$args), 'CSRF/capability boundary');
        }
        $this->employeeAccess($context, $order);
        $transport = new NativeGateway();
        $sdk = new LunixiClient(new SdkConfiguration(['baseUrl' => Settings::GATEWAY, 'keyId' => 'mk_test_sk_synthetic_native',
            'privateKey' => base64_encode(sodium_crypto_sign_secretkey($seed)), 'maxRetries' => 0]), $transport);
        $bridge = new Bridge(1, $sdk);
        $transport->failIntent = true;
        $this->rejects(fn() => $bridge->start($order, 'https://merchant.example/return', '203.0.113.10'), 'uncertain creation does not mark paid');
        $reserved = Storage::attempt(Bridge::key($order));
        $this->ok($reserved['state'] === 'reserved' && $reserved['key'] !== '', 'attempt reservation committed before network');
        $transport->failIntent = false;
        $session = $bridge->start($order, 'https://merchant.example/return', '203.0.113.10');
        $this->ok(count(array_unique(array_filter($transport->keys, 'is_string'))) === 1, 'timeout uses same key');
        $intent = $transport->intent;
        $this->ok(($intent['buyer']['identityNumber'] ?? null) === '11111111110' && ($intent['buyer']['ip'] ?? null) === '203.0.113.10', 'buyer ID number from the invoice address, IP from the buyer request');
        $this->ok(array_column($intent['basketItems'] ?? [], 'price') === ['10050'] && ($intent['basketItems'][0]['itemType'] ?? null) === 'VIRTUAL', 'virtual order line is one exact VIRTUAL item');
        $this->ok(($intent['billingAddress']['contactName'] ?? null) === 'Synthetic Acceptance' && !isset($intent['shippingAddress']), 'virtual order sends the billing address only');
        $this->ok($session->hostedUrl() === $transport->paymentPageUrl, 'exact backend hosted URL used independently of embedded token');
        $record = Storage::attempt(Bridge::key($order));
        $this->ok(!str_contains($record['token'], 'native_checkout'), 'checkout token encrypted');
        $this->ok($bridge->start($order, 'https://merchant.example/return', '203.0.113.10')->token === $session->token, 'persisted token reused');
        $this->ok($bridge->start($order, 'https://merchant.example/return', '198.51.100.7')->token === $session->token, 'another buyer IP on retry keeps the reserved attempt');
        $this->ok(Storage::attempt(Bridge::key($order))['snapshot'] === ['merchantId' => 'native_merchant', 'orderId' => Bridge::key($order), 'amount' => '10050', 'currency' => (new Currency((int) $order->id_currency))->iso_code], 'snapshot identity excludes the order details');
        $this->ok(!str_contains($record['paymentPageUrl'], 'server-issued-page'), 'backend payment-page URL encrypted');
        $calls = count($transport->calls);
        $this->ok($bridge->start($order, 'https://merchant.example/return', '203.0.113.10')->hostedUrl() === $session->hostedUrl(), 'persisted backend URL reused exactly');
        $this->ok(count($transport->calls) === $calls, 'cached URL resume does not create another intent');
        if (getenv('LUNIXI_NATIVE_FORM_ONLY') === '1') {
            $missingOrder = $module->pendingOrder($this->cart($context));
            $missingTransport = new NativeGateway(); $missingTransport->paymentPageUrl = null;
            $missingSdk = new LunixiClient(new SdkConfiguration(['baseUrl' => Settings::GATEWAY, 'keyId' => 'mk_test_sk_synthetic_native',
                'privateKey' => base64_encode(sodium_crypto_sign_secretkey($seed)), 'maxRetries' => 0]), $missingTransport);
            $missingBridge = new Bridge(1, $missingSdk);
            $missing = $missingBridge->start($missingOrder, 'https://merchant.example/return', '203.0.113.10');
            $this->rejects(fn() => $missing->hostedUrl(), 'missing URL cannot be reconstructed from browser token');
            $missingBefore = Storage::attempt(Bridge::key($missingOrder));
            $this->rejects(fn() => Settings::save(1, 'native_merchant', 'mk_test_sk_synthetic_native', '', '', 'invalid-mode', false), 'invalid presentation setting rejected');
            Settings::save(1, 'native_merchant', 'mk_test_sk_synthetic_native', '', '', 'embedded', false);
            $this->rejects(fn() => $missingBridge->start($missingOrder, 'https://merchant.example/return', '203.0.113.10'), 'mode change cannot replace frozen attempt');
            $missingAfter = Storage::attempt(Bridge::key($missingOrder));
            $this->ok($missingBefore['key'] === $missingAfter['key'] && $missingBefore['requestHash'] === $missingAfter['requestHash'], 'mode change preserves exact historical key and hash');
            echo 'Native PrestaShop form contract: ' . $this->assertions . " assertions passed.\n";
            return;
        }
        $this->ok(count($module->hookPaymentOptions([])) === 1, 'native PaymentOption rendered');
        $this->ok(!$bridge->reconcile($order)->isPaid(), 'client/browser callback cannot supply success');
        $transport->status = 'CAPTURED'; $transport->captured = '10050';
        $event = $this->event($bridge, $session->paymentId, 'native-capture-' . $order->id);
        Storage::pdo()->prepare('UPDATE ps_orders SET total_paid_tax_incl = ? WHERE id_order = ?')->execute(['999.00', $order->id]);
        $this->rejects(fn() => $bridge->reconcile($order, $event), 'edited native order cannot be marked paid by historical intent');
        Storage::pdo()->prepare('UPDATE ps_orders SET total_paid_tax_incl = ? WHERE id_order = ?')->execute(['100.50', $order->id]);
        $beforeEvents = (int) Storage::pdo()->query('SELECT COUNT(*) FROM ps_lunixi_events')->fetchColumn();
        Storage::pdo()->exec("CREATE TRIGGER lunixi_test_rollback BEFORE INSERT ON ps_order_history FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='synthetic rollback'");
        $this->rejects(fn() => $bridge->reconcile($order, $event), 'native order failure rolls back transaction');
        Storage::pdo()->exec('DROP TRIGGER lunixi_test_rollback');
        $this->ok((int) Storage::pdo()->query('SELECT COUNT(*) FROM ps_lunixi_events')->fetchColumn() === $beforeEvents, 'event claim rolled back');
        $this->ok((int) Storage::pdo()->query('SELECT current_state FROM ps_orders WHERE id_order=' . (int) $order->id)->fetchColumn() === (int) Configuration::get('LUNIXI_OS_PENDING'), 'native state rolled back');
        $order->clearCache();
        $this->ok((int) Storage::pdo()->query('SELECT COUNT(*) FROM ps_order_payment WHERE order_reference=' . Storage::pdo()->quote($order->reference))->fetchColumn() === 0, 'native payment rolled back');
        $this->ok($bridge->reconcile($order, $event)->isPaid(), 'signed webhook plus authenticated GET marks paid');
        $this->ok($bridge->reconcile($order, $event)->isPaid(), 'duplicate event harmless');
        $this->ok((int) (new Order($order->id))->current_state === (int) Configuration::get('PS_OS_PAYMENT'), 'native paid state');
        $this->ok((int) Storage::pdo()->query('SELECT COUNT(*) FROM ps_order_payment WHERE order_reference=' . Storage::pdo()->quote($order->reference))->fetchColumn() === 1, 'one native payment after replay');
        $this->ok((int) Storage::pdo()->query('SELECT COUNT(*) FROM ps_lunixi_mail WHERE sent_at IS NULL')->fetchColumn() > 0, 'notification deferred in durable outbox');
        $operation = bin2hex(random_bytes(24)); $transport->lag = true;
        $bridge->mutate($order, $operation, 'refund', '1000');
        $this->ok($bridge->operationStatus($order, $operation)['state'] !== 'confirmed', 'async refund remains unconfirmed');
        $this->rejects(fn() => $bridge->mutate($order, bin2hex(random_bytes(24)), 'refund', '1000'), 'new refund blocked while uncertain');
        $this->rejects(fn() => $bridge->mutate($order, $operation, 'refund', '2000'), 'changed amount under same native id rejected');
        $transport->lag = false;
        $decision = $bridge->mutate($order, $operation, 'refund', '1000');
        $this->ok($decision->refundedAmount === '1000', 'real gateway mutation reconciled');
        $this->ok($bridge->operationStatus($order, $operation)['state'] === 'confirmed', 'settlement delta confirms refund');
        $this->ok((int) (new Order($order->id))->current_state === (int) Configuration::get('LUNIXI_OS_PARTIAL_REFUND'), 'native partial refund state');
        $this->ok(Storage::unresolved((int) $order->id, Storage::attempt(Bridge::key($order))) === null, 'no unresolved operation after confirmed refund');
        $this->reviews($module, $context, $seed);
        $this->orderDetails($module, $context, $seed);
        // Actual native preauthorization -> partial capture -> full capture.
        Settings::save(1, 'native_merchant', 'mk_test_sk_synthetic_native', '', '', 'embedded', true);
        $authorizedOrder = $module->pendingOrder($this->cart($context));
        $authorizedTransport = new NativeGateway();
        $authorizedSdk = new LunixiClient(new SdkConfiguration(['baseUrl' => Settings::GATEWAY, 'keyId' => 'mk_test_sk_synthetic_native',
            'privateKey' => base64_encode(sodium_crypto_sign_secretkey($seed)), 'maxRetries' => 0]), $authorizedTransport);
        $authorizedBridge = new Bridge(1, $authorizedSdk);
        $embedded = $authorizedBridge->start($authorizedOrder, 'https://merchant.example/return', '203.0.113.10');
        $this->ok($authorizedTransport->intent['preauth'] === true, 'native preauth configuration sent through shared kernel');
        $this->ok(!str_contains($embedded->browserConfig(), 'privateKey'), 'embedded browser config contains no merchant secret');
        $authorizedTransport->status = 'AUTHORIZED';
        $this->ok($authorizedBridge->reconcile($authorizedOrder)->state === 'authorized', 'authorization stays on hold');
        $capture1 = bin2hex(random_bytes(24));
        $partial = $authorizedBridge->mutate($authorizedOrder, $capture1, 'capture', '4000');
        $this->ok($partial->state === 'partially_captured' && !$partial->isPaid(), 'partial capture is not fulfillment');
        $this->ok($authorizedBridge->operationStatus($authorizedOrder, $capture1)['state'] === 'confirmed', 'partial capture operation confirmed from delta');
        $this->ok((int) Storage::pdo()->query('SELECT current_state FROM ps_orders WHERE id_order=' . (int) $authorizedOrder->id)->fetchColumn() === (int) Configuration::get('LUNIXI_OS_AUTHORIZED'), 'native partial capture stays on hold');
        $this->ok($authorizedBridge->mutate($authorizedOrder, bin2hex(random_bytes(24)), 'capture', '6050')->isPaid(), 'remaining native capture completes settlement');
        $this->ok((int) Storage::pdo()->query('SELECT COUNT(*) FROM ps_order_payment WHERE order_reference=' . Storage::pdo()->quote($authorizedOrder->reference))->fetchColumn() === 1, 'native captures produce one basket payment');
        // Actual native authorization release; never a browser-paid transition.
        $voidOrder = $module->pendingOrder($this->cart($context));
        $voidTransport = new NativeGateway();
        $voidSdk = new LunixiClient(new SdkConfiguration(['baseUrl' => Settings::GATEWAY, 'keyId' => 'mk_test_sk_synthetic_native',
            'privateKey' => base64_encode(sodium_crypto_sign_secretkey($seed)), 'maxRetries' => 0]), $voidTransport);
        $voidBridge = new Bridge(1, $voidSdk);
        $voidBridge->start($voidOrder, 'https://merchant.example/return', '203.0.113.10');
        $voidTransport->status = 'AUTHORIZED'; $voidBridge->reconcile($voidOrder);
        $voidId = bin2hex(random_bytes(24));
        $this->ok($voidBridge->mutate($voidOrder, $voidId, 'void', null)->state === 'failed', 'native void releases authorization');
        $this->ok($voidBridge->operationStatus($voidOrder, $voidId)['state'] === 'confirmed', 'native void confirmed from released balance');
        $this->ok((int) Storage::pdo()->query('SELECT COUNT(*) FROM ps_order_payment WHERE order_reference=' . Storage::pdo()->quote($voidOrder->reference))->fetchColumn() === 0, 'void creates no paid record');
        // This suite booted the BO kernel in English before installing the module.
        // Ask the actual native FO loader to initialize Turkish, as a Turkish request does;
        // an English BO translator does not discover another locale's new module resources.
        $turkish = $context->getTranslatorFromLocale('tr-TR');
        $this->ok($turkish->trans('Secure payment', [], 'Modules.Lunixipay.Shop') === 'Güvenli ödeme', 'native Turkish Shop catalog loaded');
        $this->ok($turkish->trans('Capture', [], 'Modules.Lunixipay.Admin') === 'Tahsil et', 'native Turkish Admin catalog loaded');
        // Only synthetic identities are passed to the separate real-HTTP acceptance process.
        $fixturePath = sys_get_temp_dir() . '/lunixi-prestashop-http-fixture.json';
        if (file_put_contents($fixturePath, json_encode(['orderId' => (int) $order->id, 'otherEmail' => $context->customer->email], JSON_THROW_ON_ERROR), LOCK_EX) === false) { throw new RuntimeException('Could not write HTTP acceptance fixture.'); }
        chmod($fixturePath, 0600);
        $this->ok($module->uninstall(), 'native uninstall succeeds');
        $this->ok(Storage::attempt(Bridge::key($order)) !== null, 'uninstall preserves financial history');
        echo 'PrestaShop ' . _PS_VERSION_ . ': ' . $this->assertions . " native assertions passed; synthetic gateway, no funds moved.\n";
    }
    private function employeeAccess(Context $context, Order $order): void
    {
        global $kernel;
        $savedEmployee = $context->employee;
        $savedCookie = $context->cookie;
        $container = $kernel->getContainer();
        $requestStack = version_compare(_PS_VERSION_, '9.0.0', '>=') ? $container->get('request_stack') : null;
        $securitySession = null;
        if ($requestStack) {
            $securitySession = new \Symfony\Component\HttpFoundation\Session\Session(new \Symfony\Component\HttpFoundation\Session\Storage\NativeSessionStorage(['name' => 'LUNIXI_NATIVE_ACL', 'use_cookies' => false, 'cache_limiter' => '']));
            $securitySession->start();
            $request = \Symfony\Component\HttpFoundation\Request::create('http://localhost/admin/native-acl');
            $request->setSession($securitySession);
            $request->cookies->set($securitySession->getName(), $securitySession->getId());
            $requestStack->push($request);
        }
        $employees = [];
        $sessions = [];
        $profile = new Profile();
        foreach (Language::getLanguages(false) as $language) { $profile->name[(int) $language['id_lang']] = 'Lunixi native read only'; }
        if (!$profile->add()) { throw new RuntimeException('Employee profile fixture failed.'); }
        $foreignShop = new Shop();
        $foreignShop->name = 'Lunixi native ACL shop'; $foreignShop->id_shop_group = 1;
        $foreignShop->id_category = 2; $foreignShop->theme_name = $context->shop->theme_name;
        $foreignShop->active = true;
        if (!$foreignShop->add()) { throw new RuntimeException('Employee foreign-shop fixture failed.'); }
        try {
            $authenticate = function (Employee $employee) use ($context, $securitySession, $container, &$sessions): void {
                $context->employee = $employee;
                Cache::clean('isLoggedBack' . $employee->id);
                if ($securitySession) {
                    $entity = $container->get('doctrine')->getManager()->find(\PrestaShopBundle\Entity\Employee\Employee::class, (int) $employee->id);
                    if (!$entity) { throw new RuntimeException('Native employee security entity missing.'); }
                    $securitySession->set('_security_main', serialize(new \Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken($entity, 'main', $entity->getRoles())));
                } else {
                    $context->cookie = new Cookie('lunixi_native_admin_' . $employee->id);
                    $context->cookie->id_employee = (int) $employee->id;
                    $context->cookie->passwd = $employee->passwd;
                    $session = new EmployeeSession();
                    $context->cookie->registerSession($session); $sessions[] = $session;
                }
                $this->ok((bool) $employee->isLoggedBack(), 'native employee session authenticated');
            };
            foreach ([[1, 1], [1, (int) $foreignShop->id], [(int) $profile->id, 1]] as [$profileId, $shopId]) {
                $employee = new Employee(); $employee->firstname = 'Synthetic'; $employee->lastname = 'ACL';
                $employee->email = 'native-acl-' . bin2hex(random_bytes(8)) . '@example.test';
                $employee->id_lang = (int) $context->language->id; $employee->id_profile = $profileId;
                $employee->passwd = password_hash('test-only-admin-password', PASSWORD_BCRYPT);
                $employee->active = true; $employee->id_shop_list = [$shopId];
                if (!$employee->add()) { throw new RuntimeException('Native employee fixture failed.'); }
                $employees[] = $employee;
            }
            $allowed = new Employee((int) $employees[0]->id);
            $authenticate($allowed);
            $this->ok($allowed->can('edit', 'AdminOrders') && in_array(1, $allowed->getAssociatedShops(), true), 'native editable employee has integer shop association');
            Access::employee($context, $order);
            $this->ok(true, 'native authenticated employee can administer own-shop order');
            $foreign = new Employee((int) $employees[1]->id);
            $authenticate($foreign);
            $this->ok($foreign->can('edit', 'AdminOrders') && !in_array((int) $order->id_shop, $foreign->getAssociatedShops(), true), 'native editable employee has only foreign-shop association');
            $this->rejects(fn() => Access::employee($context, $order), 'native foreign-shop employee denied before financial IO');
            $readOnly = new Employee((int) $employees[2]->id);
            $authenticate($readOnly);
            $this->ok(!$readOnly->can('edit', 'AdminOrders') && in_array(1, $readOnly->getAssociatedShops(), true), 'native same-shop profile lacks order edit permission');
            $this->rejects(fn() => Access::employee($context, $order), 'native read-only employee denied before financial IO');
            if ($securitySession) { $securitySession->remove('_security_main'); }
            $context->employee = $allowed;
            if (!$securitySession) { $context->cookie = new Cookie('lunixi_native_logged_out'); }
            Cache::clean('isLoggedBack' . $allowed->id);
            $this->rejects(fn() => Access::employee($context, $order), 'native previously allowed employee denied without authentication before financial IO');
            $this->ok(!$allowed->isLoggedBack(), 'native employee has no authenticated session after logout');
        } finally {
            if ($requestStack) { $requestStack->pop(); $securitySession->invalidate(); }
            $context->employee = $savedEmployee; $context->cookie = $savedCookie;
            foreach ($sessions as $session) { $session->delete(); }
            foreach ($employees as $employee) { $employee->delete(); }
            $profile->delete(); $foreignShop->delete();
        }
        $this->ok((new ReflectionMethod(Employee::class, 'getAssociatedShops'))->getDeclaringClass()->getName() === 'ObjectModelCore', 'shared native ObjectModel shop-association API used');
        echo 'Native employee ACL: ' . _PS_VERSION_ . ' Employee=' . (new ReflectionClass(Employee::class))->getParentClass()->getFileName() . '; association=' . (new ReflectionMethod(Employee::class, 'getAssociatedShops'))->getFileName() . "\n";
    }
    /** Late bank transfer (linked payment) and money for a cancelled order: visible review, never an order payment. */
    private function reviews(Lunixipay $module, Context $context, string $seed): void
    {
        $messages = fn(Order $order): int => (int) Storage::pdo()->query('SELECT COUNT(*) FROM ps_customer_message m JOIN ps_customer_thread t ON t.id_customer_thread = m.id_customer_thread WHERE m.private = 1 AND m.message LIKE ' . Storage::pdo()->quote('%lunixi-review-%') . ' AND t.id_order = ' . (int) $order->id)->fetchColumn();
        $payments = fn(Order $order): int => (int) Storage::pdo()->query('SELECT COUNT(*) FROM ps_order_payment WHERE order_reference=' . Storage::pdo()->quote($order->reference))->fetchColumn();
        $state = fn(Order $order): int => (int) Storage::pdo()->query('SELECT current_state FROM ps_orders WHERE id_order=' . (int) $order->id)->fetchColumn();
        $bridge = function (NativeGateway $transport) use ($seed): Bridge {
            return new Bridge(1, new LunixiClient(new SdkConfiguration(['baseUrl' => Settings::GATEWAY, 'keyId' => 'mk_test_sk_synthetic_native',
                'privateKey' => base64_encode(sodium_crypto_sign_secretkey($seed)), 'maxRetries' => 0]), $transport));
        };
        $lateOrder = $module->pendingOrder($this->cart($context));
        $lateTransport = new NativeGateway();
        $lateBridge = $bridge($lateTransport);
        $lateBridge->start($lateOrder, 'https://merchant.example/return', '203.0.113.10');
        $lateTransport->status = 'EXPIRED';
        $this->ok($lateBridge->reconcile($lateOrder)->state === 'failed', 'expired bank transfer attempt fails');
        $this->ok($state($lateOrder) === (int) Configuration::get('PS_OS_ERROR'), 'failed attempt uses the native error state');
        $latePayment = 'native_late_' . $lateOrder->id;
        $lateTransport->linked[$latePayment] = '10050';
        $event = $this->event($lateBridge, $latePayment, 'native-late-' . $lateOrder->id, Bridge::key($lateOrder));
        $decision = $lateBridge->reconcile($lateOrder, $event);
        $this->ok($decision->isLinkedPayment() && !$decision->isPaid() && $decision->paymentId === $latePayment, 'late linked payment becomes a review decision');
        $lateBridge->reconcile($lateOrder, $event);
        $lateBridge->reconcile($lateOrder, $this->event($lateBridge, $latePayment, 'native-late-again-' . $lateOrder->id, Bridge::key($lateOrder)));
        $lateOrder->clearCache();
        $this->ok($state($lateOrder) === (int) Configuration::get('LUNIXI_OS_REVIEW'), 'late linked payment holds the order in review, not paid');
        $this->ok($payments($lateOrder) === 0, 'late linked payment records no order payment');
        $this->ok($messages($lateOrder) === 1, 'one private review message across repeated deliveries');
        $this->ok(Storage::attempt(Bridge::key($lateOrder))['paymentId'] !== $latePayment, 'ledger keeps the bound attempt');
        $this->ok(Bridge::reviewReason(new Order((int) $lateOrder->id), Storage::attempt(Bridge::key($lateOrder))) === 'linked_payment', 'admin order panel flags the linked payment');

        $closedOrder = $module->pendingOrder($this->cart($context));
        $closedTransport = new NativeGateway();
        $closedBridge = $bridge($closedTransport);
        $closedSession = $closedBridge->start($closedOrder, 'https://merchant.example/return', '203.0.113.10');
        $closedOrder->setCurrentState((int) Configuration::get('PS_OS_CANCELED'));
        $closedTransport->status = 'CAPTURED'; $closedTransport->captured = '10050';
        $closedEvent = $this->event($closedBridge, $closedSession->paymentId, 'native-closed-' . $closedOrder->id);
        $this->ok($closedBridge->reconcile($closedOrder, $closedEvent)->isPaid(), 'money for a cancelled order is acknowledged without an error');
        $closedBridge->reconcile($closedOrder, $closedEvent);
        $closedBridge->reconcile($closedOrder);
        $closedOrder->clearCache();
        $this->ok($state($closedOrder) === (int) Configuration::get('PS_OS_CANCELED'), 'cancelled order is never moved to paid');
        $this->ok($payments($closedOrder) === 0, 'cancelled order records no payment');
        $this->ok($messages($closedOrder) === 1, 'cancelled order gets one private review message');
        $this->ok(Bridge::reviewReason(new Order((int) $closedOrder->id), Storage::attempt(Bridge::key($closedOrder))) === 'order_closed', 'admin order panel flags the cancelled order');
    }
    /** Order details from the native order: buyer, addresses, exact basket; the ID number lives on the invoice address. */
    private function orderDetails(Lunixipay $module, Context $context, string $seed): void
    {
        $this->ok(IdentityNumber::normalize('11111111110') === '11111111110', 'Turkish ID number with valid check digits accepted');
        $this->ok(IdentityNumber::normalize('11111111111') === null && IdentityNumber::normalize('01111111110') === null, 'Turkish ID number with wrong check digits rejected');
        $this->ok(IdentityNumber::normalize(' u 1234567 ') === 'U1234567', 'passport number normalized');
        $this->ok(IdentityNumber::normalize('A12') === null && IdentityNumber::normalize('A1B2-C3D4') === null, 'short or punctuated passport number rejected');
        $this->ok(Basket::reduce(['6000', '4000'], '10000', '1000') === ['5400', '3600'], 'discount spread over lines in proportion');
        $this->ok(Basket::reduce(['1', '1', '1'], '3', '2') === ['1', '0', '0'], 'remaining minor units go to the last lines');
        $line = fn(string $id, string $total, bool $physical = true): array => ['id' => $id, 'name' => 'Synthetic line', 'category' => 'General', 'physical' => $physical, 'total' => $total];
        $free = Basket::items([$line('1', '10050')], '1000', '0', '1000', true, '10050');
        $this->ok(array_column($free, 'price') === ['10050'], 'free-shipping rule omits the shipping item');
        $wrapped = Basket::items([$line('1', '10050')], '1000', '250', '0', false, '11300');
        $this->ok(array_column($wrapped, 'id') === ['1', Basket::SHIPPING_ID, Basket::WRAPPING_ID] && array_column($wrapped, 'itemType') === ['PHYSICAL', 'VIRTUAL', 'VIRTUAL'], 'shipping and gift wrapping are VIRTUAL items');
        $this->ok(array_column(Basket::items([$line('1', '3333'), $line('2', '3333')], '0', '0', '0', false, '6667'), 'price') === ['3333', '3334'], 'rounding remainder goes to the last line');
        foreach ([[[$line('1', '10050')], '0', '0', '0', false, '15000'], [[$line('1', '10050')], '0', '0', '0', false, '9000'], [[], '0', '0', '0', false, '100']] as $case) {
            try {
                Basket::items(...$case);
                $this->ok(false, 'unexplained basket difference accepted');
            } catch (OrderDetailsException $error) {
                $this->ok($error->fields() === ['basketItems'], 'unexplained basket difference stops the payment');
            }
        }

        $transport = new NativeGateway();
        $bridge = new Bridge(1, new LunixiClient(new SdkConfiguration(['baseUrl' => Settings::GATEWAY, 'keyId' => 'mk_test_sk_synthetic_native',
            'privateKey' => base64_encode(sodium_crypto_sign_secretkey($seed)), 'maxRetries' => 0]), $transport));
        // Physical product, shipping and a discount on a native order.
        $shipped = $module->pendingOrder($this->cart($context));
        Storage::pdo()->prepare('UPDATE ps_product p JOIN ps_order_detail od ON od.product_id = p.id_product SET p.is_virtual = 0 WHERE od.id_order = ?')->execute([(int) $shipped->id]);
        Storage::pdo()->prepare('UPDATE ps_orders SET total_shipping_tax_incl = ?, total_discounts_tax_incl = ?, total_paid_tax_incl = ? WHERE id_order = ?')
            ->execute(['10.00', '5.00', '105.50', (int) $shipped->id]);
        $bridge->start($shipped, 'https://merchant.example/return', '203.0.113.10');
        $intent = $transport->intent;
        $this->ok($intent['amount'] === '10550' && array_column($intent['basketItems'], 'price') === ['9550', '1000'], 'discount lowers the product line, shipping is its own item, sum equals amount');
        $this->ok(array_column($intent['basketItems'], 'itemType') === ['PHYSICAL', 'VIRTUAL'] && $intent['basketItems'][0]['category1'] !== '', 'physical line and categorized item');
        $this->ok(($intent['shippingAddress']['city'] ?? null) === 'Istanbul' && ($intent['shippingAddress']['zipCode'] ?? null) === '34000', 'physical order sends its delivery address');
        $this->ok(($intent['buyer']['gsmNumber'] ?? null) === '+905550000000' && str_ends_with((string) ($intent['buyer']['email'] ?? ''), '@example.test'), 'buyer phone and email from the native customer');

        $calls = count($transport->calls);
        $unbalanced = $module->pendingOrder($this->cart($context));
        Storage::pdo()->prepare('UPDATE ps_orders SET total_paid_tax_incl = ? WHERE id_order = ?')->execute(['150.00', (int) $unbalanced->id]);
        $noIp = $module->pendingOrder($this->cart($context));
        foreach ([[$unbalanced, '203.0.113.10', ['basketItems']], [$noIp, 'not-an-ip', ['buyer.ip']]] as [$failing, $ip, $fields]) {
            try {
                $bridge->start($failing, 'https://merchant.example/return', $ip);
                $this->ok(false, 'incomplete order details started a payment');
            } catch (OrderDetailsException $error) {
                $this->ok($error->fields() === $fields, 'incomplete order details name the failing path');
            }
            $this->ok(Storage::attempt(Bridge::key($failing)) === null, 'nothing reserved for incomplete order details');
        }
        $this->ok(count($transport->calls) === $calls, 'nothing sent for incomplete order details');

        // An invoice address without an ID number: the payment form asks for it and saves it on the address.
        $cart = $this->cart($context, '');
        $this->ok(IdentityNumber::requiredForCart($cart) && str_contains((string) $module->hookPaymentOptions([])[0]->getForm(), IdentityNumber::FIELD), 'native payment form renders the ID number field');
        try {
            IdentityNumber::ensureOnCart($cart, '11111111111');
            $this->ok(false, 'invalid ID number accepted');
        } catch (OrderDetailsException $error) {
            $this->ok($error->fields() === ['buyer.identityNumber'], 'invalid ID number refused before any order');
        }
        IdentityNumber::ensureOnCart($cart, '11111111110');
        $this->ok((new Address((int) $cart->id_address_invoice))->dni === '11111111110' && !IdentityNumber::requiredForCart($cart), 'unused invoice address stores the ID number');
        $this->rejects(fn() => OrderDetails::assertCart($this->cart($context, '11111111110', ''), '203.0.113.10'), 'missing phone stops checkout before the native order');
        // An address an earlier order references is copied, never rewritten.
        $legacy = $module->pendingOrder($this->cart($context, ''));
        try {
            $bridge->start($legacy, 'https://merchant.example/return', '203.0.113.10');
            $this->ok(false, 'order without ID number started a payment');
        } catch (OrderDetailsException $error) {
            $this->ok($error->fields() === ['buyer.identityNumber'], 'order without ID number is refused');
        }
        $repeat = new Cart(); $repeat->id_customer = (int) $legacy->id_customer; $repeat->id_currency = (int) $legacy->id_currency;
        $repeat->id_lang = (int) $legacy->id_lang; $repeat->id_shop = 1; $repeat->id_shop_group = 1; $repeat->secure_key = (string) $legacy->secure_key;
        $repeat->id_address_delivery = (int) $legacy->id_address_delivery; $repeat->id_address_invoice = (int) $legacy->id_address_invoice;
        if (!$repeat->add()) { throw new RuntimeException('Repeat cart fixture failed.'); }
        IdentityNumber::ensureOnCart($repeat, '11111111110');
        $this->ok((int) $repeat->id_address_invoice !== (int) $legacy->id_address_invoice && (new Address((int) $repeat->id_address_invoice))->dni === '11111111110', 'used invoice address copied with the ID number');
        $this->ok((string) (new Address((int) $legacy->id_address_invoice))->dni === '' && (int) $repeat->id_address_delivery === (int) $legacy->id_address_delivery, 'earlier order and delivery address unchanged');
    }
    private function event(Bridge $bridge, string $paymentId, string $id, ?string $orderId = null)
    {
        $data = ['intentId' => $paymentId, 'capturedAmountMinor' => '10050'] + ($orderId === null ? [] : ['orderId' => $orderId]);
        $raw = json_encode(['id' => $id, 'type' => 'payment.captured', 'data' => $data], JSON_THROW_ON_ERROR);
        $timestamp = gmdate('Y-m-d\TH:i:s\Z');
        $signature = hash_hmac('sha256', $id . '.' . $timestamp . '.' . $raw, 'native_webhook_secret');
        return $bridge->webhook($raw, ['x-lunixi-event-id' => $id, 'x-lunixi-event-type' => 'payment.captured',
            'x-lunixi-signature-timestamp' => $timestamp, 'x-lunixi-signature' => 'v2=' . $signature], 1);
    }
    private function cart(Context $context, string $dni = '11111111110', string $phone = '+905550000000'): Cart
    {
        $customer = new Customer(); $customer->firstname = 'Synthetic'; $customer->lastname = 'Acceptance';
        $customer->email = 'native-' . bin2hex(random_bytes(8)) . '@example.test'; $customer->passwd = password_hash('test-only-customer-password', PASSWORD_BCRYPT); $customer->active = true;
        if (!$customer->add()) { throw new RuntimeException('Customer fixture failed.'); }
        $context->updateCustomer($customer); $context->cookie->id_customer = (int) $customer->id;
        $context->cookie->passwd = $customer->passwd; $context->cookie->logged = 1;
        $address = new Address(); $address->id_customer = (int) $customer->id; $address->id_country = (int) Configuration::get('PS_COUNTRY_DEFAULT');
        $address->firstname = 'Synthetic'; $address->lastname = 'Acceptance'; $address->alias = 'test'; $address->address1 = 'Synthetic test address'; $address->city = 'Istanbul'; $address->postcode = '34000';
        $address->phone_mobile = $phone; $address->dni = $dni;
        if (!$address->add()) { throw new RuntimeException('Address fixture failed.'); }
        $product = new Product(); $product->name = [(int) $context->language->id => 'Synthetic payment fixture'];
        $product->link_rewrite = [(int) $context->language->id => 'synthetic-payment-fixture']; $product->price = '100.50';
        $product->id_category_default = 2; $product->active = 1; $product->is_virtual = 1; $product->id_tax_rules_group = 0;
        if (!$product->add()) { throw new RuntimeException('Product fixture failed.'); }
        StockAvailable::setQuantity((int) $product->id, 0, 20);
        $cart = new Cart(); $cart->id_customer = (int) $customer->id; $cart->id_currency = (int) $context->currency->id;
        $cart->id_lang = (int) $context->language->id; $cart->id_shop = 1; $cart->id_shop_group = 1;
        $cart->id_address_delivery = (int) $address->id; $cart->id_address_invoice = (int) $address->id; $cart->secure_key = $customer->secure_key;
        if (!$cart->add() || !$cart->updateQty(1, (int) $product->id)) { throw new RuntimeException('Cart fixture failed.'); }
        $context->cart = $cart;
        return $cart;
    }
}
(new NativeAcceptance())->run($module);
