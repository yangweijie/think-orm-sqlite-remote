<?php
declare(strict_types=1);

namespace yangweijie\orm\sqlite\remote\service;

use think\App;
use think\Route;
use yangweijie\orm\sqlite\remote\console\command\optimize\SchemaRemote;
use yangweijie\orm\sqlite\remote\controller\NTunnel;

/**
 * 服务提供者
 * 
 * 使用方法：
 * 
 * 1. 在 config/app.php 的 providers 中添加:
 *    \yangweijie\orm\sqlite\remote\service\ServiceProvider::class,
 * 
 * 2. 或者在 app/provider.php 中添加:
 *    return [
 *        \yangweijie\orm\sqlite\remote\service\ServiceProvider::class,
 *    ];
 * 
 * 3. 手动注册路由（在 route/app.php 中）:
 *    \yangweijie\orm\sqlite\remote\service\ServiceProvider::registerRoute($app->route);
 */
class ServiceProvider
{
    /**
     * 默认隧道路由路径
     */
    public static string $tunnelRoute = 'ntunnel-sqlite';
    
    /**
     * 隧道路由选项
     */
    public static array $tunnelOptions = [];
    
    /**
     * 命令列表
     */
    public static function commands(): array
    {
        return [
            SchemaRemote::class,
        ];
    }
    
    /**
     * 注册服务
     */
    public function register(App $app): void
    {
        // 注册命令
        if ($app->has('console')) {
            foreach (self::commands() as $command) {
                $app->console->addCommand(new $command());
            }
        }
        
        // 自动注册路由
        if ($app->has('route')) {
            self::registerRoute($app->route, self::$tunnelRoute, self::$tunnelOptions);
        }
    }
    
    /**
     * 注册隧道路由
     * 
     * @param Route $route 路由对象
     * @param string $path 路由路径
     * @param array $options 配置选项
     *   - allow_test_menu: bool 是否允许测试页面
     *   - database_root: string 数据库根目录（安全限制）
     *   - allowed_databases: array 允许的数据库列表
     *   - auth_username: string Basic Auth 用户名
     *   - auth_password: string Basic Auth 密码
     */
    public static function registerRoute(Route $route, string $path = 'ntunnel-sqlite', array $options = []): void
    {
        $route->any($path, function (App $app) use ($options) {
            $controller = new NTunnel();
            
            // 配置选项
            if (isset($options['allow_test_menu'])) {
                $controller->setAllowTestMenu($options['allow_test_menu']);
            }
            if (isset($options['database_root'])) {
                $controller->setDatabaseRoot($options['database_root']);
            }
            if (isset($options['allowed_databases'])) {
                $controller->setAllowedDatabases($options['allowed_databases']);
            }
            
            // Basic Auth 认证
            if (isset($options['auth_username'])) {
                $controller->setBasicAuth(
                    $options['auth_username'],
                    $options['auth_password'] ?? null
                );
            }
            
            return $controller($app);
        });
    }
    
    /**
     * 设置隧道路由配置
     */
    public static function setTunnelConfig(string $route, array $options = []): void
    {
        self::$tunnelRoute = $route;
        self::$tunnelOptions = $options;
    }
}
