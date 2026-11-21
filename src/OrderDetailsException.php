<?php

declare(strict_types=1);

namespace Lunixi\PrestaShop;

/**
 * The native order lacks a field every payment requires, or its totals cannot be itemized.
 * Carries the gateway paths of the failing fields (`buyer.gsmNumber`, `billingAddress.zipCode`,
 * `basketItems`), never their values, so it is safe to log.
 */
final class OrderDetailsException extends \RuntimeException
{
    /** @var list<string> */
    private array $fields;

    /** @param list<string> $fields */
    public function __construct(array $fields)
    {
        parent::__construct('Order details are incomplete: ' . implode(', ', $fields) . '.');
        $this->fields = $fields;
    }

    /** @return list<string> */
    public function fields(): array
    {
        return $this->fields;
    }
}
