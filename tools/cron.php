<?php

declare(strict_types=1);

// Run with: php modules/lunixipay/tools/cron.php /absolute/path/to/shop
if (PHP_SAPI !== 'cli' || $argc !== 2 || !is_file($argv[1] . '/config/config.inc.php')) {
    exit(2);
}
require rtrim($argv[1], '/') . '/config/config.inc.php';
$context = Context::getContext();
$context->controller = new FrontController();
$context->container = \PrestaShop\PrestaShop\Adapter\ContainerBuilder::getContainer('front', _PS_MODE_DEV_);
$module = Module::getInstanceByName('lunixipay');
if (!$module || !$module->active) {
    exit(1);
}
use Lunixi\PrestaShop\{Bridge, MailOutbox, Storage};
try {
    $q = Storage::pdo()->query('SELECT order_key FROM `' . _DB_PREFIX_ . 'lunixi_attempts` WHERE payload IS NOT NULL ORDER BY order_key');
    foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $key) {
        if (!preg_match('/^ps:([0-9]+):order:([0-9]+)$/D', $key, $match)) {
            continue;
        }
        $order = new Order((int) $match[2]);
        Shop::setContext(Shop::CONTEXT_SHOP, (int) $match[1]);
        try {
            (new Bridge((int) $order->id_shop))->reconcile($order);
        } catch (Throwable $e) {
            fwrite(STDERR, "Lunixi reconciliation deferred; check operator monitoring.\n");
        }
    }
    MailOutbox::drain();
} catch (Throwable $e) {
    fwrite(STDERR, "Lunixi maintenance failed.\n");
    exit(1);
}
