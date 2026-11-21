<?php

declare(strict_types=1);

namespace Lunixi\PrestaShop;

use Lunixi\PrestaShop\Vendor\Lunixi\Sdk\Commerce\OrderSnapshot;
use Lunixi\PrestaShop\Vendor\Lunixi\Sdk\Payment\{Address, BasketItem, Buyer};
use Lunixi\PrestaShop\Vendor\Lunixi\Sdk\Payment\OrderDetails as RequiredFields;

/**
 * Order details every payment requires, read from the native order only: the buyer and billing
 * address from the invoice address and customer, the delivery address when the order has a physical
 * product, and the basket from the order's DECIMAL rows (see Basket). No value is invented; a missing
 * field throws OrderDetailsException with its gateway path and the payment does not start.
 */
final class OrderDetails
{
    /** Category of a product without a named default category. */
    public const DEFAULT_CATEGORY = 'General';

    /**
     * The snapshot with the order details attached; its identity and fingerprint do not change.
     *
     * @param string $buyerIp The buyer's browser IP of this payment request (the native order stores none).
     * @throws OrderDetailsException
     */
    public static function attach(OrderSnapshot $snapshot, \Order $order, string $buyerIp): OrderSnapshot
    {
        $lines = self::lines($order);
        $physical = in_array(true, array_column($lines, 'physical'), true);
        $invoice = self::address((int) $order->id_address_invoice, (int) $order->id_customer);
        $delivery = $physical ? (self::address((int) $order->id_address_delivery, (int) $order->id_customer) ?? $invoice) : null;
        $details = self::fields(new \Customer((int) $order->id_customer), $invoice, $delivery, $physical, (int) $order->id_lang, $buyerIp);
        $totals = self::totals($order);
        $items = Basket::items($lines, $totals['shipping'], $totals['wrapping'], $totals['discount'], $totals['freeShipping'], $snapshot->amount());
        $snapshot = $snapshot->withBuyer(new Buyer($details['buyer']))->withBillingAddress(new Address($details['billingAddress']));
        if ($details['shippingAddress'] !== null) {
            $snapshot = $snapshot->withShippingAddress(new Address($details['shippingAddress']));
        }
        return $snapshot->withBasketItems(array_map(static fn(array $item): BasketItem => new BasketItem($item), $items));
    }

    /**
     * Checks the buyer and address fields of a cart before its native order is created; the order
     * copies the same customer and addresses.
     *
     * @throws OrderDetailsException
     */
    public static function assertCart(\Cart $cart, string $buyerIp): void
    {
        $physical = !$cart->isVirtualCart();
        $invoice = self::address((int) $cart->id_address_invoice, (int) $cart->id_customer);
        $delivery = $physical ? (self::address((int) $cart->id_address_delivery, (int) $cart->id_customer) ?? $invoice) : null;
        self::fields(new \Customer((int) $cart->id_customer), $invoice, $delivery, $physical, (int) $cart->id_lang, $buyerIp);
    }

    /**
     * @return array{buyer:array<string,string>,billingAddress:array<string,string>,shippingAddress:array<string,string>|null}
     * @throws OrderDetailsException
     */
    private static function fields(\Customer $customer, ?\Address $invoice, ?\Address $delivery, bool $physical, int $langId, string $ip): array
    {
        if ($invoice === null) {
            throw new OrderDetailsException(['billingAddress']);
        }
        $billing = self::addressFields($invoice, $langId);
        $email = trim((string) $customer->email);
        $buyer = [
            'name' => trim((string) $invoice->firstname),
            'surname' => trim((string) $invoice->lastname),
            'identityNumber' => (string) IdentityNumber::normalize((string) $invoice->dni),
            // The gateway accepts an ASCII address only.
            'email' => preg_match('/^[\x21-\x7E]+$/D', $email) === 1 ? $email : '',
            'gsmNumber' => self::phone($invoice, $delivery),
            'city' => $billing['city'],
            'country' => $billing['country'],
            'zipCode' => $billing['zipCode'],
            'ip' => filter_var($ip, FILTER_VALIDATE_IP) !== false ? $ip : '',
        ];
        if (\Validate::isLoadedObject($customer)) {
            $buyer['id'] = (string) $customer->id;
        }
        $shipping = $delivery !== null ? self::addressFields($delivery, $langId) : null;
        $missing = array_merge(
            self::missing($buyer, RequiredFields::BUYER_REQUIRED_FIELDS, 'buyer'),
            self::missing($billing, RequiredFields::ADDRESS_REQUIRED_FIELDS, 'billingAddress'),
            $physical ? ($shipping === null ? ['shippingAddress'] : self::missing($shipping, RequiredFields::ADDRESS_REQUIRED_FIELDS, 'shippingAddress')) : []
        );
        if ($missing !== []) {
            throw new OrderDetailsException($missing);
        }
        return ['buyer' => $buyer, 'billingAddress' => $billing, 'shippingAddress' => $physical ? $shipping : null];
    }

    /** @return array<string,string> */
    private static function addressFields(\Address $address, int $langId): array
    {
        $person = trim(trim((string) $address->firstname) . ' ' . trim((string) $address->lastname));
        $company = trim((string) $address->company);
        $country = \Country::getNameById($langId, (int) $address->id_country);
        return [
            'contactName' => $company === '' ? $person : ($person === '' ? $company : $person . ' (' . $company . ')'),
            'address' => implode(', ', array_filter([trim((string) $address->address1), trim((string) $address->address2)], static fn(string $line): bool => $line !== '')),
            'city' => trim((string) $address->city),
            // Country::getNameById() returns false for an unknown country despite its string PHPDoc.
            'country' => trim((string) $country),
            'zipCode' => trim((string) $address->postcode),
        ];
    }

    private static function phone(\Address $invoice, ?\Address $delivery): string
    {
        foreach ([$invoice, $delivery] as $address) {
            if ($address === null) {
                continue;
            }
            foreach ([(string) $address->phone_mobile, (string) $address->phone] as $phone) {
                if (trim($phone) !== '') {
                    return trim($phone);
                }
            }
        }
        return '';
    }

    private static function address(int $addressId, int $customerId): ?\Address
    {
        if ($addressId < 1) {
            return null;
        }
        $address = new \Address($addressId);
        return \Validate::isLoadedObject($address) && (int) $address->id_customer === $customerId ? $address : null;
    }

    /**
     * @param array<string,string> $values
     * @param list<string> $required
     * @return list<string>
     */
    private static function missing(array $values, array $required, string $path): array
    {
        $missing = [];
        foreach ($required as $field) {
            if (trim($values[$field] ?? '') === '') {
                $missing[] = $path . '.' . $field;
            }
        }
        return $missing;
    }

    /**
     * Product lines as minor-unit text from the native DECIMAL columns; never PHP floats.
     *
     * @return list<array{id:string,name:string,category:string,physical:bool,total:string}>
     */
    private static function lines(\Order $order): array
    {
        $query = Storage::pdo()->prepare(
            'SELECT od.product_id, od.product_attribute_id, od.product_name, od.total_price_tax_incl, p.is_virtual, cl.name AS category'
            . ' FROM `' . _DB_PREFIX_ . 'order_detail` od'
            . ' LEFT JOIN `' . _DB_PREFIX_ . 'product` p ON p.id_product = od.product_id'
            . ' LEFT JOIN `' . _DB_PREFIX_ . 'product_shop` ps ON ps.id_product = od.product_id AND ps.id_shop = ?'
            . ' LEFT JOIN `' . _DB_PREFIX_ . 'category_lang` cl ON cl.id_category = COALESCE(ps.id_category_default, p.id_category_default) AND cl.id_shop = ? AND cl.id_lang = ?'
            . ' WHERE od.id_order = ? ORDER BY od.id_order_detail'
        );
        $query->execute([(int) $order->id_shop, (int) $order->id_shop, (int) $order->id_lang, (int) $order->id]);
        $lines = [];
        foreach ($query->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $attribute = (int) $row['product_attribute_id'];
            $category = trim((string) ($row['category'] ?? ''));
            $lines[] = [
                'id' => (string) (int) $row['product_id'] . ($attribute > 0 ? '-' . $attribute : ''),
                'name' => trim((string) $row['product_name']),
                'category' => $category === '' ? self::DEFAULT_CATEGORY : $category,
                // A product deleted since the order counts as shipped goods.
                'physical' => $row['is_virtual'] === null || (int) $row['is_virtual'] !== 1,
                'total' => Bridge::minor((string) $row['total_price_tax_incl'], $order),
            ];
        }
        return $lines;
    }

    /** @return array{shipping:string,wrapping:string,discount:string,freeShipping:bool} */
    private static function totals(\Order $order): array
    {
        $query = Storage::pdo()->prepare('SELECT total_shipping_tax_incl, total_wrapping_tax_incl, total_discounts_tax_incl FROM `' . _DB_PREFIX_ . 'orders` WHERE id_order = ?');
        $query->execute([(int) $order->id]);
        $row = $query->fetch(\PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            throw new \RuntimeException('Native order totals are unavailable.');
        }
        $rule = Storage::pdo()->prepare('SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'order_cart_rule` WHERE id_order = ? AND free_shipping = 1');
        $rule->execute([(int) $order->id]);
        return [
            'shipping' => Bridge::minor((string) $row['total_shipping_tax_incl'], $order),
            'wrapping' => Bridge::minor((string) $row['total_wrapping_tax_incl'], $order),
            'discount' => Bridge::minor((string) $row['total_discounts_tax_incl'], $order),
            'freeShipping' => (int) $rule->fetchColumn() > 0,
        ];
    }
}
