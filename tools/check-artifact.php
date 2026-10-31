<?php
/** Inspect the actual merchant artifact, including its standalone scoped autoloader. */
declare(strict_types=1);
$root = dirname(__DIR__);
$path = $root . '/dist/lunixipay-0.1.1.zip';
if (!is_file($path)) { throw new RuntimeException('Build the release ZIP first.'); }
$zip = new ZipArchive();
if ($zip->open($path) !== true) { throw new RuntimeException('Invalid release ZIP.'); }
$count = 0;
for ($index = 0; $index < $zip->numFiles; ++$index) {
    $name = $zip->getNameIndex($index);
    if (!is_string($name) || !str_starts_with($name, 'lunixipay/') || str_contains($name, '..') || preg_match('~/(?:tests|vendor|\.git|\.env|build|tmp)(?:/|$)~', $name)) {
        throw new RuntimeException('Unsafe or development-only ZIP entry.');
    }
    $value = $zip->getFromIndex($index);
    if (!is_string($value) || preg_match('~/(?:Users|home)/|git[.][A-Za-z0-9.-]+|-----BEGIN [A-Z ]*PRIVATE KEY-----\R[A-Za-z0-9+/=]{20,}~', $value)) {
        throw new RuntimeException('Internal path or private key material in ZIP.');
    }
    if (str_ends_with($name, '.php') && preg_match('/namespace\s+Lunixi\\\\Sdk(?:[;\\\\])/', $value)) {
        throw new RuntimeException('Unscoped SDK namespace in ZIP.');
    }
    ++$count;
}
foreach (['lunixipay/lunixipay.php', 'lunixipay/autoload.php', 'lunixipay/sdk/autoload.php', 'lunixipay/sdk/Commerce/CheckoutService.php', 'lunixipay/SECURITY.md', 'lunixipay/LICENSE', 'lunixipay/logo.png'] as $required) {
    if ($zip->locateName($required) === false) { throw new RuntimeException('Required merchant file is missing.'); }
}
$zip->close();
require $root . '/build/lunixipay/autoload.php';
if (!class_exists('Lunixi\\PrestaShop\\Vendor\\Lunixi\\Sdk\\Commerce\\CheckoutService') || class_exists('Lunixi\\Sdk\\Commerce\\CheckoutService', false)) {
    throw new RuntimeException('Standalone SDK isolation failed.');
}
\Lunixi\PrestaShop\Access::post('POST', 'synthetic-csrf', 'synthetic-csrf', true);
$rejected = 0;
foreach ([['GET', 'a', 'a', true], ['POST', 'a', 'b', true], ['POST', '', '', true], ['POST', 'a', 'a', false]] as $case) {
    try { \Lunixi\PrestaShop\Access::post(...$case); } catch (RuntimeException $e) { ++$rejected; }
}
if ($rejected !== 4) { throw new RuntimeException('Access boundary regression.'); }
echo 'Artifact checks passed: ' . $count . ' files; standalone SDK isolated; CSRF/capability boundary; no private key/internal path/development tree. SHA256 ' . hash_file('sha256', $path) . "\n";
