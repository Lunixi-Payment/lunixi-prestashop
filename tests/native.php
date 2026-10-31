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

use Lunixi\PrestaShop\{Access, Bridge, Settings, Storage};
use Lunixi\PrestaShop\Vendor\Lunixi\Sdk\{Configuration as SdkConfiguration, LunixiClient};
use Lunixi\PrestaShop\Vendor\Lunixi\Sdk\Http\{HttpClientInterface, HttpResponse};
use Lunixi\PrestaShop\Vendor\Lunixi\Sdk\Commerce\Money;

final class NativeGateway implements HttpClientInterface
{
    public array $calls = [];
    public array $intent = [];
    public int $captured = 0;
    public int $refunded = 0;
    public string $status = 'INITIATED';
    public bool $lag = false;
    public bool $failIntent = false;
    public array $keys = [];
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
            return $this->json(['data' => ['paymentId' => 'native_intent_' . $data['orderId'], 'token' => 'native_checkout_synthetic_TOKEN', 'status' => $this->status]]);
        }
        if ($method === 'POST') {
            $key = $headers['Idempotency-Key'] ?? '';
            if (!isset($this->keys[$key]) && !$this->lag) {
                if (str_ends_with($path, '/refund')) { $this->refunded += $data['amount']; $this->status = $this->refunded === $this->captured ? 'REFUNDED' : 'PARTIALLY_REFUNDED'; }
                elseif (str_ends_with($path, '/capture')) { $this->captured += $data['amount']; $this->status = $this->captured === $this->intent['amount'] ? 'CAPTURED' : 'PARTIALLY_CAPTURED'; }
                elseif (str_ends_with($path, '/void')) { $this->status = 'VOIDED'; }
                $this->keys[$key] = true;
            }
            return $this->json(['data' => ['status' => $this->status]]);
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
        Settings::save(1, 'native_merchant', 'mk_test_sk_synthetic_native', base64_encode(sodium_crypto_sign_secretkey($seed)), 'native_webhook_secret', 'hosted', false);
        $cipher = (string) Configuration::get('LUNIXI_CREDENTIALS');
        $this->ok(!str_contains($cipher, 'native_webhook_secret'), 'admin credentials encrypted');
        $this->ok(Settings::secrets(1)['merchantId'] === 'native_merchant', 'credentials decrypt');
        $this->ok(Storage::pdo() === Db::getInstance()->getLink(), 'same native PDO connection');
        $cart = $this->cart($context);
        $order = $module->pendingOrder($cart);
        $this->ok($order->module === 'lunixipay', 'native PaymentModule order created');
        $this->ok((int) $order->current_state === (int) Configuration::get('LUNIXI_OS_PENDING'), 'order starts unpaid');
        $this->ok((int) $module->pendingOrder($cart)->id === (int) $order->id, 'cart retry creates one order');
        $this->ok(Bridge::total($order) === 10050, 'native decimal converts exactly');
        Storage::pdo()->prepare('UPDATE ps_orders SET total_paid_tax_incl = ? WHERE id_order = ?')->execute(['100.505000', $order->id]);
        $this->ok(Bridge::total($order) === 10051, 'native exact decimal half-up rounding before minor conversion');
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
        $transport = new NativeGateway();
        $sdk = new LunixiClient(new SdkConfiguration(['baseUrl' => Settings::GATEWAY, 'keyId' => 'mk_test_sk_synthetic_native',
            'privateKey' => base64_encode(sodium_crypto_sign_secretkey($seed)), 'maxRetries' => 0]), $transport);
        $bridge = new Bridge(1, $sdk);
        $transport->failIntent = true;
        $this->rejects(fn() => $bridge->start($order, 'https://merchant.example/return'), 'uncertain creation does not mark paid');
        $reserved = Storage::attempt(Bridge::key($order));
        $this->ok($reserved['state'] === 'reserved' && $reserved['key'] !== '', 'attempt reservation committed before network');
        $transport->failIntent = false;
        $session = $bridge->start($order, 'https://merchant.example/return');
        $this->ok(count(array_unique(array_filter($transport->keys, 'is_string'))) === 1, 'timeout uses same key');
        $this->ok(str_starts_with($session->hostedUrl(), 'https://pay.lunixi.com/payment/'), 'fixed hosted origin');
        $record = Storage::attempt(Bridge::key($order));
        $this->ok(!str_contains($record['token'], 'native_checkout'), 'checkout token encrypted');
        $this->ok($bridge->start($order, 'https://merchant.example/return')->token === $session->token, 'persisted token reused');
        $this->ok(count($module->hookPaymentOptions([])) === 1, 'native PaymentOption rendered');
        $this->ok(!$bridge->reconcile($order)->isPaid(), 'client/browser callback cannot supply success');
        $transport->status = 'CAPTURED'; $transport->captured = 10050;
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
        $bridge->mutate($order, $operation, 'refund', 1000);
        $this->ok($bridge->operationStatus($order, $operation)['state'] !== 'confirmed', 'async refund remains unconfirmed');
        $this->rejects(fn() => $bridge->mutate($order, bin2hex(random_bytes(24)), 'refund', 1000), 'new refund blocked while uncertain');
        $this->rejects(fn() => $bridge->mutate($order, $operation, 'refund', 2000), 'changed amount under same native id rejected');
        $transport->lag = false;
        $decision = $bridge->mutate($order, $operation, 'refund', 1000);
        $this->ok($decision->refundedAmount === 1000, 'real gateway mutation reconciled');
        $this->ok($bridge->operationStatus($order, $operation)['state'] === 'confirmed', 'settlement delta confirms refund');
        $this->ok((int) (new Order($order->id))->current_state === (int) Configuration::get('LUNIXI_OS_PARTIAL_REFUND'), 'native partial refund state');
        $this->ok(Storage::unresolved((int) $order->id, Storage::attempt(Bridge::key($order))) === null, 'no unresolved operation after confirmed refund');
        // Actual native preauthorization -> partial capture -> full capture.
        Settings::save(1, 'native_merchant', 'mk_test_sk_synthetic_native', '', '', 'embedded', true);
        $authorizedOrder = $module->pendingOrder($this->cart($context));
        $authorizedTransport = new NativeGateway();
        $authorizedSdk = new LunixiClient(new SdkConfiguration(['baseUrl' => Settings::GATEWAY, 'keyId' => 'mk_test_sk_synthetic_native',
            'privateKey' => base64_encode(sodium_crypto_sign_secretkey($seed)), 'maxRetries' => 0]), $authorizedTransport);
        $authorizedBridge = new Bridge(1, $authorizedSdk);
        $embedded = $authorizedBridge->start($authorizedOrder, 'https://merchant.example/return');
        $this->ok($authorizedTransport->intent['preauth'] === true, 'native preauth configuration sent through shared kernel');
        $this->ok(!str_contains($embedded->browserConfig(), 'privateKey'), 'embedded browser config contains no merchant secret');
        $authorizedTransport->status = 'AUTHORIZED';
        $this->ok($authorizedBridge->reconcile($authorizedOrder)->state === 'authorized', 'authorization stays on hold');
        $capture1 = bin2hex(random_bytes(24));
        $partial = $authorizedBridge->mutate($authorizedOrder, $capture1, 'capture', 4000);
        $this->ok($partial->state === 'partially_captured' && !$partial->isPaid(), 'partial capture is not fulfillment');
        $this->ok($authorizedBridge->operationStatus($authorizedOrder, $capture1)['state'] === 'confirmed', 'partial capture operation confirmed from delta');
        $this->ok((int) Storage::pdo()->query('SELECT current_state FROM ps_orders WHERE id_order=' . (int) $authorizedOrder->id)->fetchColumn() === (int) Configuration::get('LUNIXI_OS_AUTHORIZED'), 'native partial capture stays on hold');
        $this->ok($authorizedBridge->mutate($authorizedOrder, bin2hex(random_bytes(24)), 'capture', 6050)->isPaid(), 'remaining native capture completes settlement');
        $this->ok((int) Storage::pdo()->query('SELECT COUNT(*) FROM ps_order_payment WHERE order_reference=' . Storage::pdo()->quote($authorizedOrder->reference))->fetchColumn() === 1, 'native captures produce one basket payment');
        // Actual native authorization release; never a browser-paid transition.
        $voidOrder = $module->pendingOrder($this->cart($context));
        $voidTransport = new NativeGateway();
        $voidSdk = new LunixiClient(new SdkConfiguration(['baseUrl' => Settings::GATEWAY, 'keyId' => 'mk_test_sk_synthetic_native',
            'privateKey' => base64_encode(sodium_crypto_sign_secretkey($seed)), 'maxRetries' => 0]), $voidTransport);
        $voidBridge = new Bridge(1, $voidSdk);
        $voidBridge->start($voidOrder, 'https://merchant.example/return');
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
    private function event(Bridge $bridge, string $paymentId, string $id)
    {
        $raw = json_encode(['id' => $id, 'type' => 'payment.captured', 'data' => ['intentId' => $paymentId, 'capturedAmountMinor' => 10050]], JSON_THROW_ON_ERROR);
        $timestamp = gmdate('Y-m-d\TH:i:s\Z');
        $signature = hash_hmac('sha256', $id . '.' . $timestamp . '.' . $raw, 'native_webhook_secret');
        return $bridge->webhook($raw, ['x-lunixi-event-id' => $id, 'x-lunixi-event-type' => 'payment.captured',
            'x-lunixi-signature-timestamp' => $timestamp, 'x-lunixi-signature' => 'v2=' . $signature], 1);
    }
    private function cart(Context $context): Cart
    {
        $customer = new Customer(); $customer->firstname = 'Synthetic'; $customer->lastname = 'Acceptance';
        $customer->email = 'native-' . bin2hex(random_bytes(8)) . '@example.test'; $customer->passwd = password_hash('test-only-customer-password', PASSWORD_BCRYPT); $customer->active = true;
        if (!$customer->add()) { throw new RuntimeException('Customer fixture failed.'); }
        $context->updateCustomer($customer); $context->cookie->id_customer = (int) $customer->id;
        $context->cookie->passwd = $customer->passwd; $context->cookie->logged = 1;
        $address = new Address(); $address->id_customer = (int) $customer->id; $address->id_country = (int) Configuration::get('PS_COUNTRY_DEFAULT');
        $address->firstname = 'Synthetic'; $address->lastname = 'Acceptance'; $address->alias = 'test'; $address->address1 = 'Synthetic test address'; $address->city = 'Istanbul'; $address->postcode = '34000';
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
