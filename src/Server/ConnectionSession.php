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

    /**
     * 应用 WAL 配置
     */
    private function applyWalConfig(): void
    {
        $config = $this->walConfig;

        // 设置锁等待超时
        if ($config['busy_timeout'] > 0) {
            $this->conn->exec("PRAGMA busy_timeout = {$config['busy_timeout']}");
        }

        // 设置日志模式 (WAL/DELETE/TRUNCATE)
        if (!empty($config['journal_mode'])) {
            $this->conn->exec("PRAGMA journal_mode = {$config['journal_mode']}");
        }

        // 设置 WAL 自动 checkpoint
        if ($config['wal_autocheckpoint'] > 0) {
            $this->conn->exec("PRAGMA wal_autocheckpoint = {$config['wal_autocheckpoint']}");
        }

        // 设置同步模式
        if (!empty($config['synchronous'])) {
            $this->conn->exec("PRAGMA synchronous = {$config['synchronous']}");
        }
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

        // PASSIVE: 不阻塞，尽可能做 checkpoint
        // FULL: 阻塞等待所有读者完成
        // RESTART: 类似 FULL，完成后重启 WAL 文件
        // TRUNCATE: 类似 RESTART，并截断 WAL 文件
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
