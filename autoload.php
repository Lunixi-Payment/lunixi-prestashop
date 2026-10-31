<?php

/** Standalone release autoloader. No site-wide Composer autoloader is loaded. */

declare(strict_types=1);

require_once __DIR__ . '/sdk/autoload.php';
spl_autoload_register(static function (string $class): void {
    $prefix = 'Lunixi\\PrestaShop\\';
    if (strncmp($class, $prefix, strlen($prefix)) === 0) {
        $file = __DIR__ . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (is_file($file)) {
            require_once $file;
        }
    }
});
