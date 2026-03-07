<?php
declare(strict_types=1);

namespace yangweijie\orm\sqlite\remote\Server;

/**
 * 连接会话 - 管理单个 SQLite 连接
 * 
 * 支持配置 SQLite WAL 模式和并发控制参数
 */
class ConnectionSession
{
    private \SQLite3 $conn;
    private string $database;
    private int $lastActive;
    private bool $inTransaction = false;
    private int $transactionStart = 0;

    /** @var array WAL 配置 */
    private array $walConfig;

    public function __construct(\SQLite3 $conn, string $database, array $walConfig = [])
    {
        $this->conn = $conn;
        $this->database = $database;
        $this->lastActive = time();
        
        // 默认 WAL 配置
        $this->walConfig = array_merge([
            'busy_timeout' => 5000,        // 锁等待超时 (ms)
            'journal_mode' => 'WAL',       // 日志模式
            'wal_autocheckpoint' => 1000,  // 自动 checkpoint 阈值
            'synchronous' => 'NORMAL',     // 同步模式 (OFF/NORMAL/FULL)
            'transaction_timeout' => 60,   // 事务超时 (s)
        ], $walConfig);

        $this->applyWalConfig();
    }

    /** @var array<string> 允许的日志模式 */
    private const ALLOWED_JOURNAL_MODES = ['WAL', 'DELETE', 'TRUNCATE', 'MEMORY', 'OFF'];
    
    /** @var array<string> 允许的同步模式 */
    private const ALLOWED_SYNCHRONOUS_MODES = ['OFF', 'NORMAL', 'FULL'];
    
    /** @var array<string> 允许的 checkpoint 模式 */
    private const ALLOWED_CHECKPOINT_MODES = ['PASSIVE', 'FULL', 'RESTART', 'TRUNCATE'];

    /**
     * 应用 WAL 配置
     */
    private function applyWalConfig(): void
    {
        $config = $this->walConfig;

        // 设置锁等待超时（数值验证：0-60000ms）
        $busyTimeout = max(0, min(60000, (int)($config['busy_timeout'] ?? 5000)));
        if ($busyTimeout > 0) {
            $this->conn->exec("PRAGMA busy_timeout = {$busyTimeout}");
        }

        // 设置日志模式（白名单验证）
        $journalMode = strtoupper((string)($config['journal_mode'] ?? 'WAL'));
        if (!in_array($journalMode, self::ALLOWED_JOURNAL_MODES, true)) {
            $journalMode = 'WAL';
        }
        $this->conn->exec("PRAGMA journal_mode = {$journalMode}");

        // 设置 WAL 自动 checkpoint（数值验证：0-100000）
        $checkpoint = max(0, min(100000, (int)($config['wal_autocheckpoint'] ?? 1000)));
        if ($checkpoint > 0) {
            $this->conn->exec("PRAGMA wal_autocheckpoint = {$checkpoint}");
        }

        // 设置同步模式（白名单验证）
        $synchronous = strtoupper((string)($config['synchronous'] ?? 'NORMAL'));
        if (!in_array($synchronous, self::ALLOWED_SYNCHRONOUS_MODES, true)) {
            $synchronous = 'NORMAL';
        }
        $this->conn->exec("PRAGMA synchronous = {$synchronous}");
    }

    public function getDatabase(): string
    {
        return $this->database;
    }

    public function getLastActive(): int
    {
        return $this->lastActive;
    }

    public function touch(): void
    {
        $this->lastActive = time();
    }

    public function isInTransaction(): bool
    {
        return $this->inTransaction;
    }

    /**
     * 执行查询
     */
    public function query(string $sql): array
    {
        $this->touch();

        // 判断是否为 SELECT 语句
        $sqlUpper = strtoupper(trim($sql));
        $isSelect = str_starts_with($sqlUpper, 'SELECT') || 
                    str_starts_with($sqlUpper, 'PRAGMA') ||
                    str_starts_with($sqlUpper, 'EXPLAIN');

        if ($isSelect) {
            // SELECT 语句使用 query()
            $result = $this->conn->query($sql);
            $errno = $this->conn->lastErrorCode();

            if ($errno !== 0) {
                return ['errno' => $errno, 'error' => $this->conn->lastErrorMsg()];
            }

            if (!($result instanceof \SQLite3Result)) {
                return ['errno' => 0, 'rows' => [], 'fields' => [], 'num_rows' => 0];
            }

            $rows = [];
            $fields = [];

            $numFields = $result->numColumns();
            for ($i = 0; $i < $numFields; $i++) {
                $fields[] = $result->columnName($i);
            }

            while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
                $rows[] = $row;
            }

            $result->finalize();

            return [
                'errno' => 0,
                'rows' => $rows,
                'fields' => $fields,
                'num_rows' => count($rows),
            ];
        }

        // DDL/DML 语句使用 exec()
        $result = $this->conn->exec($sql);
        $errno = $this->conn->lastErrorCode();

        if (!$result || $errno !== 0) {
            return ['errno' => $errno, 'error' => $this->conn->lastErrorMsg()];
        }

        return [
            'errno' => 0,
            'affected_rows' => $this->conn->changes(),
            'insert_id' => $this->conn->lastInsertRowID(),
        ];
    }

    /**
     * 检查事务是否超时
     */
    public function isTransactionTimeout(): bool
    {
        if (!$this->inTransaction || $this->transactionStart === 0) {
            return false;
        }

        $timeout = $this->walConfig['transaction_timeout'] ?? 60;
        return (time() - $this->transactionStart) > $timeout;
    }

    /**
     * 开始事务
     */
    public function begin(): array
    {
        $this->touch();

        if ($this->inTransaction) {
            return ['errno' => 0, 'message' => 'Already in transaction'];
        }

        // 使用 IMMEDIATE 事务，立即获取写锁
        // 避免多个事务同时等待锁导致死锁
        $result = $this->conn->exec('BEGIN IMMEDIATE TRANSACTION');
        
        if (!$result) {
            return ['errno' => $this->conn->lastErrorCode(), 'error' => $this->conn->lastErrorMsg()];
        }

        $this->inTransaction = true;
        $this->transactionStart = time();

        return ['errno' => 0, 'message' => 'Transaction started'];
    }

    /**
     * 提交事务
     */
    public function commit(): array
    {
        $this->touch();

        if (!$this->inTransaction) {
            return ['errno' => 0, 'message' => 'No active transaction'];
        }

        // 检查事务是否超时
        if ($this->isTransactionTimeout()) {
            $this->conn->exec('ROLLBACK');
            $this->inTransaction = false;
            $this->transactionStart = 0;
            return ['errno' => 408, 'error' => 'Transaction timeout, auto rolled back'];
        }

        $result = $this->conn->exec('COMMIT');
        
        if (!$result) {
            return ['errno' => $this->conn->lastErrorCode(), 'error' => $this->conn->lastErrorMsg()];
        }

        $this->inTransaction = false;
        $this->transactionStart = 0;

        return ['errno' => 0, 'message' => 'Transaction committed'];
    }

    /**
     * 回滚事务
     */
    public function rollback(): array
    {
        $this->touch();

        if (!$this->inTransaction) {
            return ['errno' => 0, 'message' => 'No active transaction'];
        }

        $this->conn->exec('ROLLBACK');
        $this->inTransaction = false;
        $this->transactionStart = 0;

        return ['errno' => 0, 'message' => 'Transaction rolled back'];
    }

    /**
     * 执行 checkpoint（手动触发）
     */
    public function checkpoint(string $mode = 'PASSIVE'): array
    {
        $this->touch();

        // 白名单验证 checkpoint 模式
        $mode = strtoupper($mode);
        if (!in_array($mode, self::ALLOWED_CHECKPOINT_MODES, true)) {
            $mode = 'PASSIVE';
        }

        $result = $this->conn->exec("PRAGMA wal_checkpoint({$mode})");

        if (!$result) {
            return ['errno' => $this->conn->lastErrorCode(), 'error' => $this->conn->lastErrorMsg()];
        }

        return ['errno' => 0, 'message' => 'Checkpoint completed'];
    }

    /**
     * 关闭连接
     */
    public function close(): void
    {
        if ($this->inTransaction) {
            $this->conn->exec('ROLLBACK');
        }
        $this->conn->close();
    }
}
