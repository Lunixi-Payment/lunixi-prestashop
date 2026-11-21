<?php

declare(strict_types=1);

namespace Lunixi\PrestaShop;

/**
 * The buyer's Turkish ID number (TCKN) or passport number, kept in the native identification
 * number (`dni`) of the order's invoice address. The invoice address value is used when it is
 * valid; otherwise the payment form asks for it and it is saved on that address.
 */
final class IdentityNumber
{
    /** Form field of the payment option. */
    public const FIELD = 'lunixi_identity_number';

    /** Longest value the native address identification number stores. */
    public const NATIVE_MAX_LENGTH = 16;

    /**
     * The canonical value, or null when invalid: 11 digits must pass the TCKN check digits,
     * anything else must be a passport number of 5 to 20 letters and digits.
     */
    public static function normalize(string $value): ?string
    {
        $value = strtoupper((string) preg_replace('/\s+/', '', $value));
        if (preg_match('/^[0-9]{11}$/D', $value) === 1) {
            return self::isTurkishId($value) ? $value : null;
        }
        return preg_match('/^[A-Z0-9]{5,20}$/D', $value) === 1 ? $value : null;
    }

    /** TCKN rule: no leading zero, 10th and 11th digits are check digits. */
    public static function isTurkishId(string $value): bool
    {
        if (preg_match('/^[1-9][0-9]{10}$/D', $value) !== 1) {
            return false;
        }
        $digits = array_map('intval', str_split($value));
        $odd = $digits[0] + $digits[2] + $digits[4] + $digits[6] + $digits[8];
        $even = $digits[1] + $digits[3] + $digits[5] + $digits[7];
        $tenth = (($odd * 7 - $even) % 10 + 10) % 10;
        return $tenth === $digits[9] && array_sum(array_slice($digits, 0, 10)) % 10 === $digits[10];
    }

    /** True when the cart's invoice address has no valid identification number yet. */
    public static function requiredForCart(\Cart $cart): bool
    {
        $address = new \Address((int) $cart->id_address_invoice);
        return !\Validate::isLoadedObject($address) || self::normalize((string) $address->dni) === null;
    }

    /**
     * Makes sure the cart's invoice address carries a valid identification number before the
     * native order is created; a valid stored value wins over the submitted one. An address that
     * earlier orders reference is never rewritten: like the native address form, a copy carries the
     * new value and becomes the cart's invoice address (the delivery address and its carrier stay).
     *
     * @throws OrderDetailsException
     */
    public static function ensureOnCart(\Cart $cart, string $submitted): void
    {
        $address = new \Address((int) $cart->id_address_invoice);
        if (!\Validate::isLoadedObject($address) || (int) $address->id_customer !== (int) $cart->id_customer) {
            throw new OrderDetailsException(['billingAddress']);
        }
        if (self::normalize((string) $address->dni) !== null) {
            return;
        }
        $identity = self::normalize($submitted);
        if ($identity === null || strlen($identity) > self::NATIVE_MAX_LENGTH) {
            throw new OrderDetailsException(['buyer.identityNumber']);
        }
        if (!$address->isUsed()) {
            $address->dni = $identity;
            if (!$address->update()) {
                throw new \RuntimeException('Native invoice address could not be saved.');
            }
            return;
        }
        $previous = (int) $address->id;
        // ObjectModel::add() drops the copied primary key and inserts a new address row.
        $copy = clone $address;
        $copy->dni = $identity;
        if (!$copy->add()) {
            throw new \RuntimeException('Native invoice address could not be saved.');
        }
        $cart->id_address_invoice = (int) $copy->id;
        if (!$cart->update()) {
            throw new \RuntimeException('Native cart could not be saved.');
        }
        if ($previous !== (int) $cart->id_address_delivery) {
            // A used address is only flagged deleted; the earlier orders keep it.
            $address->delete();
        }
    }
}
