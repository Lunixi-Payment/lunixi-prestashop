<?php

declare(strict_types=1);

namespace Lunixi\PrestaShop;

final class Access
{
    public static function buyer(\Context $context, \Order $order): void
    {
        if (
            !\Validate::isLoadedObject($order) || $order->module !== 'lunixipay' || !$context->customer instanceof \Customer || !$context->shop instanceof \Shop || !$context->customer->isLogged(true) ||
            (int) $context->customer->id !== (int) $order->id_customer || (int) $context->shop->id !== (int) $order->id_shop
        ) {
            throw new \RuntimeException('Order access denied.');
        }
    }
    public static function post(string $method, string $given, string $expected, bool $allowed): void
    {
        if ($method !== 'POST' || !$allowed || $expected === '' || !hash_equals($expected, $given)) {
            throw new \RuntimeException('Permission or CSRF verification failed.');
        }
    }
    public static function employee(\Context $context, \Order $order): void
    {
        if (
            !$context->employee || !$context->employee->isLoggedBack() || !$context->employee->can('edit', 'AdminOrders') ||
            !in_array((int) $order->id_shop, array_map('intval', $context->employee->getAssociatedShopIds()), true)
        ) {
            throw new \RuntimeException('Order administration permission denied.');
        }
    }
}
