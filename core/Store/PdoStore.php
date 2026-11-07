<?php
namespace Core\Store;

use PDO;

/**
 * 关系型数据库存储（MySQL / PostgreSQL / SQLite）。
 *
 * 和 RedisStore 保持完全一致的值语义：
 *  - set 传字符串原样存，传数组/对象/数字则 JSON 编码；
 *  - get 能 JSON 解码就解码，否则返回原字符串。
 *
 * 表结构是通用的 key-value + 有序集合，不需要业务表迁移：
 *   {prefix}kv(k, v, expires_at)
 *   {prefix}z(id, k, member, score)
 */
final class PdoStore implements Store
{
    private string $kv;
    private string $z;

    public function __construct(
        private readonly PDO $pdo,
        private readonly string $prefix = 'hyper_',
    ) {
        $safe = preg_replace('/[^A-Za-z0-9_]/', '', $prefix) ?: 'hyper_';
        $this->kv = $safe . 'kv';
        $this->z  = $safe . 'z';
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->init();
    }

    public static function fromDsn(string $dsn, string $user = '', string $password = '', array $options = [], string $prefix = 'hyper_'): self
    {
        $pdo = new PDO($dsn, $user, $password, $options + [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        return new self($pdo, $prefix);
    }

    private function driver(): string
    {
        return strtolower((string)$this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME));
    }

    private function init(): void
    {
        $driver = $this->driver();
        if ($driver === 'mysql') {
            $this->pdo->exec("CREATE TABLE IF NOT EXISTS {$this->kv} (
                k VARCHAR(191) NOT NULL PRIMARY KEY,
                v LONGTEXT NULL,
                expires_at BIGINT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            $this->pdo->exec("CREATE TABLE IF NOT EXISTS {$this->z} (
                id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
                k VARCHAR(191) NOT NULL,
                member LONGTEXT NOT NULL,
                score DOUBLE NOT NULL,
                INDEX idx_z_k_score (k, score)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            return;
        }
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS {$this->kv} (
            k VARCHAR(191) NOT NULL PRIMARY KEY,
            v TEXT NULL,
            expires_at BIGINT NULL
        )");
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS {$this->z} (
            id " . ($driver === 'sqlite' ? 'INTEGER PRIMARY KEY AUTOINCREMENT' : 'BIGSERIAL PRIMARY KEY') . ",
            k VARCHAR(191) NOT NULL,
            member TEXT NOT NULL,
            score DOUBLE PRECISION NOT NULL
        )");
        if ($driver !== 'sqlite') {
            $this->pdo->exec("CREATE INDEX IF NOT EXISTS idx_{$this->z}_k_score ON {$this->z} (k, score)");
        }
    }

    public function get(string $key): mixed
    {
        $stmt = $this->pdo->prepare("SELECT v, expires_at FROM {$this->kv} WHERE k = ?");
        $stmt->execute([$key]);
        $row = $stmt->fetch();
        if (!is_array($row)) {
            return null;
        }
        if ($row['expires_at'] !== null && (int)$row['expires_at'] > 0 && (int)$row['expires_at'] <= time()) {
            $this->del($key);
            return null;
        }
        $value = $row['v'];
        if ($value === null) {
            return null;
        }
        $decoded = json_decode((string)$value, true);
        return json_last_error() === JSON_ERROR_NONE ? $decoded : $value;
    }

    public function set(string $key, mixed $value, int $ttl = 0): bool
    {
        $payload = is_string($value) ? $value : (string)json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $expires = $ttl > 0 ? time() + $ttl : null;
        if ($this->driver() === 'mysql') {
            $sql = "INSERT INTO {$this->kv} (k, v, expires_at) VALUES (?, ?, ?)
                    ON DUPLICATE KEY UPDATE v = VALUES(v), expires_at = VALUES(expires_at)";
        } else {
            $sql = "INSERT INTO {$this->kv} (k, v, expires_at) VALUES (?, ?, ?)
                    ON CONFLICT(k) DO UPDATE SET v = excluded.v, expires_at = excluded.expires_at";
        }
        $stmt = $this->pdo->prepare($sql);
        return $stmt->execute([$key, $payload, $expires]);
    }

    public function del(string ...$keys): int
    {
        $keys = array_values(array_filter($keys, static fn($v) => $v !== ''));
        if ($keys === []) {
            return 0;
        }
        $marks = implode(',', array_fill(0, count($keys), '?'));
        $stmt = $this->pdo->prepare("DELETE FROM {$this->kv} WHERE k IN ({$marks})");
        $stmt->execute($keys);
        return $stmt->rowCount();
    }

    public function has(string $key): bool
    {
        return $this->get($key) !== null;
    }

    public function keys(string $prefix = '*'): array
    {
        // 用 ! 作转义符，避免 MySQL/PgSQL 对反斜杠字面量的差异
        $like = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $prefix);
        $like = str_replace(['*', '?'], ['%', '_'], $like);
        $stmt = $this->pdo->prepare("SELECT k FROM {$this->kv} WHERE k LIKE ? ESCAPE '!' AND (expires_at IS NULL OR expires_at = 0 OR expires_at > ?)");
        $stmt->execute([$like, time()]);
        return array_values(array_map(static fn($row) => (string)$row['k'], $stmt->fetchAll()));
    }

    public function incr(string $key, int $by = 1): int
    {
        $this->pdo->beginTransaction();
        try {
            $current = $this->get($key);
            $next = (is_numeric($current) ? (int)$current : 0) + $by;
            $this->set($key, $next);
            $this->pdo->commit();
            return $next;
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    public function zadd(string $key, float $score, string $member): bool
    {
        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare("DELETE FROM {$this->z} WHERE k = ? AND member = ?");
            $stmt->execute([$key, $member]);
            $stmt = $this->pdo->prepare("INSERT INTO {$this->z} (k, member, score) VALUES (?, ?, ?)");
            $stmt->execute([$key, $member, $score]);
            $this->pdo->commit();
            return true;
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    public function zrange(string $key, int $start, int $stop, bool $rev = false): array
    {
        $start = max(0, $start);
        $stop  = max(0, $stop);
        $limit = max(0, $stop - $start + 1);
        $order = $rev ? 'DESC' : 'ASC';
        $stmt = $this->pdo->prepare("SELECT member FROM {$this->z} WHERE k = ? ORDER BY score {$order} LIMIT ? OFFSET ?");
        $stmt->bindValue(1, $key);
        $stmt->bindValue(2, $limit, PDO::PARAM_INT);
        $stmt->bindValue(3, $start, PDO::PARAM_INT);
        $stmt->execute();
        return array_values(array_map(static fn($row) => (string)$row['member'], $stmt->fetchAll()));
    }

    public function zrem(string $key, string ...$members): int
    {
        $members = array_values(array_filter($members, static fn($v) => $v !== ''));
        if ($members === []) {
            return 0;
        }
        $marks = implode(',', array_fill(0, count($members), '?'));
        $stmt = $this->pdo->prepare("DELETE FROM {$this->z} WHERE k = ? AND member IN ({$marks})");
        $stmt->execute(array_merge([$key], $members));
        return $stmt->rowCount();
    }

    public function zcard(string $key): int
    {
        $stmt = $this->pdo->prepare("SELECT COUNT(*) AS c FROM {$this->z} WHERE k = ?");
        $stmt->execute([$key]);
        return (int)($stmt->fetch()['c'] ?? 0);
    }

    public function ztrim(string $key, int $keep): int
    {
        $total = $this->zcard($key);
        if ($total <= $keep) {
            return 0;
        }
        $remove = $total - max(0, $keep);
        $stmt = $this->pdo->prepare("SELECT member FROM {$this->z} WHERE k = ? ORDER BY score ASC LIMIT ?");
        $stmt->bindValue(1, $key);
        $stmt->bindValue(2, $remove, PDO::PARAM_INT);
        $stmt->execute();
        $members = array_map(static fn($row) => (string)$row['member'], $stmt->fetchAll());
        return $this->zrem($key, ...$members);
    }
}
