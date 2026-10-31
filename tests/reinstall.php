<?php
declare(strict_types=1);
if (getenv('LUNIXI_NATIVE_TEST') !== '1') { throw new RuntimeException('Refusing native test mutations; set LUNIXI_NATIVE_TEST=1 only for an isolated disposable shop.'); }
$_SERVER['HTTP_HOST'] = 'localhost:8080';
require ($argv[1] ?? '/var/www/prestashop') . '/config/config.inc.php';
Context::getContext()->employee = new Employee(1);
require_once _PS_ROOT_DIR_ . '/app/AppKernel.php';
if (is_file(_PS_ROOT_DIR_ . '/app/AdminKernel.php')) {
    require_once _PS_ROOT_DIR_ . '/app/AdminKernel.php';
    $kernel = new AdminKernel('prod', false);
} else { $kernel = new AppKernel('prod', false); }
$kernel->boot();
Context::getContext()->container = $kernel->getContainer();
$module = Module::getInstanceByName('lunixipay');
$state = (int) Configuration::get('LUNIXI_OS_PENDING');
if ($module->id) { throw new RuntimeException('Reinstall test requires an uninstalled module.'); }
if (!$module->install()) { throw new RuntimeException('Fresh-request native reinstall failed: ' . implode(';', $module->getErrors())); }
if ((int) Configuration::get('LUNIXI_OS_PENDING') !== $state) { throw new RuntimeException('Reinstall changed financial state ids.'); }
echo "Native fresh-request reinstall passed; state ids and financial history retained.\n";
