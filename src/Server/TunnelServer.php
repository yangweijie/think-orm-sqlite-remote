<?php
declare(strict_types=1);

namespace yangweijie\orm\sqlite\remote\Server;

use Psl\Async;
use Psl\IO;
use Psl\Network;
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

        // 使用 psl TCP 监听
        $listener = Network\Server::create(
            Network\SocketAddress::create($this->host, $this->port)
        );

        $this->log("Server started, waiting for connections...");

        // 启动空闲连接清理协程
        Async\run([$this, 'cleanupIdleSessions'])->ignore();

        // 主循环接受连接
        while (true) {
            try {
                $connection = $listener->accept();
                
                // 每个连接在独立协程中处理
                Async\run(function () use ($connection) {
                    $this->handleConnection($connection);
                })->ignore();
                
            } catch (\Throwable $e) {
                $this->log("Error accepting connection: " . $e->getMessage());
            }
        }
    }

    /**
     * 处理客户端连接
     */
    protected function handleConnection(Network\StreamSocketInterface $connection): void
    {
        $remoteAddr = $connection->getRemoteAddress();
        $this->log("Client connected: {$remoteAddr}");

        try {
            while (true) {
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
            return ['errno' => 500, 'error' => $e->getMessage()];
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

        // 创建新连接
        try {
            $conn = new \SQLite3($database, SQLITE3_OPEN_READWRITE | SQLITE3_OPEN_CREATE);
            $this->sessions[$sessionId] = new ConnectionSession($conn, $database, $this->walConfig);
            return ['errno' => 0, 'message' => 'Connected'];
        } catch (\Throwable $e) {
            return ['errno' => 500, 'error' => $e->getMessage()];
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
        while (true) {
            Async\sleep(min($this->idleTimeout, 60)); // 最多每分钟检查一次
            
            $now = time();
            foreach ($this->sessions as $sessionId => $session) {
                // 检查事务超时
                if ($session->isInTransaction() && $session->isTransactionTimeout()) {
                    $this->log("Transaction timeout, auto rollback: {$sessionId}");
                    $session->rollback();
                }
                
                // 检查空闲超时
                if ($now - $session->getLastActive() > $this->idleTimeout) {
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
    protected function sendMessage(Network\StreamSocketInterface $connection, array $data): void
    {
        $json = json_encode($data, JSON_UNESCAPED_UNICODE);
        $message = pack('N', strlen($json)) . $json;
        $connection->writeAll($message);
    }

    /**
     * 发送错误
     */
    protected function sendError(Network\StreamSocketInterface $connection, int $errno, string $error): void
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
