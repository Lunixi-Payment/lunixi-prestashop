<?php

declare(strict_types=1);

// phpcs:disable PSR1.Files.SideEffects -- Native upgrade hook requires a platform guard and named entry function.
if (!defined('_PS_VERSION_')) {
    exit;
}

/** Dependency-only upgrade: native PrestaShop records the new module version. */
function upgrade_module_0_1_5($module): bool
{
    // Do not reinstall schema, reset settings, rewrite encrypted financial data,
    // or create payment attempts when replacing the bundled transport.
    return $module instanceof Lunixipay;
}
