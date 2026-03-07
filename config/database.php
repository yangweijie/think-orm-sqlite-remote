<?php
/**
 * SQLite Remote Database Configuration
 * 
 * 支持两种模式：
 * 
 * ┌─────────────────────────────────────────────────────────────────────────┐
 * │ 模式 1: HTTP Tunnel（用于 Navicat 等客户端）                             │
 * │ - 无事务支持                                                            │
 * │ - 适合远程管理工具连接                                                   │
 * │ - 配置: tunnel_url                                                      │
 * └─────────────────────────────────────────────────────────────────────────┘
 * 
 * ┌─────────────────────────────────────────────────────────────────────────┐
 * │ 模式 2: Socket（用于 ThinkPHP 应用，支持完整事务）                        │
 * │ - 支持完整事务（BEGIN/COMMIT/ROLLBACK）                                  │
 * │ - 支持事务中获取 lastInsertId                                            │
 * │ - 需要先启动守护进程: php think sqlite-tunnel:start                      │
 * │ - 配置: socket_host + socket_port                                       │
 * └─────────────────────────────────────────────────────────────────────────┘
 * 
 * 使用步骤：
 * 
 * 1. 安装包：
 *    composer require yangweijie/think-orm-sqlite-remote
 * 
 * 2. HTTP Tunnel 模式（Navicat 连接用）：
 *    - 部署 ntunnel_sqlite.php 或注册路由
 *    - 配置 tunnel_url
 * 
 * 3. Socket 模式（事务支持）：
 *    - 启动守护进程: php think sqlite-tunnel:start --port=9527
 *    - 配置 socket_host 和 socket_port
 * 
 * 4. 使用示例：
 * 
 *    // 查询
 *    $users = Db::table('users')->select();
 *    
 *    // 事务（需要 Socket 模式）
 *    Db::transaction(function() {
 *        $id = Db::table('users')->insertGetId(['name' => 'test']);
 *        Db::table('profiles')->insert(['user_id' => $id]);
 *    });
 */

return [
    'default' => 'sqlite_remote',
    'connections' => [
        // HTTP Tunnel 模式（Navicat 连接用，无事务支持）
        'sqlite_remote' => [
            'type'           => \yangweijie\orm\sqlite\remote\Connection::class,
            'tunnel_url'     => 'http://localhost/ntunnel-sqlite',
            'database'       => '/path/to/database.sqlite',
            'prefix'         => '',
            'encode_base64'  => false,
            'timeout'        => 30,
            // Basic Auth（可选）
            'auth_username'  => 'admin',
            'auth_password'  => 'secret',
            // 字段缓存
            'fields_cache'   => true,
        ],
        
        // Socket 模式（ThinkPHP 应用用，支持事务）
        'sqlite_socket' => [
            'type'           => \yangweijie\orm\sqlite\remote\Connection::class,
            // Socket 配置（优先于 tunnel_url）
            'socket_host'    => '127.0.0.1',
            'socket_port'    => 9527,
            'database'       => '/path/to/database.sqlite',
            'prefix'         => '',
            'timeout'        => 30,
            // Basic Auth（可选，需与守护进程配置一致）
            'auth_username'  => 'admin',
            'auth_password'  => 'secret',
            // 字段缓存
            'fields_cache'   => true,
        ],
    ],
];