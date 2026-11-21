<?php

declare(strict_types=1);

namespace Lunixi\PrestaShop;

use Lunixi\PrestaShop\Vendor\Lunixi\Sdk\Commerce\{CheckoutService, CheckoutSession, Money, OrderSnapshot, PaymentDecision};
use Lunixi\PrestaShop\Vendor\Lunixi\Sdk\LunixiClient;
use Lunixi\PrestaShop\Vendor\Lunixi\Sdk\Webhook\WebhookEvent;

/** Thin native order adapter; all financial evidence and signing live in the SDK. */
final class Bridge
{
    /** Decisions that hold or carry buyer money. */
    private const MONEY_STATES = ['authorized', 'partially_captured', 'paid', 'partially_refunded', 'refunded'];
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
    public static function total(\Order $order): string
    {
        // Read the native DECIMAL column as text; never multiply a PHP float.
        $q = Storage::pdo()->prepare('SELECT total_paid_tax_incl FROM `' . _DB_PREFIX_ . 'orders` WHERE id_order = ?');
        $q->execute([(int) $order->id]);
        return self::minor((string) $q->fetchColumn(), $order);
    }
    /** A native DECIMAL text of the order currency in minor units, rounded with the order's native rounding mode. */
    public static function minor(string $decimal, \Order $order): string
    {
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
            return Money::minor((string) $rounded, $exponent);
        }
    }
    /**
     * @param string $buyerIp The buyer's browser IP of this request; PrestaShop orders do not store one.
     * @throws OrderDetailsException When the order lacks a required order detail; nothing is reserved or sent.
     */
    public function start(\Order $order, string $callback, string $buyerIp): CheckoutSession
    {
        $currency = new \Currency((int) $order->id_currency);
        $credentials = Settings::secrets((int) $order->id_shop);
        $snapshot = new OrderSnapshot($credentials['merchantId'], self::key($order), self::total($order), (string) $currency->iso_code);
        // Outside the snapshot identity: the fingerprint of existing attempts is unchanged.
        $snapshot = OrderDetails::attach($snapshot, $order, $buyerIp);
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
    public function mutate(\Order $order, string $id, string $action, ?string $amount): PaymentDecision
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
        $cancelled = (int) $order->current_state === (int) \Configuration::get('PS_OS_CANCELED', null, null, (int) $order->id_shop);
        if ($decision->isLinkedPayment()) {
            // Another payment of the same order (e.g. a late bank transfer): never recorded as an order payment.
            $this->review($order, PaymentDecision::REVIEW_LINKED_PAYMENT, $decision, !$cancelled && !(new \OrderState((int) $order->current_state))->paid);
            return;
        }
        if ($cancelled && in_array($decision->state, self::MONEY_STATES, true)) {
            // A cancelled order keeps its state and stock; the merchant decides about the money.
            $this->review($order, PaymentDecision::REVIEW_ORDER_CLOSED, $decision, false);
            return;
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
        if ($decision->state === 'partially_refunded' && Money::compare($decision->capturedAmount, $decision->chargedAmount) < 0) {
            $stateKey = 'LUNIXI_OS_AUTHORIZED';
        }
        $state = (int) \Configuration::get($stateKey, null, null, (int) $order->id_shop);
        if ($state < 1) {
            throw new \RuntimeException('Native order state is not configured.');
        }
        \Lunixipay::$mailTransaction = true;
        try {
            if (in_array($decision->state, ['paid', 'partially_refunded', 'refunded'], true) && Money::compare($decision->capturedAmount, $decision->chargedAmount) >= 0) {
                $q = Storage::pdo()->prepare('SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'order_payment` WHERE order_reference = ? AND transaction_id = ?');
                $q->execute([$order->reference, $decision->paymentId]);
                if ((int) $q->fetchColumn() === 0 && !$order->addOrderPayment(Money::decimal(self::total($order), self::exponent($order)), 'Lunixi', $decision->paymentId, new \Currency((int) $order->id_currency))) {
                    throw new \RuntimeException('Native payment could not be saved.');
                }
            }
            $this->transition($order, $state);
        } finally {
            \Lunixipay::$mailTransaction = false;
        }
    }

    /**
     * Admin order panel flag; the details are in the private order messages.
     * @param array<string,mixed>|null $record
     */
    public static function reviewReason(\Order $order, ?array $record): ?string
    {
        foreach ((array) ($record['linkedPayments'] ?? []) as $linked) {
            if (is_array($linked) && Money::compare((string) ($linked['capturedAmount'] ?? '0'), '0') > 0) {
                return PaymentDecision::REVIEW_LINKED_PAYMENT;
            }
        }
        $cancelled = (int) $order->current_state === (int) \Configuration::get('PS_OS_CANCELED', null, null, (int) $order->id_shop);
        return $cancelled && in_array($record['state'] ?? '', self::MONEY_STATES, true) ? PaymentDecision::REVIEW_ORDER_CLOSED : null;
    }

    private function transition(\Order $order, int $state): void
    {
        if ((int) $order->current_state === $state) {
            return;
        }
        $history = new \OrderHistory();
        $history->id_order = (int) $order->id;
        $history->changeIdOrderState($state, $order, true);
        if (!$history->add()) {
            throw new \RuntimeException('Native order history could not be saved.');
        }
        // Native status emails are queued as references, not sent pre-commit.
        MailOutbox::history((int) $history->id, (int) $order->id);
    }

    /**
     * Visible merchant review: a private order message (once per payment balance) and,
     * when $hold, the non-paid, non-shipping review state. No order payment is recorded.
     */
    private function review(\Order $order, string $reason, PaymentDecision $decision, bool $hold): void
    {
        $reference = 'lunixi-review-' . substr(hash('sha256', implode("\0", [self::key($order), $reason, $decision->paymentId, $decision->state, $decision->capturedAmount, $decision->refundedAmount])), 0, 24);
        $q = Storage::pdo()->prepare('SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'customer_message` m JOIN `' . _DB_PREFIX_ . 'customer_thread` t'
            . ' ON t.id_customer_thread = m.id_customer_thread WHERE t.id_order = ? AND m.message LIKE ?');
        $q->execute([(int) $order->id, '%' . $reference . '%']);
        if ((int) $q->fetchColumn() === 0) {
            // Whole literals keep the translation keys extractable.
            // phpcs:disable Generic.Files.LineLength.TooLong
            $text = $reason === PaymentDecision::REVIEW_LINKED_PAYMENT
                ? 'Lunixi payment review: another payment of this order (%payment%, %amount%) was captured, for example a late bank transfer. It was not recorded as an order payment. Accept it manually or refund it from the Lunixi panel. Ref: %reference%'
                : 'Lunixi payment review: payment %payment% (%amount%) arrived for a cancelled order. The order and its stock were not changed and no payment was recorded. Reopen the order manually or refund it from the Lunixi panel. Ref: %reference%';
            // phpcs:enable Generic.Files.LineLength.TooLong
            $amount = Money::decimal($decision->capturedAmount, self::exponent($order)) . ' ' . (new \Currency((int) $order->id_currency))->iso_code;
            $parameters = ['%payment%' => $decision->paymentId, '%amount%' => $amount, '%reference%' => $reference];
            $context = \Context::getContext();
            $message = $context !== null ? $context->getTranslator()->trans($text, $parameters, 'Modules.Lunixipay.Admin') : strtr($text, $parameters);
            $customer = new \Customer((int) $order->id_customer);
            $threadId = (int) \CustomerThread::getIdCustomerThreadByEmailAndIdOrder((string) $customer->email, (int) $order->id);
            if ($threadId < 1) {
                $thread = new \CustomerThread();
                $thread->id_shop = (int) $order->id_shop;
                $thread->id_lang = (int) $order->id_lang;
                $thread->id_contact = 0;
                $thread->id_customer = (int) $order->id_customer;
                $thread->id_order = (int) $order->id;
                $thread->email = (string) $customer->email;
                $thread->status = 'open';
                $thread->token = \Tools::passwdGen(12);
                if (!$thread->add()) {
                    throw new \RuntimeException('Native order review thread could not be saved.');
                }
                $threadId = (int) $thread->id;
            }
            $note = new \CustomerMessage();
            $note->id_customer_thread = $threadId;
            $note->id_employee = 0;
            $note->message = $message;
            $note->private = true;
            if (!$note->add()) {
                throw new \RuntimeException('Native order review message could not be saved.');
            }
        }
        if ($hold) {
            $state = (int) \Configuration::get('LUNIXI_OS_REVIEW', null, null, (int) $order->id_shop);
            if ($state < 1) {
                throw new \RuntimeException('Native order state is not configured.');
            }
            \Lunixipay::$mailTransaction = true;
            try {
                $this->transition($order, $state);
            } finally {
                \Lunixipay::$mailTransaction = false;
            }
        }
    }
}
