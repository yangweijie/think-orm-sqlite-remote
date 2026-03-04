# ThinkPHP ORM SQLite Remote

通过 HTTP 隧道访问远程 SQLite 数据库的 ThinkPHP ORM 扩展。

## 原理

参考 Navicat 的 `ntunnel_sqlite.php` 实现，通过 HTTP 请求 + 二进制协议与远程服务器上的 `ntunnel_sqlite.php` 通信，执行 SQL 操作。

## 目录结构

```
think-orm-sqlite-remote/
├── src/
│   ├── Connection.php   # 核心连接类 - 实现 HTTP 隧道协议
│   ├── Builder.php      # 查询构建器
│   ├── Schema.php       # 数据库结构操作
│   ├── Service.php      # 服务提供者
│   ├── Db.php          # Facade 门面
│   └── Model.php        # 基础 Model 类
├── config/
│   └── database.php    # 配置文件示例
├── ntunnel_sqlite.php  # 远程服务器端脚本
└── composer.json
```

## 安装

```bash
composer require yangweijie/think-orm-sqlite-remote
```

## 配置

### 1. 配置文件 (config/database.php)

```php
return [
    'default' => 'sqlite_remote',
    'connections' => [
        'sqlite_remote' => [
            // 核心配置
            'type' => \yangweijie\orm\sqlite\remote\Connection::class,
            'tunnel_url' => 'http://your-server.com/path/to/ntunnel_sqlite.php',
            'database' => 'relative/path/to/database.sqlite',
            
            // 可选配置
            'prefix' => 'db_',           // 表前缀
            'encode_base64' => false,    // 是否 Base64 编码查询
            'timeout' => 30,             // 超时时间（秒）
        ],
    ],
];
```

### 2. 部署 ntunnel_sqlite.php

将 `ntunnel_sqlite.php` 部署到你的远程服务器上，确保可以通过 HTTP 访问。

## 使用方法

### 直接使用 Db Facade

```php
use think\facade\Db;

// 查询
$users = Db::connect('sqlite_remote')
    ->table('users')
    ->where('status', 1)
    ->select();

// 条件查询
$user = Db::connect('sqlite_remote')
    ->table('users')
    ->where('email', 'john@example.com')
    ->find();

// 插入
Db::connect('sqlite_remote')->table('users')->insert([
    'name' => 'John',
    'email' => 'john@example.com',
    'created_at' => date('Y-m-d H:i:s'),
]);

// 更新
Db::connect('sqlite_remote')
    ->table('users')
    ->where('id', 1)
    ->update(['name' => 'Jane']);

// 删除
Db::connect('sqlite_remote')
    ->table('users')
    ->where('id', 1)
    ->delete();

// 原生 SQL
$result = Db::connect('sqlite_remote')->query('SELECT * FROM users WHERE id = ?', [1]);
```

### 使用 Model

```php
namespace app\model;

use qiezi\orm\sqlite\remote\Model as RemoteModel;

class User extends RemoteModel
{
    protected $connection = 'sqlite_remote';
    protected $table = 'users';
    
    // 自动时间戳
    protected $autoWriteTimestamp = true;
    protected $createTime = 'created_at';
    protected $updateTime = 'updated_at';
    
    // 软删除
    // protected $deleteTime = 'deleted_at';
    
    // 关联
    public function profile()
    {
        return $this->hasOne(Profile::class, 'user_id');
    }
}

// 使用
$user = User::find(1);
$users = User::where('status', 1)->select();
User::create(['name' => 'John', 'email' => 'john@example.com']);
```

### 使用 Service

```php
// 获取连接实例
$conn = app('qiezi\orm\sqlite\remote\Connection');

// 或者使用 Db 类
$conn = \think\facade\Db::connect('sqlite_remote');

// 执行查询
$result = $conn->query('SELECT COUNT(*) as total FROM users');
```

## 协议说明

### HTTP 请求

- **URL**: 配置的 `tunnel_url`
- **方法**: POST
- **Content-Type**: application/x-www-form-urlencoded
- **参数**:
  - `actn`: 操作类型
    - `C` - 连接测试
    - `Q` - 执行查询
    - `2` - 创建 SQLite2 数据库
    - `3` - 创建 SQLite3 数据库
  - `dbfile`: 数据库文件路径（相对于 ntunnel_sqlite.php）
  - `q[]`: SQL 查询数组
  - `encodeBase64`: 是否 Base64 编码（可选）

### 二进制响应协议

响应为二进制格式，包含：
1. 头部 (16 字节): 魔数 + 版本 + 错误码 + 保留
2. 结果集头: 错误码 + 影响行数 + 插入ID + 字段数 + 行数
3. 字段定义: 字段名 + 表名 + 类型 + 标志 + 长度
4. 数据行: 值块 + 类型

## 注意事项

1. **安全**: 不要将 `ntunnel_sqlite.php` 暴露在公共网络上，使用防火墙或 Basic Auth 保护
2. **性能**: 每次查询都通过 HTTP 请求，不适合高并发场景
3. **文件路径**: `database` 配置为相对于 ntunnel_sqlite.php 的路径
4. **错误处理**: 建议捕获 `\think\db\exception\DBException` 异常

## 许可证

Apache-2.0 License
