# ThinkPHP ORM SQLite Remote - 项目上下文

## 项目概述

ThinkPHP ORM 扩展，通过 HTTP Tunnel（Navicat 协议）访问远程 SQLite 数据库。

### 核心技术栈
- **PHP**: >= 8.0
- **框架**: ThinkPHP 8.0 + think-orm 3.0
- **协议**: Navicat HTTP Tunnel 二进制协议

### 架构设计
```
客户端 (ThinkPHP)  ──HTTP POST──>  ntunnel_sqlite.php (远程服务器)
     │                                      │
     │  Connection.php                      │  SQLite3 / PDO
     │  - 构建二进制请求                     │  - 执行 SQL
     │  - 解析二进制响应                     │  - 返回结果集
     │                                      │
     └──────────────────────────────────────┘
```

## 目录结构

```
src/
├── Connection.php              # 核心连接类，实现 HTTP Tunnel 协议
│   - connect(): 初始化连接，读取配置
│   - executeRaw(): 发送 HTTP 请求
│   - parseResponse(): 解析二进制响应
│   - getSchemaInfo(): 获取表结构（带缓存）
│
├── console/command/optimize/
│   └── SchemaRemote.php        # Schema 缓存命令
│
├── controller/
│   └── NTunnel.php             # 服务端控制器（替代 ntunnel_sqlite.php）
│
├── service/
│   └── ServiceProvider.php     # 服务提供者（注册命令和路由）
│
└── route/                      # 路由配置

config/
└── database.php                # 配置示例

ntunnel_sqlite.php              # 独立服务端脚本（部署到远程服务器）
```

## 配置项

### 连接配置
```php
'connections' => [
    'sqlite_remote' => [
        'type'           => \yangweijie\orm\sqlite\remote\Connection::class,
        'tunnel_url'     => 'http://server/ntunnel_sqlite.php',  // 必填
        'database'       => 'path/to/db.sqlite',                  // 必填，相对路径
        'prefix'         => '',           // 表前缀
        'encode_base64'  => false,        // Base64 编码查询
        'timeout'        => 30,           // 超时秒数
        'auth_username'  => 'admin',      // Basic Auth 用户名
        'auth_password'  => 'secret',     // Basic Auth 密码
        'fields_cache'   => true,         // 字段缓存
    ],
],
```

### Think-ORM 通用配置
| 配置项 | 说明 |
|--------|------|
| `type` | 连接类 |
| `prefix` | 表前缀 |
| `fields_cache` | 字段缓存 |
| `fields_strict` | 严格字段检查 |
| `trigger_sql` | SQL 监听 |
| `builder` | 自定义 Builder 类 |
| `query` | 自定义 Query 类 |

## 二进制协议

### 请求格式 (POST)
```
actn=C|Q|2|3    # 操作: Connect/Query/Create2/Create3
dbfile=path     # 数据库文件路径
q[]=SQL         # SQL 查询数组
encodeBase64=1  # 可选，Base64 编码
```

### 响应格式
```
头部 (16 bytes):
  - magic (4B): 0x00000457 (1111)
  - version (2B): 203
  - errno (4B): 错误码
  - reserved (6B)

结果集头 (32 bytes):
  - errno (4B)
  - affectedRows (4B)
  - insertId (4B)
  - numFields (4B)
  - numRows (4B)
  - dummy (12B)

字段定义 + 数据行 (Block 编码)
```

### Block 编码
```
长度 < 254:  [len(1B)][data]
长度 >= 254: [0xFE][len(4B)][data]
```

## 命令

```bash
# 生成 Schema 缓存
php think optimize:schema-remote --connection=sqlite_remote
php think optimize:schema-remote --connection=sqlite_remote --table=users
php think optimize:schema-remote --connection=sqlite_remote --table=*
```

## 服务端部署

### 方式一：独立脚本
将 `ntunnel_sqlite.php` 部署到远程服务器。

### 方式二：ThinkPHP 路由
```php
// 注册路由
\yangweijie\orm\sqlite\remote\service\ServiceProvider::registerRoute(
    $app->route,
    'ntunnel-sqlite',
    [
        'auth_username' => 'admin',
        'auth_password' => 'secret',
        'database_root' => '/data/sqlite',  // 限制数据库目录
    ]
);
```

## 开发注意事项

1. **命名空间**: `yangweijie\orm\sqlite\remote`
2. **继承关系**: `Connection extends \think\db\Connection`
3. **Builder**: 复用 `think\db\builder\Sqlite`
4. **Query**: 复用 `think\db\Query`
5. **缓存**: 使用 PSR-16 CacheInterface
6. **安全**: 支持数据库目录限制、文件白名单、Basic Auth

## 测试

```bash
# 连接测试
php -r "
require 'vendor/autoload.php';
\$conn = new \yangweijie\orm\sqlite\remote\Connection([
    'tunnel_url' => 'http://localhost/ntunnel',
    'database' => 'test.db',
]);
\$conn->connect();
echo 'OK';
"
```
