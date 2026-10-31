<?php

declare(strict_types=1);

namespace Lunixi\PrestaShop;

/** Explicit lifecycle checks for PrestaShop's nullable legacy context fields. */
final class Runtime
{
    public static function context(?\Context $context): \Context
    {
        if (!$context instanceof \Context) {
            throw new \RuntimeException('Native request context is unavailable.');
        }
        return $context;
    }
    public static function shop(?\Context $context): \Shop
    {
        $value = self::context($context)->shop;
        if (!$value instanceof \Shop) {
            throw new \RuntimeException('Native shop context is unavailable.');
        }
        return $value;
    }
    public static function customer(?\Context $context): \Customer
    {
        $value = self::context($context)->customer;
        if (!$value instanceof \Customer) {
            throw new \RuntimeException('Native customer context is unavailable.');
        }
        return $value;
    }
    public static function employee(?\Context $context): \Employee
    {
        $value = self::context($context)->employee;
        if (!$value instanceof \Employee) {
            throw new \RuntimeException('Native employee context is unavailable.');
        }
        return $value;
    }
    public static function language(?\Context $context): \Language
    {
        $value = self::context($context)->language;
        if (!$value instanceof \Language) {
            throw new \RuntimeException('Native language context is unavailable.');
        }
        return $value;
    }
    public static function link(?\Context $context): \Link
    {
        $value = self::context($context)->link;
        if (!$value instanceof \Link) {
            throw new \RuntimeException('Native link context is unavailable.');
        }
        return $value;
    }
    public static function smarty(?\Context $context): \Smarty
    {
        $value = self::context($context)->smarty;
        if (!$value instanceof \Smarty) {
            throw new \RuntimeException('Native template context is unavailable.');
        }
        return $value;
    }
    public static function cart(?\Context $context): \Cart
    {
        $value = self::context($context)->cart;
        if (!$value instanceof \Cart) {
            throw new \RuntimeException('Native cart context is unavailable.');
        }
        return $value;
    }
    public static function module(\Module $module): \Lunixipay
    {
        if (!$module instanceof \Lunixipay) {
            throw new \RuntimeException('Native module identity mismatch.');
        }
        return $module;
    }
}
