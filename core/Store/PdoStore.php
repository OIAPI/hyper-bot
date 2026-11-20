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
 *
 * 连接生命周期（两类失效都必须处理，缺一个就会出现「跑久了必须重启」）：
 *
 *  1. 跨进程失效：App 在 Swoole master 里建立的连接不能给 fork 出来的 worker
 *     共用。这里记录创建 PID，PID 变了就用 factory 重连并补建表。
 *
 *  2. 空闲超时失效：MySQL 的 wait_timeout（默认 8 小时）到了、服务端重启、
 *     网络闪断，都会让**长连接**在客户端毫无感知的情况下死掉，之后每次查询都报
 *       SQLSTATE[HY000] General error: 2006 MySQL server has gone away
 *     （或 2013 Lost connection）。
 *     这类错误是永久的：PDO 不会自己重连，进程不复位就一直报错到重启为止 ——
 *     这正是「长时间运行后全部数据操作失败、重启才恢复」的根因。
 *     处理方式与 RedisStore 对齐：单次操作命中连接类错误就丢弃连接、用 factory
 *     重建、重试一次；仍然失败才把异常抛给调用方。
 *
 * 重试的安全性：kv 是「按 key 覆盖」的幂等语义（set 是 upsert，del/zrem 按值删），
 * 读操作天然幂等；incr/zadd 各自在**独立事务**里完成，重连后整段重跑等价于
 * 在干净连接上重新执行一次，不会读到半途状态。
 */
final class PdoStore implements Store
{
    /**
     * 需要重连的 MySQL 驱动错误码：
     *  2002 连接不上、2006 server has gone away、2013 lost connection、
     *  2055 连接丢失。SQLSTATE 以 08 开头的连接类异常同样按断线处理
     *  （PostgreSQL 断开、admin shutdown 等都会落在这个类里）。
     */
    private const LOST_DRIVER_CODES = [2002, 2006, 2013, 2055];

    /** 没有 errorInfo 时（少数驱动/场景）用消息兜底匹配 */
    private const LOST_MESSAGE_PATTERN = '/server has gone away|lost connection|gone away|'
        . 'server closed the connection|no connection to the server|broken pipe|'
        . 'connection (?:was )?(?:reset|refused|closed)|ECONNRESET|EOF detected/i';

    private ?PDO $pdo;
    private ?\Closure $factory;
    private int $pid;
    private string $prefix;
    private string $kv;
    private string $z;

    public function __construct(PDO $pdo, string $prefix = 'hyper_', ?\Closure $factory = null)
    {
        $this->pdo = $pdo;
        $this->prefix = $prefix;
        $this->factory = $factory;
        $this->pid = getmypid() ?: 0;
        $safe = preg_replace('/[^A-Za-z0-9_]/', '', $prefix) ?: 'hyper_';
        $this->kv = $safe . 'kv';
        $this->z = $safe . 'z';
        $this->configure();
        $this->init();
    }

    public static function fromDsn(string $dsn, string $user = '', string $password = '', array $options = [], string $prefix = 'hyper_'): self
    {
        $options += [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ];
        $pdo = new PDO($dsn, $user, $password, $options);
        $factory = static function () use ($dsn, $user, $password, $options): PDO {
            return new PDO($dsn, $user, $password, $options);
        };
        return new self($pdo, $prefix, $factory);
    }

    /** 当前进程可用的 PDO：PID 变化或连接被丢弃时自动重建 */
    private function pdo(): PDO
    {
        $pid = getmypid() ?: 0;
        if ($this->pdo !== null && $this->pid === $pid) {
            return $this->pdo;
        }
        if ($this->factory === null) {
            throw new \RuntimeException($this->pdo === null
                ? 'PDO 连接不可用，且没有可用的重连工厂'
                : 'PDO 连接已跨进程失效，且没有可用的重连工厂');
        }
        return $this->reconnect();
    }

    /** 用 factory 重建连接，并重新应用属性 + 补建表 */
    private function reconnect(): PDO
    {
        $this->pdo = ($this->factory)();
        $this->pid = getmypid() ?: 0;
        $this->configure();
        $this->init();
        return $this->pdo;
    }

    /**
     * 丢弃当前连接，下次 pdo() 时重建。
     *
     * 没有 factory 时（外部直接 new PDO 传入）无法重建，保持原连接不动 ——
     * 强行把 pid 置 0 反而会让下一次 pdo() 误报「跨进程失效」。
     */
    private function reset(): void
    {
        if ($this->factory === null) {
            return;
        }
        $this->pdo = null;
        $this->pid = 0;
    }

    /**
     * 统一执行入口：命中连接类错误就重连重试一次。
     *
     * @template T
     * @param callable(PDO):T $fn
     * @return T
     */
    private function run(callable $fn): mixed
    {
        try {
            return $fn($this->pdo());
        } catch (\Throwable $e) {
            if (!$this->isConnectionLost($e)) {
                throw $e;
            }
            $this->reset();
            $this->logReconnect($e);
            // 重连后重试一次；这次再失败就直接抛出，不做无限重试
            return $fn($this->pdo());
        }
    }

    /** 是否为「连接已断、重连即可恢复」的错误 */
    private function isConnectionLost(\Throwable $e): bool
    {
        if (!$e instanceof \PDOException) {
            return false;
        }
        $sqlState = '';
        $driverCode = 0;
        $info = $e->errorInfo;
        if (is_array($info)) {
            $sqlState = (string)($info[0] ?? '');
            $driverCode = (int)($info[1] ?? 0);
        }
        if ($sqlState === '') {
            $sqlState = (string)$e->getCode();
        }
        // SQLSTATE 08 类 = connection exception
        if (str_starts_with($sqlState, '08')) {
            return true;
        }
        if (in_array($driverCode, self::LOST_DRIVER_CODES, true)) {
            return true;
        }
        // errorInfo 偶尔为空（驱动/构造方式不同），从消息里补一次驱动码：
        // 形如 "SQLSTATE[HY000]: General error: 2006 MySQL server has gone away"
        if (preg_match('/General error:\s*(\d+)/i', $e->getMessage(), $m)
            && in_array((int)$m[1], self::LOST_DRIVER_CODES, true)) {
            return true;
        }
        return (bool)preg_match(self::LOST_MESSAGE_PATTERN, $e->getMessage());
    }

    /** 重连日志：断线是运维需要看见的事件，但不能让它把业务异常吞掉 */
    private function logReconnect(\Throwable $e): void
    {
        try {
            \Utils\Logger::warning('[store] 数据库连接已断开，已重建连接并重试：' . $e->getMessage());
        } catch (\Throwable) {
            // 日志组件自身异常不影响存储恢复
        }
    }

    private function configure(): void
    {
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        // 不能依赖 php.ini / DSN 的默认值：有的环境 PDO 默认是 FETCH_NUM，
        // 于是 $row['k'] / $row['v'] 全是 Undefined array key。
        $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        // MySQL 驱动在某些 PDO 版本/配置下默认不缓冲；一条语句没取完就执行下一条
        // 会报 2014 Cannot execute queries while other unbuffered queries are active。
        if (defined('PDO::MYSQL_ATTR_USE_BUFFERED_QUERY') && $this->driver() === 'mysql') {
            try {
                $this->pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, true);
            } catch (\Throwable) {
                // 驱动不支持该属性时忽略，查询处的 closeCursor/fetchAll 仍然生效
            }
        }
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

    /** 读一行（不含 TTL 判定），供 get/incr 复用 */
    private function fetchRow(PDO $pdo, string $key): ?array
    {
        $stmt = $pdo->prepare("SELECT v, expires_at FROM {$this->kv} WHERE k = ?");
        $stmt->execute([$key]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $stmt->closeCursor();
        return is_array($row) ? $row : null;
    }

    /** 写一行（upsert），供 set/incr 复用 */
    private function writeValue(PDO $pdo, string $key, string $payload, ?int $expires): bool
    {
        if ($this->driver() === 'mysql') {
            $sql = "INSERT INTO {$this->kv} (k, v, expires_at) VALUES (?, ?, ?)
                    ON DUPLICATE KEY UPDATE v = VALUES(v), expires_at = VALUES(expires_at)";
        } else {
            $sql = "INSERT INTO {$this->kv} (k, v, expires_at) VALUES (?, ?, ?)
                    ON CONFLICT(k) DO UPDATE SET v = excluded.v, expires_at = excluded.expires_at";
        }
        $stmt = $pdo->prepare($sql);
        return $stmt->execute([$key, $payload, $expires]);
    }

    public function get(string $key): mixed
    {
        $row = $this->run(fn(PDO $pdo): ?array => $this->fetchRow($pdo, $key));
        if ($row === null) {
            return null;
        }
        $expiresAt = $row['expires_at'] ?? null;
        if ($expiresAt !== null && (int)$expiresAt > 0 && (int)$expiresAt <= time()) {
            $this->del($key);
            return null;
        }
        $value = $row['v'] ?? null;
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
        return (bool)$this->run(fn(PDO $pdo): bool => $this->writeValue($pdo, $key, $payload, $expires));
    }

    public function del(string ...$keys): int
    {
        $keys = array_values(array_filter($keys, static fn($v) => $v !== ''));
        if ($keys === []) {
            return 0;
        }
        $marks = implode(',', array_fill(0, count($keys), '?'));
        return (int)$this->run(function (PDO $pdo) use ($keys, $marks): int {
            $stmt = $pdo->prepare("DELETE FROM {$this->kv} WHERE k IN ({$marks})");
            $stmt->execute($keys);
            return $stmt->rowCount();
        });
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
        $rows = $this->run(function (PDO $pdo) use ($like): array {
            $stmt = $pdo->prepare("SELECT k FROM {$this->kv} WHERE k LIKE ? ESCAPE '!' AND (expires_at IS NULL OR expires_at = 0 OR expires_at > ?)");
            $stmt->execute([$like, time()]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $stmt->closeCursor();
            return is_array($rows) ? $rows : [];
        });
        return array_values(array_map(static fn($row) => (string)($row['k'] ?? ''), $rows));
    }

    public function incr(string $key, int $by = 1): int
    {
        return (int)$this->run(function (PDO $pdo) use ($key, $by): int {
            $pdo->beginTransaction();
            try {
                $row = $this->fetchRow($pdo, $key);
                $current = $row['v'] ?? null;
                $next = (is_numeric($current) ? (int)$current : 0) + $by;
                $this->writeValue($pdo, $key, (string)$next, null);
                $pdo->commit();
                return $next;
            } catch (\Throwable $e) {
                // 连接已死时 inTransaction()/rollBack() 自身也会抛，整体忽略：
                // 服务端连接断开后事务本来就已回滚。
                try {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                } catch (\Throwable) {
                }
                throw $e;
            }
        });
    }

    public function zadd(string $key, float $score, string $member): bool
    {
        return (bool)$this->run(function (PDO $pdo) use ($key, $score, $member): bool {
            $pdo->beginTransaction();
            try {
                $stmt = $pdo->prepare("DELETE FROM {$this->z} WHERE k = ? AND member = ?");
                $stmt->execute([$key, $member]);
                $stmt = $pdo->prepare("INSERT INTO {$this->z} (k, member, score) VALUES (?, ?, ?)");
                $stmt->execute([$key, $member, $score]);
                $pdo->commit();
                return true;
            } catch (\Throwable $e) {
                try {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                } catch (\Throwable) {
                }
                throw $e;
            }
        });
    }

    public function zrange(string $key, int $start, int $stop, bool $rev = false): array
    {
        $start = max(0, $start);
        $stop = max(0, $stop);
        $limit = max(0, $stop - $start + 1);
        $order = $rev ? 'DESC' : 'ASC';
        $rows = $this->run(function (PDO $pdo) use ($key, $limit, $start, $order): array {
            $stmt = $pdo->prepare("SELECT member FROM {$this->z} WHERE k = ? ORDER BY score {$order} LIMIT ? OFFSET ?");
            $stmt->bindValue(1, $key);
            $stmt->bindValue(2, $limit, PDO::PARAM_INT);
            $stmt->bindValue(3, $start, PDO::PARAM_INT);
            $stmt->execute();
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $stmt->closeCursor();
            return is_array($rows) ? $rows : [];
        });
        return array_values(array_map(static fn($row) => (string)($row['member'] ?? ''), $rows));
    }

    public function zrem(string $key, string ...$members): int
    {
        $members = array_values(array_filter($members, static fn($v) => $v !== ''));
        if ($members === []) {
            return 0;
        }
        $marks = implode(',', array_fill(0, count($members), '?'));
        return (int)$this->run(function (PDO $pdo) use ($key, $members, $marks): int {
            $stmt = $pdo->prepare("DELETE FROM {$this->z} WHERE k = ? AND member IN ({$marks})");
            $stmt->execute(array_merge([$key], $members));
            return $stmt->rowCount();
        });
    }

    public function zcard(string $key): int
    {
        return (int)$this->run(function (PDO $pdo) use ($key): int {
            $stmt = $pdo->prepare("SELECT COUNT(*) AS c FROM {$this->z} WHERE k = ?");
            $stmt->execute([$key]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            $stmt->closeCursor();
            return (int)($row['c'] ?? 0);
        });
    }

    public function ztrim(string $key, int $keep): int
    {
        // 刻意不调用 zcard()/zrem()：那会嵌套 run()，一旦内层重连，
        // 外层闭包手里的 $pdo 就成了失效连接。这里在同一条连接上做完。
        return (int)$this->run(function (PDO $pdo) use ($key, $keep): int {
            $stmt = $pdo->prepare("SELECT COUNT(*) AS c FROM {$this->z} WHERE k = ?");
            $stmt->execute([$key]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            $stmt->closeCursor();
            $total = (int)($row['c'] ?? 0);
            if ($total <= $keep) {
                return 0;
            }
            $stmt = $pdo->prepare("SELECT member FROM {$this->z} WHERE k = ? ORDER BY score ASC LIMIT ?");
            $stmt->bindValue(1, $key);
            $stmt->bindValue(2, max(0, $total - $keep), PDO::PARAM_INT);
            $stmt->execute();
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $stmt->closeCursor();
            $members = array_values(array_filter(
                array_map(static fn($r) => (string)($r['member'] ?? ''), is_array($rows) ? $rows : []),
                static fn(string $m): bool => $m !== ''
            ));
            if ($members === []) {
                return 0;
            }
            $marks = implode(',', array_fill(0, count($members), '?'));
            $stmt = $pdo->prepare("DELETE FROM {$this->z} WHERE k = ? AND member IN ({$marks})");
            $stmt->execute(array_merge([$key], $members));
            return $stmt->rowCount();
        });
    }
}
