<?php

declare(strict_types=1);

namespace Lunixi\PrestaShop;

use Lunixi\PrestaShop\Vendor\Lunixi\Sdk\Commerce\PdoLedgerStore;

/** All financial writes use PrestaShop's existing primary PDO connection. */
final class Storage
{
    public static function pdo(): \PDO
    {
        $db = \Db::getInstance()->getLink();
        if (!$db instanceof \PDO || $db->getAttribute(\PDO::ATTR_DRIVER_NAME) !== 'mysql') {
            throw new \RuntimeException('The module requires PrestaShop PDO MySQL.');
        }
        $db->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        return $db;
    }

    public static function ledger(): PdoLedgerStore
    {
        return new PdoLedgerStore(self::pdo(), _DB_PREFIX_ . 'lunixi_');
    }

    public static function install(): void
    {
        // Hooks and stock/invoice writes must participate in the same transaction.
        $q = self::pdo()->prepare('SELECT TABLE_NAME, ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND LEFT(TABLE_NAME, ?) = ?');
        $q->execute([strlen(_DB_PREFIX_), _DB_PREFIX_]);
        foreach ($q->fetchAll(\PDO::FETCH_ASSOC) as $table) {
            if (strtoupper((string) $table['ENGINE']) !== 'INNODB') {
                throw new \RuntimeException('All shop tables must use InnoDB.');
            }
        }
        self::ledger()->install();
        self::pdo()->exec('CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'lunixi_operations` (operation_id CHAR(48) PRIMARY KEY, id_order INT UNSIGNED NOT NULL,'
            . ' action VARCHAR(16) NOT NULL, amount BIGINT NULL, created_at BIGINT NOT NULL) ENGINE=InnoDB');
        self::pdo()->exec('CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'lunixi_mail` (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, payload MEDIUMTEXT NOT NULL,'
            . ' sent_at BIGINT NULL, created_at BIGINT NOT NULL) ENGINE=InnoDB');
    }

    public static function operation(int $orderId, string $operationId, string $action, ?int $amount): string
    {
        if (!preg_match('/^[a-f0-9]{48}$/D', $operationId) || !in_array($action, ['refund', 'capture', 'void'], true)) {
            throw new \InvalidArgumentException('Invalid native operation.');
        }
        $pdo = self::pdo();
        $pdo->beginTransaction();
        try {
            $q = $pdo->prepare('INSERT IGNORE INTO `' . _DB_PREFIX_ . 'lunixi_operations` (operation_id,id_order,action,amount,created_at) VALUES (?,?,?,?,?)');
            $q->execute([$operationId, $orderId, $action, $amount, time()]);
            $q = $pdo->prepare('SELECT * FROM `' . _DB_PREFIX_ . 'lunixi_operations` WHERE operation_id = ? FOR UPDATE');
            $q->execute([$operationId]);
            $row = $q->fetch(\PDO::FETCH_ASSOC);
            if (!$row || (int) $row['id_order'] !== $orderId || $row['action'] !== $action || ($row['amount'] === null ? null : (int) $row['amount']) !== $amount) {
                throw new \RuntimeException('Operation identity was already bound to different parameters.');
            }
            $pdo->commit();
            return 'prestashop-operation:' . $operationId;
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            } throw $e;
        }
    }

    /**
     * @param array<string,mixed>|null $record
     * @return array<string,mixed>|null
     */
    public static function unresolved(int $orderId, ?array $record): ?array
    {
        $q = self::pdo()->prepare('SELECT * FROM `' . _DB_PREFIX_ . 'lunixi_operations` WHERE id_order = ? ORDER BY created_at DESC');
        $q->execute([$orderId]);
        foreach ($q->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $hash = hash('sha256', 'prestashop-operation:' . $row['operation_id']);
            $op = $record['operations'][$hash] ?? null;
            if (is_array($op) && ($op['state'] ?? '') !== 'confirmed') {
                return $row;
            }
        }
        return null;
    }

    /** @return array<string,mixed>|null */
    public static function attempt(string $key): ?array
    {
        $store = self::ledger();
        return $store->transaction($key, static function () use ($store, $key): ?array {
            return $store->load($key);
        });
    }
}
