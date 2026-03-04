<?php
/**
 * SQLite Remote Database Configuration
 * 
 * Usage:
 * 
 * 1. Install the package:
 *    composer require qiezi/think-orm-sqlite-remote
 * 
 * 2. Configure database.php:
 * 
 *    return [
 *        'default' => 'sqlite_remote',
 *        'connections' => [
 *            'sqlite_remote' => [
 *                'type'     => 'qiezi\\orm\\sqlite\\remote\\Connection',
 *                'tunnel_url' => 'http://your-server.com/ntunnel_sqlite.php',
 *                'database' => 'data/db.sqlite',  // Relative path to SQLite file
 *                'prefix'   => 'db_',             // Table prefix (optional)
 *                'encode_base64' => false,        // Encode queries in base64 (optional)
 *                'timeout'  => 30,                // Connection timeout in seconds (optional)
 *            ],
 *        ],
 *    ];
 * 
 * 3. Use in code:
 * 
 *    // Direct query
 *    $result = \think\facade\Db::connect('sqlite_remote')->query('SELECT * FROM users');
 *    
 *    // Using Query Builder
 *    $users = \think\facade\Db::connect('sqlite_remote')
 *        ->table('users')
 *        ->where('status', 1)
 *        ->select();
 *    
 *    // Insert
 *    \think\facade\Db::connect('sqlite_remote')->table('users')->insert([
 *        'name' => 'John',
 *        'email' => 'john@example.com',
 *    ]);
 *    
 *    // Using Model
 *    namespace app\model;
 *    
 *    use think\Model;
 *    
 *    class User extends Model
 *    {
 *        protected $connection = 'sqlite_remote';
 *        protected $table = 'users';
 *    }
 * 
 * 4. Make sure your ntunnel_sqlite.php is accessible:
 *    - Place ntunnel_sqlite.php on your remote server
 *    - The path should be relative to the ntunnel_sqlite.php location
 *    - Example: 'data/db.sqlite' means the file is at /path/to/data/db.sqlite
 */

return [
    'default' => 'sqlite_remote',
    'connections' => [
        'sqlite_remote' => [
            'type' => \qiezi\orm\sqlite\remote\Connection::class,
            'tunnel_url' => 'http://localhost:8080/ntunnel_sqlite.php',
            'database' => 'database.sqlite',
            'prefix' => '',
            'encode_base64' => false,
            'timeout' => 30,
        ],
    ],
];
