<?php

declare(strict_types=1);

namespace Lunixi\PrestaShop;

use Lunixi\PrestaShop\Vendor\Lunixi\Sdk\Commerce\Money;

/**
 * Exact basket of a native order: its product lines, then shipping and gift wrapping as VIRTUAL items.
 *
 * Every price is a nonnegative canonical minor-unit string and the prices add up to the order amount
 * exactly. A discount is never a negative item: it is spread over the product lines in proportion to
 * their totals with integer arithmetic, the remaining minor units going to the last lines. A free-shipping
 * cart rule carries the shipping cost inside the discount total, so its shipping item is omitted. Only the
 * rounding differences of the native totals are absorbed; any other difference throws and the payment
 * does not start.
 */
final class Basket
{
    public const SHIPPING_ID = 'shipping';
    public const WRAPPING_ID = 'gift-wrapping';

    /**
     * @param list<array{id:string,name:string,category:string,physical:bool,total:string}> $lines Product lines, totals in minor units.
     * @param string $shipping Shipping total (tax included) in minor units.
     * @param string $wrapping Gift wrapping total (tax included) in minor units.
     * @param string $discount Discount total (tax included) in minor units.
     * @param string $amount The order amount the basket must add up to.
     * @return list<array{id:string,name:string,category1:string,itemType:string,price:string}>
     * @throws OrderDetailsException
     */
    public static function items(array $lines, string $shipping, string $wrapping, string $discount, bool $freeShipping, string $amount): array
    {
        if ($lines === []) {
            throw new OrderDetailsException(['basketItems']);
        }
        if ($freeShipping) {
            $discount = Money::compare($discount, $shipping) >= 0 ? Money::subtract($discount, $shipping) : '0';
            $shipping = '0';
        }
        $products = '0';
        foreach ($lines as $line) {
            $products = Money::add($products, Money::integer($line['total']));
        }
        $extras = Money::add($shipping, $wrapping);
        if (Money::compare($extras, $amount) > 0) {
            throw new OrderDetailsException(['basketItems']);
        }
        $goods = Money::subtract($amount, $extras);
        // Each rounded native total differs from its exact value by less than one minor unit.
        $tolerance = (string) (count($lines) + 3);
        $totals = array_map(static fn(array $line): string => $line['total'], $lines);
        if (Money::compare($products, $goods) >= 0) {
            $reduction = Money::subtract($products, $goods);
            if (Money::compare(ltrim(Money::difference($reduction, $discount), '-'), $tolerance) > 0) {
                throw new OrderDetailsException(['basketItems']);
            }
            $prices = self::reduce($totals, $products, $reduction);
        } else {
            $shortfall = Money::subtract($goods, $products);
            if (Money::compare(Money::add($discount, $shortfall), $tolerance) > 0) {
                throw new OrderDetailsException(['basketItems']);
            }
            $prices = $totals;
            $last = count($prices) - 1;
            $prices[$last] = Money::add($prices[$last], $shortfall);
        }
        $items = [];
        foreach ($lines as $index => $line) {
            $items[] = ['id' => $line['id'], 'name' => $line['name'], 'category1' => $line['category'],
                'itemType' => $line['physical'] ? 'PHYSICAL' : 'VIRTUAL', 'price' => $prices[$index]];
        }
        if ($shipping !== '0') {
            $items[] = ['id' => self::SHIPPING_ID, 'name' => 'Shipping', 'category1' => 'Shipping', 'itemType' => 'VIRTUAL', 'price' => $shipping];
        }
        if ($wrapping !== '0') {
            $items[] = ['id' => self::WRAPPING_ID, 'name' => 'Gift wrapping', 'category1' => 'Gift wrapping', 'itemType' => 'VIRTUAL', 'price' => $wrapping];
        }
        $sum = '0';
        foreach ($items as $item) {
            $sum = Money::add($sum, $item['price']);
        }
        if ($sum !== $amount) {
            throw new OrderDetailsException(['basketItems']);
        }
        return $items;
    }

    /**
     * Lowers the line totals by $reduction (at most their sum) in proportion to each total; the
     * minor units left by the floored shares go to the last lines that still have room.
     *
     * @param list<string> $totals
     * @return list<string>
     */
    public static function reduce(array $totals, string $sum, string $reduction): array
    {
        if ($reduction === '0' || $sum === '0') {
            return $totals;
        }
        $shares = [];
        $taken = '0';
        foreach ($totals as $index => $total) {
            $shares[$index] = Money::prorate($reduction, $total, $sum);
            $taken = Money::add($taken, $shares[$index]);
        }
        $rest = Money::subtract($reduction, $taken);
        for ($index = count($totals) - 1; $index >= 0 && $rest !== '0'; --$index) {
            $extra = Money::minimum(Money::subtract($totals[$index], $shares[$index]), $rest);
            $shares[$index] = Money::add($shares[$index], $extra);
            $rest = Money::subtract($rest, $extra);
        }
        if ($rest !== '0') {
            throw new OrderDetailsException(['basketItems']);
        }
        $prices = [];
        foreach ($totals as $index => $total) {
            $prices[] = Money::subtract($total, $shares[$index]);
        }
        return $prices;
    }
}
