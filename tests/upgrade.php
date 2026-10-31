<?php

declare(strict_types=1);

// Actual native dependency-only upgrade; run only on a disposable TEST shop.
if (getenv('LUNIXI_NATIVE_TEST') !== '1') {
    throw new RuntimeException('Explicit isolated native TEST opt-in is required.');
}
$root = $argv[1] ?? '';
$mode = $argv[2] ?? '';
if (!in_array($mode, ['snapshot', 'upgrade'], true) || !is_file($root . '/config/config.inc.php')) {
    throw new RuntimeException('Supply the disposable platform root and snapshot or upgrade.');
}
$_SERVER['HTTP_HOST'] = 'localhost:8080';
require $root . '/config/config.inc.php';
Context::getContext()->employee = new Employee(1);
require_once _PS_ROOT_DIR_ . '/app/AppKernel.php';
if (is_file(_PS_ROOT_DIR_ . '/app/AdminKernel.php')) {
    require_once _PS_ROOT_DIR_ . '/app/AdminKernel.php';
    $kernel = new AdminKernel('prod', false);
} else {
    $kernel = new AppKernel('prod', false);
}
$kernel->boot();
Context::getContext()->container = $kernel->getContainer();

/** Hash retained state without logging financial payloads or credentials. */
$snapshot = static function (): array {
    $db = Db::getInstance();
    $state = [];
    $tables = [
        'orders', 'order_payment', 'order_history', 'lunixi_attempts',
        'lunixi_events', 'lunixi_operations', 'lunixi_mail',
    ];
    foreach ($tables as $table) {
        $rows = $db->executeS('SELECT * FROM `' . _DB_PREFIX_ . $table . '`');
        if (!is_array($rows)) {
            throw new RuntimeException('Native retained-table snapshot failed.');
        }
        $encoded = array_map(static fn(array $row): string => json_encode($row, JSON_THROW_ON_ERROR), $rows);
        sort($encoded, SORT_STRING);
        $state[$table] = hash('sha256', implode("\n", $encoded));
    }
    $settings = $db->executeS(
        'SELECT name,value,id_shop,id_shop_group FROM `' . _DB_PREFIX_
        . 'configuration` WHERE LEFT(name,7)="LUNIXI_" ORDER BY id_configuration'
    );
    $state['configuration'] = hash('sha256', json_encode($settings, JSON_THROW_ON_ERROR));
    return $state;
};

$file = sys_get_temp_dir() . '/lunixi-prestashop-upgrade-before.json';
$module = Module::getInstanceByName('lunixipay');
if (!$module || !$module->active) {
    throw new RuntimeException('An active previously installed native module is required.');
}
$databaseVersion = static fn(): string => (string) Db::getInstance()->getValue(
    'SELECT version FROM `' . _DB_PREFIX_ . 'module` WHERE name="lunixipay"'
);
if ($mode === 'snapshot') {
    if ($module->version !== '0.1.4' || $databaseVersion() !== '0.1.4') {
        throw new RuntimeException('Snapshot requires the installed public 0.1.4 module.');
    }
    file_put_contents($file, json_encode($snapshot(), JSON_THROW_ON_ERROR), LOCK_EX);
    chmod($file, 0600);
    echo 'PrestaShop ' . _PS_VERSION_ . ": public 0.1.4 retained-state snapshot recorded without exposing values.\n";
    exit;
}
if ($module->version !== '0.1.5' || $databaseVersion() !== '0.1.4' || !is_file($file)) {
    throw new RuntimeException('Upgrade requires new 0.1.5 files and stored 0.1.4 version.');
}
$before = json_decode((string) file_get_contents($file), true, 16, JSON_THROW_ON_ERROR);
if ($before !== $snapshot()) {
    throw new RuntimeException('Retained state changed before the native upgrade.');
}
$metadata = null;
foreach (Module::getModulesOnDisk() as $candidate) {
    if ($candidate->name === 'lunixipay') {
        $metadata = $candidate;
        break;
    }
}
if ($metadata === null || !Module::initUpgradeModule($metadata)) {
    throw new RuntimeException('The native upgrade could not be prepared.');
}
$module->runUpgradeModule();
if ($databaseVersion() !== '0.1.5' || !$module->active || $before !== $snapshot()) {
    throw new RuntimeException('Native upgrade failed or changed retained payment state.');
}
unlink($file);
echo 'PrestaShop ' . _PS_VERSION_ . ': native 0.1.4→0.1.5 upgrade passed; '
    . 'encrypted settings, attempts, events, native orders/payments/history '
    . "and outbox hashes unchanged.\n";
