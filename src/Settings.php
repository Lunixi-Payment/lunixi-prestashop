<?php

declare(strict_types=1);

namespace Lunixi\PrestaShop;

use Lunixi\PrestaShop\Vendor\Lunixi\Sdk\Commerce\SecretBox;
use Lunixi\PrestaShop\Vendor\Lunixi\Sdk\LunixiClient;

final class Settings
{
    public const GATEWAY = 'https://api-gateway.lunixi.com';
    public static function box(): SecretBox
    {
        $key = base64_decode((string) getenv('LUNIXI_ENCRYPTION_KEY'), true);
        if ($key === false) {
            throw new \RuntimeException('Server encryption key is not configured.');
        }
        return new SecretBox($key);
    }
    /** @return array<string,string> */
    public static function secrets(int $shopId): array
    {
        $value = (string) \Configuration::get('LUNIXI_CREDENTIALS', null, null, $shopId);
        $data = json_decode(self::box()->decrypt($value), true, 16, JSON_THROW_ON_ERROR);
        if (!is_array($data)) {
            throw new \RuntimeException('Merchant configuration is invalid.');
        }
        return $data;
    }
    /** Embedded is the default; preserve an explicitly selected hosted mode. */
    public static function mode(int $shopId): string
    {
        return \Configuration::get('LUNIXI_MODE', null, null, $shopId) === 'hosted' ? 'hosted' : 'embedded';
    }
    /** Preserve existing credentials when a password field is left empty. */
    public static function save(int $shopId, string $merchantId, string $keyId, string $privateKey, string $secret, string $mode, bool $preauth): void
    {
        $old = [];
        if (\Configuration::get('LUNIXI_CREDENTIALS', null, null, $shopId)) {
            $old = self::secrets($shopId);
        }
        $data = ['merchantId' => trim($merchantId), 'keyId' => trim($keyId),
                 'privateKey' => $privateKey !== '' ? $privateKey : ($old['privateKey'] ?? ''),
                 'webhookSecret' => $secret !== '' ? $secret : ($old['webhookSecret'] ?? '')];
        if ($data['merchantId'] === '' || strlen($data['merchantId']) > 128 || $data['webhookSecret'] === '' || !in_array($mode, ['hosted', 'embedded'], true)) {
            throw new \InvalidArgumentException('Merchant credentials and checkout mode are required.');
        }
        if (isset($old['merchantId']) && $old['merchantId'] !== $data['merchantId']) {
            $query = Storage::pdo()->prepare('SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'lunixi_attempts` WHERE order_key LIKE ? AND payload IS NOT NULL');
            $query->execute(['ps:' . $shopId . ':order:%']);
            if ((int) $query->fetchColumn() > 0) {
                throw new \RuntimeException('Historical payments bind this shop to its merchant account.');
            }
        }
        // Validate using the SDK before persisting. This performs no network call.
        LunixiClient::create(['baseUrl' => self::GATEWAY, 'keyId' => $data['keyId'], 'privateKey' => $data['privateKey']]);
        if ($old !== [] && $old !== $data) {
            $history = json_decode((string) (\Configuration::get('LUNIXI_CREDENTIAL_HISTORY', null, null, $shopId) ?: '[]'), true, 64, JSON_THROW_ON_ERROR);
            if (!is_array($history)) {
                throw new \RuntimeException('Credential history is invalid.');
            }
            $history[] = (string) \Configuration::get('LUNIXI_CREDENTIALS', null, null, $shopId);
            \Configuration::updateValue('LUNIXI_CREDENTIAL_HISTORY', json_encode($history, JSON_THROW_ON_ERROR), false, null, $shopId);
        }
        \Configuration::updateValue('LUNIXI_CREDENTIALS', self::box()->encrypt(json_encode($data, JSON_THROW_ON_ERROR)), false, null, $shopId);
        \Configuration::updateValue('LUNIXI_MODE', $mode, false, null, $shopId);
        \Configuration::updateValue('LUNIXI_PREAUTH', $preauth ? '1' : '0', false, null, $shopId);
    }
    /** @return list<string> Current plus two retained webhook secrets during rotation. */
    public static function webhookSecrets(int $shopId): array
    {
        $current = self::secrets($shopId);
        $secrets = [$current['webhookSecret']];
        $history = json_decode((string) (\Configuration::get('LUNIXI_CREDENTIAL_HISTORY', null, null, $shopId) ?: '[]'), true, 64, JSON_THROW_ON_ERROR);
        if (!is_array($history)) {
            throw new \RuntimeException('Credential history is invalid.');
        }
        foreach (array_reverse($history) as $ciphertext) {
            $previous = json_decode(self::box()->decrypt((string) $ciphertext), true, 16, JSON_THROW_ON_ERROR);
            if (
                is_array($previous) && ($previous['merchantId'] ?? null) === $current['merchantId'] && is_string($previous['webhookSecret'] ?? null) &&
                !in_array($previous['webhookSecret'], $secrets, true)
            ) {
                $secrets[] = $previous['webhookSecret'];
            }
            if (count($secrets) >= 3) {
                break;
            }
        }
        return $secrets;
    }

    public static function client(int $shopId): LunixiClient
    {
        $data = self::secrets($shopId);
        return LunixiClient::create(['baseUrl' => self::GATEWAY, 'keyId' => $data['keyId'], 'privateKey' => $data['privateKey']]);
    }
}
