<?php
declare(strict_types=1);
$root = getenv('PRESTASHOP_ROOT');
if (!$root || !is_file($root . '/config/config.inc.php')) { throw new RuntimeException('Set PRESTASHOP_ROOT to an installed native test shop.'); }
$_SERVER['HTTP_HOST'] = 'localhost:8080';
require $root . '/config/config.inc.php';
require_once $root . '/modules/lunixipay/lunixipay.php';
