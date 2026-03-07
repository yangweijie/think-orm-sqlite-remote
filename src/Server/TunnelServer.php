<?php
declare(strict_types=1);

namespace yangweijie\orm\sqlite\remote\Server;

use Psl\Async;
use Psl\DateTime\Duration;
use Psl\TCP;
use think\console\Output;

/**
 * SQLite Tunnel Socket 服务端
 * 
 * 使用 psl 实现异步 Socket 服务，管理 SQLite 连接池
 * 支持 HTTP Tunnel（Navicat）和 Socket（ThinkPHP）两种模式
 */
class TunnelServer
{
    protected string $host;
    protected int $port;
    protected int $maxConnections;
    protected int $idleTimeout;
    protected ?Output $output;

    /** @var array<string, ConnectionSession> */
    protected array $sessions = [];

    /** @var array<string, string> 认证配置 */
    protected array $authConfig = [];

    protected ?string $databaseRoot = null;

    /** @var array SQLite WAL 配置 */
    protected array $walConfig = [];

    /** @var bool 服务运行状态 */
    protected bool $running = true;

    public function __construct(
        string $host = '127.0.0.1',
        int $port = 9527,
        int $maxConnections = 100,
        int $idleTimeout = 300,
        ?Output $output = null
    ) {
        $this->host = $host;
        $this->port = $port;
        $this->maxConnections = $maxConnections;
        $this->idleTimeout = $idleTimeout;
        $this->output = $output;
    }

    /**
     * 设置 WAL 配置
     */
    public function setWalConfig(array $config): self
    {
        $this->walConfig = $config;
        return $this;
    }

    public function setAuth(string $username, string $password): self
    {
        $this->authConfig[$username] = $password;
        return $this;
    }

    public function setDatabaseRoot(string $path): self
    {
        $this->databaseRoot = realpath($path) ?: $path;
        return $this;
    }

    /**
     * 启动服务（使用 psl 异步）
     */
    public function start(): void
    {
        $this->log("Starting SQLite Tunnel Server on {$this->host}:{$this->port}");

        // 注册信号处理（优雅关闭）
        $this->registerSignalHandlers();

        // 使用 psl TCP 监听
        $listener = TCP\listen(
            $this->host,
            $this->port,
            no_delay: true,
            reuse_address: true,
        );

        $this->log("Server started, waiting for connections...");

        // 启动空闲连接清理协程
        Async\run(fn() => $this->cleanupIdleSessions());

        // 主循环接受连接
        while ($this->running) {
            try {
                $connection = $listener->accept();
                
                // 每个连接在独立协程中处理
                Async\run(fn() => $this->handleConnection($connection));
                
            } catch (\Throwable $e) {
                $this->log("Error accepting connection: " . $e->getMessage());
            }
        }

        // 优雅关闭
        $this->gracefulShutdown();
    }

    /**
     * 注册信号处理器
     */
    protected function registerSignalHandlers(): void
    {
        if (function_exists('pcntl_async_signals')) {
            pcntl_async_signals(true);
            pcntl_signal(SIGTERM, fn() => $this->shutdown());
            pcntl_signal(SIGINT, fn() => $this->shutdown());
        }
    }

    /**
     * 停止服务
     */
    public function shutdown(): void
    {
        $this->log("Received shutdown signal");
        $this->running = false;
    }

    /**
     * 优雅关闭所有连接
     */
    protected function gracefulShutdown(): void
    {
        $count = count($this->sessions);
        if ($count > 0) {
            $this->log("Shutting down, closing {$count} session(s)...");
            foreach ($this->sessions as $sessionId => $session) {
                try {
                    $session->close();
                } catch (\Throwable) {}
            }
            $this->sessions = [];
        }
        $this->log("Server stopped");
    }

    /**
     * 处理客户端连接
     */
    protected function handleConnection(TCP\StreamInterface $connection): void
    {
        $remoteAddr = 'unknown';
        try {
            $remoteAddr = $connection->getPeerAddress()->toString();
        } catch (\Throwable) {}
        
        $this->log("Client connected: {$remoteAddr}");

        try {
            while ($this->running) {
                // 读取消息长度（4字节网络字节序）
                $lengthData = $connection->read(4);
                if (strlen($lengthData) < 4) {
                    break; // 连接关闭
                }

                $length = unpack('N', $lengthData)[1];
                if ($length <= 0 || $length > 10 * 1024 * 1024) {
                    $this->sendError($connection, 400, 'Invalid message length');
                    continue;
                }

                // 读取消息内容
                $data = $connection->read($length);
                if (strlen($data) < $length) {
                    break;
                }

                $request = json_decode($data, true);
                if ($request === null) {
                    $this->sendError($connection, 400, 'Invalid JSON');
                    continue;
                }

                // 处理请求
                $response = $this->handleRequest($request);
                $this->sendMessage($connection, $response);
            }
        } catch (\Throwable $e) {
            $this->log("Connection error: " . $e->getMessage());
        } finally {
            $connection->close();
            $this->log("Client disconnected: {$remoteAddr}");
        }
    }

    /**
     * 处理请求
     */
    protected function handleRequest(array $request): array
    {
        // 认证检查
        if (!empty($this->authConfig)) {
            $username = $request['auth']['username'] ?? '';
            $password = $request['auth']['password'] ?? '';

            if (!isset($this->authConfig[$username]) ||
                !hash_equals($this->authConfig[$username], $password)) {
                return ['errno' => 401, 'error' => 'Authentication failed'];
            }
        }

        $action = $request['action'] ?? '';
        $sessionId = $request['session_id'] ?? '';
        $database = $request['database'] ?? '';
        $sql = $request['sql'] ?? '';

        try {
            return match ($action) {
                'connect' => $this->handleConnect($sessionId, $database),
                'query' => $this->handleQuery($sessionId, $database, $sql),
                'begin' => $this->handleBegin($sessionId, $database),
                'commit' => $this->handleCommit($sessionId),
                'rollback' => $this->handleRollback($sessionId),
                'disconnect' => $this->handleDisconnect($sessionId),
                'checkpoint' => $this->handleCheckpoint($sessionId, $request['mode'] ?? 'PASSIVE'),
                'ping' => ['errno' => 0, 'pong' => true],
                default => ['errno' => 400, 'error' => "Unknown action: {$action}"],
            };
        } catch (\Throwable $e) {
            // 记录详细错误到日志（不暴露给客户端）
            $this->log('Request error: ' . $e->getMessage());
            return ['errno' => 500, 'error' => 'Internal server error'];
        }
    }

    /**
     * 连接数据库
     */
    protected function handleConnect(string $sessionId, string $database): array
    {
        if (empty($sessionId)) {
            return ['errno' => 400, 'error' => 'session_id is required'];
        }
        if (empty($database)) {
            return ['errno' => 400, 'error' => 'database is required'];
        }
        if (!$this->isDatabaseAllowed($database)) {
            return ['errno' => 403, 'error' => 'Database access denied'];
        }

        // 复用已有连接
        if (isset($this->sessions[$sessionId])) {
            $session = $this->sessions[$sessionId];
            if ($session->getDatabase() === $database) {
                $session->touch();
                return ['errno' => 0, 'message' => 'Connection already exists'];
            }
            $session->close();
            unset($this->sessions[$sessionId]);
        }

        // 检查连接数限制
        if (count($this->sessions) >= $this->maxConnections) {
            // 尝试清理空闲连接
            $this->forceCleanupIdle();
            
            if (count($this->sessions) >= $this->maxConnections) {
                return ['errno' => 503, 'error' => 'Maximum connections reached'];
            }
        }

        // 创建新连接
        try {
            $conn = new \SQLite3($database, SQLITE3_OPEN_READWRITE | SQLITE3_OPEN_CREATE);
            $this->sessions[$sessionId] = new ConnectionSession($conn, $database, $this->walConfig);
            return ['errno' => 0, 'message' => 'Connected'];
        } catch (\Throwable $e) {
            $this->log("Database connection error: " . $e->getMessage());
            return ['errno' => 500, 'error' => 'Failed to open database'];
        }
    }

    /**
     * 执行查询
     */
    protected function handleQuery(string $sessionId, string $database, string $sql): array
    {
        // 自动连接
        if (!isset($this->sessions[$sessionId])) {
            $result = $this->handleConnect($sessionId, $database);
            if ($result['errno'] !== 0) {
                return $result;
            }
        }

        $session = $this->sessions[$sessionId];
        $session->touch();

        return $session->query($sql);
    }

    /**
     * 开始事务
     */
    protected function handleBegin(string $sessionId, string $database): array
    {
        if (!isset($this->sessions[$sessionId])) {
            $result = $this->handleConnect($sessionId, $database);
            if ($result['errno'] !== 0) {
                return $result;
            }
        }

        $session = $this->sessions[$sessionId];
        return $session->begin();
    }

    /**
     * 提交事务
     */
    protected function handleCommit(string $sessionId): array
    {
        if (!isset($this->sessions[$sessionId])) {
            return ['errno' => 400, 'error' => 'No connection found'];
        }
        return $this->sessions[$sessionId]->commit();
    }

    /**
     * 回滚事务
     */
    protected function handleRollback(string $sessionId): array
    {
        if (!isset($this->sessions[$sessionId])) {
            return ['errno' => 400, 'error' => 'No connection found'];
        }
        return $this->sessions[$sessionId]->rollback();
    }

    /**
     * 断开连接
     */
    protected function handleDisconnect(string $sessionId): array
    {
        if (isset($this->sessions[$sessionId])) {
            $this->sessions[$sessionId]->close();
            unset($this->sessions[$sessionId]);
        }
        return ['errno' => 0, 'message' => 'Disconnected'];
    }

    /**
     * 执行 checkpoint
     */
    protected function handleCheckpoint(string $sessionId, string $mode = 'PASSIVE'): array
    {
        if (!isset($this->sessions[$sessionId])) {
            return ['errno' => 400, 'error' => 'No connection found'];
        }
        return $this->sessions[$sessionId]->checkpoint($mode);
    }

    /**
     * 检查数据库是否允许访问
     */
    protected function isDatabaseAllowed(string $database): bool
    {
        if (str_contains($database, '..') || str_contains($database, "\x00")) {
            return false;
        }
        if ($this->databaseRoot !== null) {
            $realPath = realpath($database);
            if ($realPath === false || !str_starts_with($realPath, $this->databaseRoot)) {
                return false;
            }
        }
        return true;
    }

    /**
     * 清理空闲连接（协程）
     */
    public function cleanupIdleSessions(): void
    {
<<<<<<< HEAD
        while (true) {
=======
        while ($this->running) {
>>>>>>> 6cbda54 (PRAGMA 参数白名单验证 (ConnectionSession.php))
            Async\sleep(Duration::seconds(min($this->idleTimeout, 60))); // 最多每分钟检查一次
            
            $this->doCleanupIdle();
        }
    }

    /**
     * 强制清理空闲连接
     */
    protected function forceCleanupIdle(): void
    {
        $this->doCleanupIdle(true);
    }

    /**
     * 执行清理空闲连接
     */
    private function doCleanupIdle(bool $force = false): void
    {
        $now = time();
        foreach ($this->sessions as $sessionId => $session) {
            // 检查事务超时
            if ($session->isInTransaction() && $session->isTransactionTimeout()) {
                $this->log("Transaction timeout, auto rollback: {$sessionId}");
                $session->rollback();
            }
            
            // 检查空闲超时
            $idleTime = $now - $session->getLastActive();
            if ($force || $idleTime > $this->idleTimeout) {
                if (!$session->isInTransaction() || $idleTime > $this->idleTimeout * 2) {
                    $this->log("Cleaning up idle session: {$sessionId}");
                    $session->close();
                    unset($this->sessions[$sessionId]);
                }
            }
        }
    }

    /**
     * 发送消息
     */
    protected function sendMessage(TCP\StreamInterface $connection, array $data): void
    {
        $json = json_encode($data, JSON_UNESCAPED_UNICODE);
        $message = pack('N', strlen($json)) . $json;
        $connection->writeAll($message);
    }

    /**
     * 发送错误
     */
    protected function sendError(TCP\StreamInterface $connection, int $errno, string $error): void
    {
        $this->sendMessage($connection, ['errno' => $errno, 'error' => $error]);
    }

    /**
     * 日志
     */
    protected function log(string $message): void
    {
        $timestamp = date('Y-m-d H:i:s');
        $line = "[{$timestamp}] {$message}";
        
        if ($this->output) {
            $this->output->writeln($line);
        } else {
            echo $line . "\n";
        }
    }
}