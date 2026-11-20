<?php

declare(strict_types=1);

namespace EasySeller\SiteSdk\Store;

use EasySeller\SiteSdk\Contract\Storage;

/**
 * Хранилище на PDO: MySQL, MariaDB или SQLite. Таблицы с приставкой (по умолчанию `easyseller_`) создаёт `install()`.
 * Замки: в MySQL `GET_LOCK`, в SQLite файлом во временной папке.
 */
final class PdoStorage implements Storage
{
    private string $driver;
    /** @var array<string, resource> */
    private array $files = [];

    public function __construct(private readonly \PDO $pdo, private readonly string $prefix = 'easyseller_')
    {
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->driver = (string)$this->pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);
    }

    /** Создать таблицы, если их нет. Повтор безопасен. */
    public function install(): void
    {
        $id = $this->driver === 'sqlite' ? 'INTEGER PRIMARY KEY AUTOINCREMENT' : 'BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY';
        $blob = $this->driver === 'sqlite' ? 'BLOB' : 'LONGBLOB';
        $tail = $this->driver === 'sqlite' ? '' : ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4';
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS {$this->prefix}state (name VARCHAR(64) NOT NULL PRIMARY KEY, value TEXT NOT NULL){$tail}");
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS {$this->prefix}applied (change_id BIGINT NOT NULL PRIMARY KEY, result TEXT NOT NULL, created_at BIGINT NOT NULL){$tail}");
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS {$this->prefix}outbox (id {$id}, kind VARCHAR(16) NOT NULL, entity VARCHAR(64) NOT NULL,
            version INT NOT NULL DEFAULT 1, attempts INT NOT NULL DEFAULT 0, next_at BIGINT NULL, sent_at BIGINT NULL, error VARCHAR(255) NULL,
            created_at BIGINT NOT NULL){$tail}");
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS {$this->prefix}document (order_id VARCHAR(64) NOT NULL, kind VARCHAR(32) NOT NULL,
            content_type VARCHAR(64) NOT NULL, bytes {$blob} NOT NULL, created_at BIGINT NOT NULL, PRIMARY KEY (order_id, kind)){$tail}");
        try {
            $this->pdo->exec("CREATE INDEX {$this->prefix}outbox_due ON {$this->prefix}outbox (sent_at, next_at)");
        } catch (\PDOException) {
            // индекс уже есть
        }
    }

    public function getState(string $key): ?string
    {
        $st = $this->pdo->prepare("SELECT value FROM {$this->prefix}state WHERE name = ?");
        $st->execute([$key]);
        $v = $st->fetchColumn();
        return $v === false ? null : (string)$v;
    }

    public function setState(string $key, string $value): void
    {
        $this->upsert("{$this->prefix}state", ['name' => $key, 'value' => $value], ['value']);
    }

    public function applied(int $changeId): ?array
    {
        $st = $this->pdo->prepare("SELECT result FROM {$this->prefix}applied WHERE change_id = ?");
        $st->execute([$changeId]);
        $v = $st->fetchColumn();
        $r = $v === false ? null : json_decode((string)$v, true);
        return is_array($r) ? $r : null;
    }

    public function saveApplied(int $changeId, array $result): void
    {
        $this->upsert("{$this->prefix}applied", ['change_id' => $changeId, 'result' => (string)json_encode($result, JSON_UNESCAPED_UNICODE), 'created_at' => time()], ['result']);
    }

    public function push(string $kind, string $entity): void
    {
        $st = $this->pdo->prepare("UPDATE {$this->prefix}outbox SET version = version + 1 WHERE kind = ? AND entity = ? AND sent_at IS NULL AND next_at IS NOT NULL");
        $st->execute([$kind, $entity]);
        if ($st->rowCount() === 0) {
            $this->pdo->prepare("INSERT INTO {$this->prefix}outbox (kind, entity, version, attempts, next_at, created_at) VALUES (?, ?, 1, 0, ?, ?)")
                ->execute([$kind, $entity, time(), time()]);
        }
    }

    public function due(int $limit): array
    {
        $st = $this->pdo->prepare("SELECT id, kind, entity, version, attempts, created_at FROM {$this->prefix}outbox
            WHERE sent_at IS NULL AND next_at IS NOT NULL AND next_at <= ? ORDER BY id LIMIT " . max(1, $limit));
        $st->execute([time()]);
        return array_map(fn(array $r) => ['id' => (int)$r['id'], 'kind' => (string)$r['kind'], 'entity' => (string)$r['entity'],
            'version' => (int)$r['version'], 'attempts' => (int)$r['attempts'], 'created_at' => (int)$r['created_at']], $st->fetchAll(\PDO::FETCH_ASSOC));
    }

    public function sent(int $rowId, int $version): void
    {
        // счётчик вырос, пока шла отправка: строка остаётся, следующий проход отправит свежее состояние
        $this->pdo->prepare("UPDATE {$this->prefix}outbox SET sent_at = ?, next_at = NULL, error = NULL, attempts = attempts + 1 WHERE id = ? AND version = ?")
            ->execute([time(), $rowId, $version]);
    }

    public function retry(int $rowId, int $attempts, int $nextAt, string $error): void
    {
        $this->pdo->prepare("UPDATE {$this->prefix}outbox SET attempts = ?, next_at = ?, error = ? WHERE id = ?")
            ->execute([$attempts, $nextAt, mb_substr($error, 0, 250), $rowId]);
    }

    public function close(int $rowId, string $error): void
    {
        $this->pdo->prepare("UPDATE {$this->prefix}outbox SET next_at = NULL, error = ?, attempts = attempts + 1 WHERE id = ?")
            ->execute([mb_substr($error, 0, 250), $rowId]);
    }

    public function minPendingOrder(array $except = []): ?string
    {
        // закрытая отказом строка держит отметку, пока тот же заказ не отправлен позже
        $t = "{$this->prefix}outbox";
        $not = $except === [] ? '' : ' AND o.entity NOT IN (' . implode(', ', array_fill(0, count($except), '?')) . ')';
        $st = $this->pdo->prepare("SELECT MIN(CAST(o.entity AS DECIMAL(20,0))) FROM {$t} o WHERE o.kind = 'order' AND o.sent_at IS NULL{$not}
            AND NOT EXISTS (SELECT 1 FROM {$t} s WHERE s.kind = 'order' AND s.entity = o.entity AND s.sent_at IS NOT NULL AND s.id > o.id)");
        $st->execute(array_values(array_map('strval', $except)));
        $v = $st->fetchColumn();
        return $v === null || $v === false ? null : (string)(int)$v;
    }

    public function pending(string $kind): bool
    {
        $st = $this->pdo->prepare("SELECT 1 FROM {$this->prefix}outbox WHERE kind = ? AND sent_at IS NULL AND next_at IS NOT NULL LIMIT 1");
        $st->execute([$kind]);
        return $st->fetchColumn() !== false;
    }

    public function putDocument(string $orderId, string $kind, string $bytes, string $contentType): void
    {
        $this->pdo->prepare("DELETE FROM {$this->prefix}document WHERE order_id = ? AND kind = ?")->execute([$orderId, $kind]);
        $st = $this->pdo->prepare("INSERT INTO {$this->prefix}document (order_id, kind, content_type, bytes, created_at) VALUES (?, ?, ?, ?, ?)");
        $st->bindValue(1, $orderId);
        $st->bindValue(2, $kind);
        $st->bindValue(3, $contentType);
        $st->bindValue(4, $bytes, \PDO::PARAM_LOB);
        $st->bindValue(5, time(), \PDO::PARAM_INT);
        $st->execute();
    }

    public function documents(string $orderId): array
    {
        $st = $this->pdo->prepare("SELECT kind, bytes, content_type FROM {$this->prefix}document WHERE order_id = ?");
        $st->execute([$orderId]);
        return array_map(fn(array $r) => ['kind' => (string)$r['kind'], 'bytes' => is_resource($r['bytes']) ? (string)stream_get_contents($r['bytes']) : (string)$r['bytes'],
            'content_type' => (string)$r['content_type']], $st->fetchAll(\PDO::FETCH_ASSOC));
    }

    public function cleanup(int $days): void
    {
        $limit = time() - $days * 86400;
        $this->pdo->prepare("DELETE FROM {$this->prefix}outbox WHERE (sent_at IS NOT NULL AND sent_at < ?) OR (sent_at IS NULL AND next_at IS NULL AND created_at < ?)")->execute([$limit, $limit]);
        $this->pdo->prepare("DELETE FROM {$this->prefix}applied WHERE created_at < ?")->execute([$limit]);
        $this->pdo->prepare("DELETE FROM {$this->prefix}document WHERE created_at < ?")->execute([time() - 30 * 86400]);
    }

    public function lock(string $name, int $wait): bool
    {
        if ($this->driver === 'mysql') {
            $st = $this->pdo->prepare('SELECT GET_LOCK(?, ?)');
            $st->execute([$this->prefix . $name, $wait]);
            return (int)$st->fetchColumn() === 1;
        }
        $f = fopen(sys_get_temp_dir() . '/' . $this->prefix . preg_replace('/\W/', '_', $name) . '.lock', 'c');
        if ($f === false) {
            return false;
        }
        $until = microtime(true) + $wait;
        do {
            if (flock($f, LOCK_EX | LOCK_NB)) {
                $this->files[$name] = $f;
                return true;
            }
            usleep(200000);
        } while (microtime(true) < $until);
        fclose($f);
        return false;
    }

    public function unlock(string $name): void
    {
        if ($this->driver === 'mysql') {
            $this->pdo->prepare('SELECT RELEASE_LOCK(?)')->execute([$this->prefix . $name]);
            return;
        }
        if (isset($this->files[$name])) {
            flock($this->files[$name], LOCK_UN);
            fclose($this->files[$name]);
            unset($this->files[$name]);
        }
    }

    /** @param array<string, mixed> $row */
    private function upsert(string $table, array $row, array $update): void
    {
        $cols = implode(', ', array_keys($row));
        $marks = implode(', ', array_fill(0, count($row), '?'));
        if ($this->driver === 'sqlite') {
            $key = array_key_first($row);
            $set = implode(', ', array_map(fn($c) => "{$c} = excluded.{$c}", $update));
            $sql = "INSERT INTO {$table} ({$cols}) VALUES ({$marks}) ON CONFLICT({$key}) DO UPDATE SET {$set}";
        } else {
            $set = implode(', ', array_map(fn($c) => "{$c} = VALUES({$c})", $update));
            $sql = "INSERT INTO {$table} ({$cols}) VALUES ({$marks}) ON DUPLICATE KEY UPDATE {$set}";
        }
        $this->pdo->prepare($sql)->execute(array_values($row));
    }
}
