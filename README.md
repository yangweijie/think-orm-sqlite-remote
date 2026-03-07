# ThinkPHP ORM SQLite Remote

通过 HTTP Tunnel 或 Socket 访问远程 SQLite 数据库的 ThinkPHP ORM 扩展。

## 特性

- **双模式支持**：HTTP Tunnel（Navicat 兼容）+ Socket（事务支持）
- **完整事务支持**：BEGIN/COMMIT/ROLLBACK，支持 insertGetId
- **WAL 模式优化**：支持 SQLite WAL 高并发配置
- **连接池管理**：Socket 模式下保持连接一致性
- **安全认证**：支持 Basic Auth
- **Navicat 兼容**：HTTP Tunnel 模式可直接用于 Navicat 连接

## 架构

```
┌─────────────────────────────────────────────────────────────────────┐
│                        双模式架构                                    │
├─────────────────────────────────────────────────────────────────────┤
│                                                                     │
│  模式 1: HTTP Tunnel（Navicat 用）                                   │
│  ──────────────────────────────────                                 │
│  Navicat ──HTTP──► NTunnel 控制器 ──► SQLite DB                     │
│                                                                     │
│  特点: 无事务支持，适合远程管理                                       │
│  配置: tunnel_url                                                   │
│                                                                     │
├─────────────────────────────────────────────────────────────────────┤
│                                                                     │
│  模式 2: Socket（ThinkPHP 用，支持事务）                              │
│  ────────────────────────────────────                               │
│  ThinkPHP ──Socket──► TunnelServer ──► SQLite DB                    │
│              │                                                      │
│              └── 连接池管理（session_id → SQLite 连接）               │
│                                                                     │
│  特点: 完整事务支持，支持 insertGetId                                 │
│  配置: socket_host + socket_port                                    │
│  启动: php think sqlite-tunnel:start                                │
│                                                                     │
└─────────────────────────────────────────────────────────────────────┘
```

## 安装

```bash
composer require yangweijie/think-orm-sqlite-remote
```

> 要求 PHP >= 8.4（psl 依赖）

## 配置

### HTTP Tunnel 模式（Navicat 连接）

```php
// config/database.php
return [
    'connections' => [
        'sqlite_remote' => [
            'type'           => \yangweijie\orm\sqlite\remote\Connection::class,
            'tunnel_url'     => 'http://your-server.com/ntunnel-sqlite',
            'database'       => '/path/to/database.sqlite',
            'prefix'         => '',
            'timeout'        => 30,
            'auth_username'  => 'admin',     // 可选
            'auth_password'  => 'secret',    // 可选
            'fields_cache'   => true,        // 字段缓存
        ],
    ],
];
```

### Socket 模式（事务支持）

```php
// config/database.php
return [
    'connections' => [
        'sqlite_socket' => [
            'type'           => \yangweijie\orm\sqlite\remote\Connection::class,
            'socket_host'    => '127.0.0.1',
            'socket_port'    => 9527,
            'database'       => '/path/to/database.sqlite',
            'prefix'         => '',
            'timeout'        => 30,
            'auth_username'  => 'admin',     // 需与守护进程配置一致
            'auth_password'  => 'secret',
            'fields_cache'   => true,
        ],
    ],
];
```

## 使用方法

### 启动 Socket 守护进程

```bash
# 基本启动（默认启用 WAL 模式）
php think sqlite-tunnel:start

# 指定端口
php think sqlite-tunnel:start --port=9527

# 带认证
php think sqlite-tunnel:start --port=9527 --auth-username=admin --auth-password=secret

# 限制数据库目录
php think sqlite-tunnel:start --port=9527 --database-root=/data/sqlite

# 完整参数
php think sqlite-tunnel:start \
    --host=127.0.0.1 \
    --port=9527 \
    --auth-username=admin \
    --auth-password=secret \
    --database-root=/data/sqlite \
    --idle-timeout=300 \
    --max-connections=100
```

### WAL 模式配置（高并发优化）

```bash
# 默认配置（推荐）
php think sqlite-tunnel:start --wal-mode=WAL --busy-timeout=5000

# 高并发读取
php think sqlite-tunnel:start \
    --wal-mode=WAL \
    --busy-timeout=10000 \
    --wal-checkpoint=500 \
    --synchronous=NORMAL \
    --transaction-timeout=30

# 性能优先（写入密集）
php think sqlite-tunnel:start \
    --synchronous=OFF \
    --wal-checkpoint=2000

# 安全优先（关键数据）
php think sqlite-tunnel:start \
    --synchronous=FULL \
    --transaction-timeout=120
```

#### WAL 配置说明

| 参数 | 默认值 | 说明 |
|------|--------|------|
| `--wal-mode` | WAL | 日志模式（WAL/DELETE） |
| `--busy-timeout` | 5000 | 锁等待超时(ms)，高并发建议增大 |
| `--wal-checkpoint` | 1000 | 自动 checkpoint 阈值 |
| `--synchronous` | NORMAL | 同步模式：OFF（最快）、NORMAL（推荐）、FULL（最安全） |
| `--transaction-timeout` | 60 | 事务超时(s)，防止长事务阻塞 |

#### SQLite PRAGMA 说明

```
journal_mode=WAL     → 启用预写日志，读写并发
busy_timeout=5000    → 锁等待5秒，避免 SQLITE_BUSY 错误
synchronous=NORMAL   → 平衡性能和安全
  - OFF:   最快，崩溃可能丢数据
  - NORMAL: 推荐，系统崩溃安全
  - FULL:  最安全，最慢
BEGIN IMMEDIATE      → 立即获取写锁，避免死锁
```

### 基本查询

```php
use think\facade\Db;

// 查询
$users = Db::connect('sqlite_socket')->table('users')->select();

// 插入
Db::connect('sqlite_socket')->table('users')->insert([
    'name' => 'John',
    'email' => 'john@example.com',
]);

// 更新
Db::connect('sqlite_socket')->table('users')->where('id', 1)->update(['name' => 'Jane']);

// 删除
Db::connect('sqlite_socket')->table('users')->where('id', 1)->delete();
```

### 事务操作（需要 Socket 模式）

```php
use think\facade\Db;

// 自动事务
Db::connect('sqlite_socket')->transaction(function() {
    $id = Db::table('users')->insertGetId(['name' => 'test']);
    Db::table('profiles')->insert(['user_id' => $id, 'bio' => '...']);
});

// 手动控制
Db::connect('sqlite_socket')->startTrans();
try {
    $id = Db::table('users')->insertGetId(['name' => 'test']);
    Db::table('profiles')->insert(['user_id' => $id]);
    Db::connect('sqlite_socket')->commit();
} catch (\Exception $e) {
    Db::connect('sqlite_socket')->rollback();
    throw $e;
}
```

### 使用 Model

```php
namespace app\model;

use think\Model;

class User extends Model
{
    protected $connection = 'sqlite_socket';
    protected $table = 'users';
    protected $autoWriteTimestamp = true;
}

// 使用
$users = User::where('status', 1)->select();
$user = User::find(1);
```

### 字段缓存

```bash
# 生成所有表的字段缓存
php think optimize:schema-remote --connection=sqlite_socket

# 生成指定表的字段缓存
php think optimize:schema-remote --connection=sqlite_socket --table=users

# 生成所有表（通配符）
php think optimize:schema-remote --connection=sqlite_socket --table=*
```

## 服务端部署

### 方式一：独立脚本

将 `ntunnel_sqlite.php` 部署到远程服务器。

### 方式二：ThinkPHP 路由（推荐）

```php
// 在服务提供者或路由文件中
\yangweijie\orm\sqlite\remote\service\ServiceProvider::registerRoute(
    $app->route,
    'ntunnel-sqlite',
    [
        'auth_username' => 'admin',
        'auth_password' => 'secret',
        'database_root' => '/data/sqlite',
        'allowed_databases' => ['/data/sqlite/app.db'],
    ]
);
```

### 方式三：完整部署（HTTP + Socket）

```bash
# 1. 注册服务提供者（config/app.php 或 app/provider.php）
\yangweijie\orm\sqlite\remote\service\ServiceProvider::class,

# 2. 启动 Socket 守护进程（后台运行）
nohup php think sqlite-tunnel:start --port=9527 --auth-username=admin --auth-password=secret &

# 3. 使用 Supervisor 管理守护进程
[program:sqlite-tunnel]
command=php think sqlite-tunnel:start --port=9527 --wal-mode=WAL --busy-timeout=5000
directory=/path/to/your/project
autostart=true
autorestart=true
user=www-data
stdout_logfile=/var/log/sqlite-tunnel.log
```

## Navicat 连接配置

1. 连接类型选择 **SQLite**
2. 选择 **HTTP** 标签页
3. 勾选 **使用 HTTP 隧道**
4. 隧道网址: `http://your-server.com/ntunnel-sqlite`
5. 勾选 **使用验证**，填写用户名和密码
6. 数据库文件: 服务器上的绝对路径

## 协议说明

### HTTP Tunnel 协议

与 Navicat 的 `ntunnel_sqlite.php` 兼容：

- 请求方法: POST
- 参数: `actn`, `dbfile`, `q[]`, `encodeBase64`
- 响应: 二进制格式（16字节头 + 结果集）

### Socket 协议

基于 JSON 的请求/响应：

```json
// 请求
{
    "action": "query",
    "session_id": "uuid-v4",
    "database": "/path/to/db.sqlite",
    "sql": "SELECT * FROM users",
    "auth": {"username": "admin", "password": "secret"}
}

// 响应
{
    "errno": 0,
    "rows": [...],
    "affected_rows": 1,
    "insert_id": 123
}
```

## 开发与测试

### 安装开发依赖

```bash
composer install
```

### 运行测试

```bash
# 运行所有测试
composer test

# 或直接使用 pest
./vendor/bin/pest

# 运行特定测试
./vendor/bin/pest tests/Unit/ConnectionSessionTest.php

# 带覆盖率
./vendor/bin/pest --coverage
```

### 测试覆盖

- `ConnectionSessionTest`: WAL 配置、事务管理、查询执行、超时检测、Checkpoint

## 注意事项

1. **事务限制**: HTTP Tunnel 模式不支持事务，请使用 Socket 模式
2. **安全**: 使用 Basic Auth 或防火墙保护隧道入口
3. **性能**: Socket 模式比 HTTP 模式性能更好
4. **WAL 模式**: 高并发场景推荐启用 WAL 模式
5. **部署**: Socket 守护进程需常驻运行（建议用 Supervisor）
6. **事务超时**: 长时间未提交的事务会自动回滚

## 目录结构

```
src/
├── Connection.php              # 核心连接类（支持 HTTP + Socket）
├── SocketClient.php            # Socket 客户端（psl TCP）
├── Server/
│   ├── TunnelServer.php        # Socket 服务端（psl 异步）
│   └── ConnectionSession.php   # 连接会话管理（WAL 配置）
├── console/command/
│   ├── SqliteTunnel.php        # 守护进程命令
│   └── optimize/SchemaRemote.php # 字段缓存命令
├── controller/NTunnel.php      # HTTP Tunnel 控制器
└── service/ServiceProvider.php # 服务提供者

tests/
├── Unit/
│   └── ConnectionSessionTest.php # 单元测试
├── Feature/
├── Pest.php
└── TestCase.php

config/database.php             # 配置示例
ntunnel_sqlite.php              # 独立服务端脚本
```

## 许可证

Apache-2.0 License
