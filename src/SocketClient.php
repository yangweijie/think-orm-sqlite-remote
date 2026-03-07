<?php
declare(strict_types=1);

namespace yangweijie\orm\sqlite\remote;

use Psl\DateTime\Duration;
use Psl\TCP;

/**
 * Socket 客户端 - 连接到 TunnelServer
 * 
 * 用于 ThinkPHP 应用与守护进程通信
 */
class SocketClient
{
    protected string $host;
    protected int $port;
    protected ?string $authUsername;
    protected ?string $authPassword;
    protected int $timeout;

    /** @var string 会话 ID（用于保持连接一致性） */
    protected string $sessionId;

    /** @var TCP\StreamInterface|null */
    protected ?TCP\StreamInterface $socket = null;

    public function __construct(
        string $host,
        int $port,
        ?string $authUsername = null,
        ?string $authPassword = null,
        int $timeout = 30
    ) {
        $this->host = $host;
        $this->port = $port;
        $this->authUsername = $authUsername;
        $this->authPassword = $authPassword;
        $this->timeout = $timeout;
        $this->sessionId = $this->generateSessionId();
    }

    /**
     * 生成唯一会话 ID
     */
    protected function generateSessionId(): string
    {
        return bin2hex(random_bytes(16));
    }

    /**
     * 获取会话 ID
     */
    public function getSessionId(): string
    {
        return $this->sessionId;
    }

    /**
     * 连接到服务器
     */
    public function connect(): void
    {
        if ($this->socket !== null) {
            return;
        }

        $this->socket = TCP\connect(
            $this->host,
            $this->port,
            no_delay: true,
            timeout: Duration::seconds($this->timeout),
        );
    }

    /**
     * 断开连接
     */
    public function disconnect(): void
    {
        if ($this->socket !== null) {
            $this->socket->close();
            $this->socket = null;
        }
    }

    /**
     * 连接数据库
     */
    public function connectDatabase(string $database): array
    {
        return $this->request([
            'action' => 'connect',
            'session_id' => $this->sessionId,
            'database' => $database,
        ]);
    }

    /**
     * 执行查询
     */
    public function query(string $database, string $sql): array
    {
        return $this->request([
            'action' => 'query',
            'session_id' => $this->sessionId,
            'database' => $database,
            'sql' => $sql,
        ]);
    }

    /**
     * 开始事务
     */
    public function beginTransaction(string $database): array
    {
        return $this->request([
            'action' => 'begin',
            'session_id' => $this->sessionId,
            'database' => $database,
        ]);
    }

    /**
     * 提交事务
     */
    public function commit(): array
    {
        return $this->request([
            'action' => 'commit',
            'session_id' => $this->sessionId,
        ]);
    }

    /**
     * 回滚事务
     */
    public function rollback(): array
    {
        return $this->request([
            'action' => 'rollback',
            'session_id' => $this->sessionId,
        ]);
    }

    /**
     * Ping 服务器
     */
    public function ping(): array
    {
        return $this->request([
            'action' => 'ping',
        ]);
    }

    /**
     * 发送请求并获取响应
     */
    protected function request(array $data): array
    {
        $this->connect();

        // 添加认证信息
        if ($this->authUsername !== null) {
            $data['auth'] = [
                'username' => $this->authUsername,
                'password' => $this->authPassword ?? '',
            ];
        }

        // 编码消息
        $json = json_encode($data, JSON_UNESCAPED_UNICODE);
        $message = pack('N', strlen($json)) . $json;

        // 发送
        $this->socket->writeAll($message, Duration::seconds($this->timeout));

        // 读取响应长度
        $lengthData = $this->socket->readFixedSize(4, Duration::seconds($this->timeout));

        $length = unpack('N', $lengthData)[1];
        if ($length <= 0 || $length > 10 * 1024 * 1024) {
            throw new \RuntimeException('Invalid response length');
        }

        // 读取响应内容
        $responseData = $this->socket->readFixedSize($length, Duration::seconds($this->timeout));

        return json_decode($responseData, true);
    }

    /**
     * 析构函数 - 关闭连接
     */
    public function __destruct()
    {
        $this->disconnect();
    }
}