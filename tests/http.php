<?php
/** Actual native front-controller HTTP acceptance. The server and fixture must be disposable. */
declare(strict_types=1);

$base = rtrim($argv[1] ?? 'http://localhost:8080', '/');
$host = $argv[2] ?? '';
$assertions = 0;
function check(bool $condition, string $message): void
{
    global $assertions;
    ++$assertions;
    if (!$condition) { throw new RuntimeException('Native HTTP failed: ' . $message); }
}
/** @return array{status:int,headers:string,body:string,location:string} */
function request(string $method, string $path, ?string $body = null, ?string $jar = null, bool $crossSite = false): array
{
    global $base, $host;
    $curl = curl_init($base . '/' . $path);
    if ($curl === false) { throw new RuntimeException('Could not initialize native HTTP client.'); }
    $headers = ['Content-Type: application/x-www-form-urlencoded'];
    if ($host !== '') { $headers[] = 'Host: ' . $host; }
    if ($crossSite) { $headers[] = 'Origin: https://pay.lunixi.com'; $headers[] = 'Sec-Fetch-Site: cross-site'; }
    curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_TIMEOUT => 30, CURLOPT_FOLLOWLOCATION => false, CURLOPT_HTTPHEADER => $headers]);
    if ($body !== null) { curl_setopt($curl, CURLOPT_POSTFIELDS, $body); }
    if ($jar !== null) { curl_setopt($curl, CURLOPT_COOKIEFILE, $jar); curl_setopt($curl, CURLOPT_COOKIEJAR, $jar); }
    $raw = curl_exec($curl);
    if (!is_string($raw)) { throw new RuntimeException('Native HTTP transport failed.'); }
    $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    $headerSize = (int) curl_getinfo($curl, CURLINFO_HEADER_SIZE);
    $responseHeaders = substr($raw, 0, $headerSize);
    $responseBody = substr($raw, $headerSize);
    curl_close($curl);
    preg_match('/^Location:\s*(.+)$/mi', $responseHeaders, $matches);
    check(!str_contains($raw, 'native_checkout'), 'checkout token disclosed to a rejected caller');
    return ['status' => $status, 'headers' => $responseHeaders, 'body' => $responseBody, 'location' => trim($matches[1] ?? '')];
}

$prefix = 'index.php?fc=module&module=lunixipay&controller=';
foreach ([['GET', 'webhook&shop_id=1', null, 405], ['POST', 'webhook&shop_id=1', '{}', 400], ['GET', 'checkout&id_order=1', null, 302]] as [$method, $path, $body, $expected]) {
    check(request($method, $prefix . $path, $body)['status'] === $expected, 'method/signature/checkout boundary');
}

$fixturePath = sys_get_temp_dir() . '/lunixi-prestashop-http-fixture.json';
if (!is_file($fixturePath)) { throw new RuntimeException('Run native.php then reinstall.php in this disposable fixture before HTTP acceptance.'); }
$fixture = json_decode((string) file_get_contents($fixturePath), true, 16, JSON_THROW_ON_ERROR);
$id = filter_var($fixture['orderId'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$email = $fixture['otherEmail'] ?? null;
if (!$id || !is_string($email) || !preg_match('/^native-[a-f0-9]+@example\.test$/D', $email)) { throw new RuntimeException('Invalid synthetic HTTP fixture identity.'); }
$return = $prefix . 'return&id_order=' . $id;
foreach (['id_order=999&status=CAPTURED&amount=999999&redirect=https://attacker.example', '', '_signature=not-a-webhook-proof&paymentId=forged'] as $body) {
    $response = request('POST', $return, $body, null, true);
    check($response['status'] === 303, 'anonymous cross-site return did not use 303');
    $url = parse_url($response['location']);
    parse_str($url['query'] ?? '', $query);
    $nativeRoute = (($query['module'] ?? '') === 'lunixipay' && ($query['controller'] ?? '') === 'return') || preg_match('~/module/lunixipay/return$~D', $url['path'] ?? '');
    check(($query['id_order'] ?? '') === (string) $id && $nativeRoute, 'POST fields changed fixed native target');
    $approved = parse_url($host !== '' ? 'http://' . $host : $base);
    check(($url['host'] ?? '') === ($approved['host'] ?? '') && ($url['port'] ?? 80) === ($approved['port'] ?? 80), 'callback redirect left the approved native shop origin');
    check($response['body'] === '', 'informational callback disclosed data');
}
check(request('POST', $return, str_repeat('x', 1048577), null, true)['status'] === 413, 'oversized return body accepted');
check(request('POST', $prefix . 'return&id_order=999999999', 'status=CAPTURED', null, true)['status'] === 303, 'anonymous POST leaked whether an order exists');
check(request('POST', $prefix . 'return', 'id_order=' . $id, null, true)['status'] === 400, 'POST body supplied the order locator');
$anonymous = request('GET', $return);
$authentication = parse_url($anonymous['location']);
check($anonymous['status'] === 302 && ($authentication['host'] ?? '') === ($approved['host'] ?? ''), 'GET return stopped requiring native customer authentication');
$loginPath = ltrim($authentication['path'] ?? '', '/') . (isset($authentication['query']) ? '?' . $authentication['query'] : '');

// Authenticate a DIFFERENT synthetic native customer through the actual login form.
// Its GET must fail ownership before any gateway read; no valid financial request is sent.
$jar = tempnam(sys_get_temp_dir(), 'lunixi-prestashop-http-cookie-');
if ($jar === false) { throw new RuntimeException('Could not create isolated native cookie jar.'); }
try {
    $login = request('GET', $loginPath, null, $jar);
    check($login['status'] === 200 && str_contains($login['body'], 'name="password"'), 'native login form unavailable');
    $params = ['email' => $email, 'password' => 'test-only-customer-password', 'submitLogin' => '1'];
    if (preg_match('/<input[^>]*name="token"[^>]*value="([^"]+)"/i', $login['body'], $match)) { $params['token'] = html_entity_decode($match[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'); }
    $logged = request('POST', $loginPath, http_build_query($params), $jar);
    check(in_array($logged['status'], [302, 303], true), 'synthetic native customer login failed');
    check(request('GET', $return, null, $jar)['status'] === 403, 'authenticated different customer accessed another order');
} finally { unlink($jar); }

echo 'Native HTTP: ' . $assertions . " assertions passed; signature/method, no-session cross-site POST/303, forged fields, size and authenticated ownership; no financial request.\n";
