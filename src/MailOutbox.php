<?php

declare(strict_types=1);

namespace Lunixi\PrestaShop;

/** Durable encrypted notifications: inserted with the native order transaction. */
final class MailOutbox
{
    /** @param array<string,mixed> $parameters */
    public static function capture(array $parameters): void
    {
        self::enqueue(['type' => 'mail', 'parameters' => $parameters]);
    }
    public static function history(int $historyId, int $orderId): void
    {
        self::enqueue(['type' => 'history', 'historyId' => $historyId, 'orderId' => $orderId]);
    }
    /** @param array<string,mixed> $value */
    private static function enqueue(array $value): void
    {
        if (!Storage::pdo()->inTransaction()) {
            throw new \RuntimeException('Mail queue requires the native transaction.');
        }
        $q = Storage::pdo()->prepare('INSERT INTO `' . _DB_PREFIX_ . 'lunixi_mail` (payload,created_at) VALUES (?,?)');
        $q->execute([Settings::box()->encrypt(json_encode($value, JSON_THROW_ON_ERROR)), time()]);
    }
    /** CLI-only worker. At-least-once email delivery; payment changes are never repeated. */
    public static function drain(int $limit = 50): int
    {
        $pdo = Storage::pdo();
        $sent = 0;
        while ($sent < $limit) {
            $pdo->beginTransaction();
            try {
                $select = $pdo->prepare('SELECT * FROM `' . _DB_PREFIX_ . 'lunixi_mail` WHERE sent_at IS NULL ORDER BY id LIMIT 1 FOR UPDATE');
                $select->execute();
                $row = $select->fetch(\PDO::FETCH_ASSOC);
                if (!$row) {
                    $pdo->commit();
                    break;
                }
                $value = json_decode(Settings::box()->decrypt($row['payload']), true, 32, JSON_THROW_ON_ERROR);
                if ($value['type'] === 'history') {
                    $ok = (new \OrderHistory((int) $value['historyId']))->sendEmail(new \Order((int) $value['orderId']));
                } else {
                    $p = $value['parameters'];
                    $ok = \Mail::Send(
                        $p['idLang'],
                        $p['template'],
                        $p['subject'],
                        $p['templateVars'],
                        $p['to'],
                        $p['toName'],
                        $p['from'],
                        $p['fromName'],
                        $p['fileAttachment'],
                        $p['mode_smtp'],
                        $p['templatePath'],
                        false,
                        $p['idShop'],
                        $p['bcc'],
                        $p['replyTo'],
                        $p['replyToName']
                    );
                }
                if (!$ok) {
                    throw new \RuntimeException('Deferred notification failed.');
                }
                $pdo->prepare('UPDATE `' . _DB_PREFIX_ . 'lunixi_mail` SET sent_at = ? WHERE id = ?')->execute([time(), $row['id']]);
                $pdo->commit();
                ++$sent;
            } catch (\Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                } throw $e;
            }
        }
        return $sent;
    }
}
