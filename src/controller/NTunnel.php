<?php
declare(strict_types=1);

namespace yangweijie\orm\sqlite\remote\controller;

use think\App;
use think\Cookie;
use think\response\Html;

/**
 * Navicat HTTP Tunnel 控制器
 * 
 * 替代独立的 ntunnel_sqlite.php 文件
 * 
 * 在 ThinkPHP 路由中配置：
 * Route::any('ntunnel-sqlite', \yangweijie\orm\sqlite\remote\controller\NTunnel::class);
 * 
 * 或者在控制器中使用：
 * class NTunnelController extends \yangweijie\orm\sqlite\remote\controller\NTunnel {}
 */
class NTunnel
{
    protected const VERSION = 203;
    
    protected const FILE_DOES_NOT_EXIST = 0;
    protected const FILE_CANNOT_BE_OPENED = 1;
    protected const FILE_IS_SQLITE2 = 2;
    protected const FILE_IS_SQLITE3 = 3;
    protected const FILE_IS_INVALID = 4;
    
    /**
     * App 实例
     */
    protected App $app;
    
    /**
     * 是否允许测试页面
     */
    protected bool $allowTestMenu = true;
    
    /**
     * 数据库根目录（安全限制）
     * 设置后只允许访问该目录下的数据库文件
     */
    protected ?string $databaseRoot = null;
    
    /**
     * 允许的数据库文件列表（安全限制）
     * 设置后只允许访问指定的数据库文件
     */
    protected array $allowedDatabases = [];
    
    /**
     * Basic Auth 用户名
     * 设置后将启用 Basic Auth 认证
     */
    protected ?string $authUsername = null;
    
    /**
     * Basic Auth 密码
     */
    protected ?string $authPassword = null;
    
    /**
     * 入口方法
     */
    public function __invoke(App $app): Html
    {
        set_time_limit(0);
        
        $this->app = $app;
        
        // Basic Auth 认证检查
        if (!$this->authenticate()) {
            return $this->unauthorizedResponse();
        }
        
        $request = $app->request;
        $method = strtoupper($request->method());
        
        // GET 请求显示测试页面
        if ($method === 'GET' || $method === 'HEAD') {
            return $this->showTestPage();
        }
        
        // POST 请求处理隧道
        return $this->handleTunnelRequest($app);
    }
    
    /**
     * 验证 Basic Auth
     */
    protected function authenticate(): bool
    {
        // 如果未设置用户名，则不需要认证
        if ($this->authUsername === null) {
            return true;
        }
        
        $request = $this->app->request;
        
        // 从请求头获取 Authorization
        $authHeader = $request->header('authorization', '');
        
        if (!str_starts_with(strtolower($authHeader), 'basic ')) {
            return false;
        }
        
        // 解码 Base64 凭据
        $credentials = base64_decode(substr($authHeader, 6));
        if ($credentials === false) {
            return false;
        }
        
        // 解析用户名和密码
        $colonPos = strpos($credentials, ':');
        if ($colonPos === false) {
            return false;
        }
        
        $username = substr($credentials, 0, $colonPos);
        $password = substr($credentials, $colonPos + 1);
        
        // 验证凭据（使用 time-safe 比较）
        return hash_equals($this->authUsername, $username)
            && hash_equals($this->authPassword ?? '', $password);
    }
    
    /**
     * 返回 401 未授权响应
     */
    protected function unauthorizedResponse(): Html
    {
        $response = $this->createResponse('Unauthorized', 'text/plain');
        $response->code(401);
        $response->header(['WWW-Authenticate' => 'Basic realm="SQLite Tunnel"']);
        return $response;
    }
    
    /**
     * 显示测试页面
     */
    protected function showTestPage(): Html
    {
        if (!$this->allowTestMenu) {
            return $this->binaryResponse($this->echoHeader(202) . $this->getBlock('Test menu disabled'));
        }
        
        $html = $this->getTestPageHtml();
        return $this->createResponse($html, 'text/html; charset=utf-8');
    }
    
    /**
     * 处理隧道请求
     */
    protected function handleTunnelRequest(App $app): Html
    {
        $request = $app->request;
        
        $action = $request->post('actn');
        $dbFile = $request->post('dbfile');
        $queries = $request->post('q', []);
        
        // PHP 版本检查
        if (PHP_VERSION_ID < 50000) {
            return $this->binaryResponse($this->echoHeader(201) . $this->getBlock('unsupported php version'));
        }
        
        // 参数检查
        if (empty($action) || empty($dbFile)) {
            if (!$this->allowTestMenu) {
                return $this->binaryResponse($this->echoHeader(202) . $this->getBlock('invalid parameters'));
            }
            return $this->showTestPage();
        }
        
        // 安全检查
        if (!$this->isDatabaseAllowed($dbFile)) {
            return $this->binaryResponse($this->echoHeader(202) . $this->getBlock('Database access denied'));
        }
        
        // Base64 解码
        if ($request->post('encodeBase64') === '1') {
            $queries = array_map('base64_decode', (array)$queries);
        }
        
        // 检查数据库文件状态
        $status = $this->getDatabaseStatus($dbFile);
        
        // 创建/压缩数据库
        if ($action === '2' || $action === '3') {
            if ($status === self::FILE_DOES_NOT_EXIST) {
                return $this->binaryResponse($this->handleSQLite3($action, $dbFile, $queries));
            }
            return $this->binaryResponse($this->echoHeader(202) . $this->getBlock('Database file exists already'));
        }
        
        // 根据状态处理
        switch ($status) {
            case self::FILE_DOES_NOT_EXIST:
                return $this->binaryResponse($this->echoHeader(202) . $this->getBlock('Database file does not exist'));
            case self::FILE_CANNOT_BE_OPENED:
                return $this->binaryResponse($this->echoHeader(202) . $this->getBlock('Database file cannot be opened'));
            case self::FILE_IS_SQLITE2:
                return $this->binaryResponse($this->handleSQLite2($action, $dbFile, $queries));
            case self::FILE_IS_SQLITE3:
                return $this->binaryResponse($this->handleSQLite3($action, $dbFile, $queries));
            case self::FILE_IS_INVALID:
                return $this->binaryResponse($this->echoHeader(202) . $this->getBlock('Database file is encrypted or invalid'));
            default:
                return $this->binaryResponse($this->echoHeader(202) . $this->getBlock('Unknown error'));
        }
    }
    
    /**
     * 检查数据库是否允许访问
     */
    protected function isDatabaseAllowed(string $dbFile): bool
    {
        // 安全检查：防止路径遍历攻击
        if (str_contains($dbFile, '..') || str_contains($dbFile, "\x00")) {
            return false;
        }
        
        // 如果设置了数据库根目录，检查文件是否在目录内
        if ($this->databaseRoot !== null) {
            $realPath = realpath($dbFile);
            $rootPath = realpath($this->databaseRoot);
            if ($realPath === false || $rootPath === false || !str_starts_with($realPath, $rootPath)) {
                return false;
            }
        }
        
        // 如果设置了允许列表，检查是否在列表中
        if (!empty($this->allowedDatabases)) {
            $realFile = realpath($dbFile);
            if ($realFile === false) {
                return false;
            }
            $allowed = false;
            foreach ($this->allowedDatabases as $allowedDb) {
                if (realpath($allowedDb) === $realFile) {
                    $allowed = true;
                    break;
                }
            }
            if (!$allowed) {
                return false;
            }
        }
        
        return true;
    }
    
    /**
     * 获取数据库文件状态
     */
    protected function getDatabaseStatus(string $file): int
    {
        if (!is_file($file)) {
            return self::FILE_DOES_NOT_EXIST;
        }
        
        $fhandle = fopen($file, 'r');
        if (!$fhandle) {
            return self::FILE_CANNOT_BE_OPENED;
        }
        
        $sqlite2header = "** This file contains an SQLite 2.1 database **";
        $sqlite3header = "SQLite format 3";
        $header = fread($fhandle, strlen($sqlite2header));
        fclose($fhandle);
        
        if (strncmp($header, $sqlite2header, strlen($sqlite2header)) === 0) {
            return self::FILE_IS_SQLITE2;
        }
        
        if ($header === '' || strncmp($header, $sqlite3header, strlen($sqlite3header)) === 0) {
            return self::FILE_IS_SQLITE3;
        }
        
        return self::FILE_IS_INVALID;
    }
    
    /**
     * 处理 SQLite3 数据库
     */
    protected function handleSQLite3(string $action, string $path, array $queries): string
    {
        if (!class_exists('SQLite3')) {
            return $this->echoHeader(203) . $this->getBlock('SQLite3 is not supported on the server');
        }
        
        $flag = SQLITE3_OPEN_READWRITE;
        if ($action === '3') {
            $flag = SQLITE3_OPEN_READWRITE | SQLITE3_OPEN_CREATE;
        }
        
        $conn = @new \SQLite3($path, $flag);
        if (!$conn) {
            return $this->echoHeader(202) . $this->getBlock('Failed to open database');
        }
        
        $output = $this->echoHeader(0);
        
        switch ($action) {
            case 'C':
                $output .= $this->echoConnInfo3();
                break;
            case '3':
                $conn->query('VACUUM');
                $output .= $this->echoConnInfo3();
                break;
            case 'Q':
                $output .= $this->executeQueries3($conn, $queries);
                break;
        }
        
        $conn->close();
        return $output;
    }
    
    /**
     * 处理 SQLite2 数据库
     */
    protected function handleSQLite2(string $action, string $path, array $queries): string
    {
        if (!function_exists('sqlite_open')) {
            return $this->echoHeader(203) . $this->getBlock('SQLite2 is not supported on the server');
        }
        
        $error = '';
        $conn = @sqlite_open($path, 0666, $error);
        if (!$conn) {
            return $this->echoHeader(202) . $this->getBlock($error ?: 'Failed to open database');
        }
        
        $output = $this->echoHeader(0);
        
        switch ($action) {
            case 'C':
                $output .= $this->echoConnInfo();
                break;
            case '2':
                sqlite_query($conn, 'VACUUM');
                $output .= $this->echoConnInfo();
                break;
            case 'Q':
                $output .= $this->executeQueries2($conn, $queries);
                break;
        }
        
        sqlite_close($conn);
        return $output;
    }
    
    /**
     * 执行 SQLite3 查询
     */
    protected function executeQueries3(\SQLite3 $conn, array $queries): string
    {
        $output = '';
        $count = count($queries);
        
        foreach ($queries as $i => $query) {
            if ($query === '') continue;
            
            $res = $conn->query($query);
            $errno = $conn->lastErrorCode();
            $affectedRows = $conn->changes();
            $insertId = $conn->lastInsertRowID();
            $numFields = 0;
            $numRows = 0;
            
            if ($res instanceof \SQLite3Result) {
                $numFields = $res->numColumns();
                if ($numFields > 0) {
                    while ($res->fetchArray(SQLITE3_NUM)) {
                        $numRows++;
                    }
                    $res->reset();
                }
            }
            
            $output .= $this->echoResultSetHeader($errno, $affectedRows, $insertId, $numFields, $numRows);
            
            if ($errno !== 0) {
                $output .= $this->getBlock($conn->lastErrorMsg());
            } elseif ($numFields > 0) {
                $output .= $this->echoFieldsHeader3($res, $numFields);
                $output .= $this->echoData3($res, $numFields, $numRows);
                $res->finalize();
            } else {
                $output .= $this->getBlock('');
            }
            
            $output .= ($i < $count - 1) ? "\x01" : "\x00";
        }
        
        return $output;
    }
    
    /**
     * 执行 SQLite2 查询
     */
    protected function executeQueries2($conn, array $queries): string
    {
        $output = '';
        $count = count($queries);
        
        foreach ($queries as $i => $query) {
            if ($query === '') continue;
            
            $res = sqlite_query($conn, $query);
            $errno = sqlite_last_error($conn);
            $affectedRows = sqlite_changes($conn);
            $insertId = sqlite_last_insert_rowid($conn);
            $numFields = sqlite_num_fields($res);
            $numRows = sqlite_num_rows($res);
            
            $output .= $this->echoResultSetHeader($errno, $affectedRows, $insertId, $numFields, $numRows);
            
            if ($errno !== 0) {
                $output .= $this->getBlock(sqlite_error_string($errno));
            } elseif ($numFields > 0) {
                $output .= $this->echoFieldsHeader($res, $numFields);
                $output .= $this->echoData($res, $numFields, $numRows);
            } else {
                $output .= $this->getBlock('');
            }
            
            $output .= ($i < $count - 1) ? "\x01" : "\x00";
        }
        
        return $output;
    }
    
    // ==================== 二进制协议辅助方法 ====================
    
    protected function getLongBinary(int $num): string
    {
        return pack('N', $num);
    }
    
    protected function getShortBinary(int $num): string
    {
        return pack('n', $num);
    }
    
    protected function getDummy(int $count): string
    {
        return str_repeat("\x00", $count);
    }
    
    protected function getBlock(string $val): string
    {
        $len = strlen($val);
        if ($len < 254) {
            return chr($len) . $val;
        }
        return "\xFE" . $this->getLongBinary($len) . $val;
    }
    
    protected function echoHeader(int $errno): string
    {
        return $this->getLongBinary(1111)
            . $this->getShortBinary(self::VERSION)
            . $this->getLongBinary($errno)
            . $this->getDummy(6);
    }
    
    protected function echoConnInfo(): string
    {
        $version = sqlite_libversion();
        return $this->getBlock($version)
            . $this->getBlock($version)
            . $this->getBlock($version);
    }
    
    protected function echoConnInfo3(): string
    {
        $version = \SQLite3::version();
        $v = $version['versionString'];
        return $this->getBlock($v)
            . $this->getBlock($v)
            . $this->getBlock($v);
    }
    
    protected function echoResultSetHeader(int $errno, int $affectedRows, int $insertId, int $numFields, int $numRows): string
    {
        return $this->getLongBinary($errno)
            . $this->getLongBinary($affectedRows)
            . $this->getLongBinary($insertId)
            . $this->getLongBinary($numFields)
            . $this->getLongBinary($numRows)
            . $this->getDummy(12);
    }
    
    protected function echoFieldsHeader($res, int $numFields): string
    {
        $str = '';
        for ($i = 0; $i < $numFields; $i++) {
            $str .= $this->getBlock(sqlite_field_name($res, $i));
            $str .= $this->getBlock('');
            $str .= $this->getLongBinary(-2); // SQLITE_TEXT
            $str .= $this->getLongBinary(0);
            $str .= $this->getLongBinary(0);
        }
        return $str;
    }
    
    protected function echoFieldsHeader3(\SQLite3Result $res, int $numFields): string
    {
        $str = '';
        for ($i = 0; $i < $numFields; $i++) {
            $str .= $this->getBlock($res->columnName($i));
            $str .= $this->getBlock('');
            $str .= $this->getLongBinary(SQLITE3_NULL);
            $str .= $this->getLongBinary(0);
            $str .= $this->getLongBinary(0);
        }
        return $str;
    }
    
    protected function echoData($res, int $numFields, int $numRows): string
    {
        $str = '';
        for ($i = 0; $i < $numRows; $i++) {
            $row = sqlite_fetch_array($res, SQLITE_NUM);
            for ($j = 0; $j < $numFields; $j++) {
                if (is_null($row[$j])) {
                    $str .= "\xFF";
                } else {
                    $str .= $this->getBlock($row[$j]);
                }
                $str .= $this->getLongBinary(-2);
            }
        }
        return $str;
    }
    
    protected function echoData3(\SQLite3Result $res, int $numFields, int $numRows): string
    {
        $str = '';
        while ($row = $res->fetchArray(SQLITE3_NUM)) {
            for ($j = 0; $j < $numFields; $j++) {
                if (is_null($row[$j])) {
                    $str .= "\xFF";
                } else {
                    $str .= $this->getBlock((string)$row[$j]);
                }
                $str .= $this->getLongBinary($res->columnType($j));
            }
        }
        return $str;
    }
    
    /**
     * 创建二进制响应
     */
    protected function binaryResponse(string $content): Html
    {
        return $this->createResponse($content, 'text/plain; charset=x-user-defined');
    }
    
    /**
     * 创建响应对象
     */
    protected function createResponse(string $content, string $contentType): Html
    {
        $cookie = $this->app->has('cookie') ? $this->app->cookie : new Cookie($this->app->config);
        $response = new Html($cookie, $content, 200);
        $response->contentType($contentType);
        return $response;
    }
    
    /**
     * 获取测试页面 HTML
     */
    protected function getTestPageHtml(): string
    {
        // 检测 SQLite2 支持
        $sqlite2PhpVersion = PHP_VERSION_ID >= 50000;
        $sqlite2Available = function_exists('sqlite_open');
        
        // 检测 SQLite3 支持
        $sqlite3PhpVersion = PHP_VERSION_ID >= 50300;
        $sqlite3Available = class_exists('SQLite3');
        
        // 格式化 PHP 版本
        $phpVersion = PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION . '.' . PHP_RELEASE_VERSION;
        
        return <<<HTML
<!DOCTYPE html>
<html>
<head>
    <title>Navicat HTTP Tunnel Tester</title>
    <meta charset="utf-8">
    <style>
        body {
            margin: 30px;
            font-family: Tahoma, sans-serif;
            font-size: 14px;
            color: #222;
        }
        .title {
            font-size: 30px;
            color: #036;
        }
        .subtitle {
            font-size: 10px;
            color: #996;
        }
        fieldset {
            border: 1px solid #666;
            margin-bottom: 20px;
        }
        legend {
            font-weight: bold;
        }
        table {
            width: 100%;
        }
        td {
            padding: 3px 5px;
        }
        input[type="text"], input[type="password"] {
            width: 300px;
            padding: 5px;
            border: 1px solid #666;
        }
        input[type="submit"] {
            padding: 8px 16px;
        }
        .success { color: #0B0; font-weight: bold; }
        .error { color: #D00; font-weight: bold; }
        #page {
            max-width: 42em;
            min-width: 36em;
            margin: auto;
        }
        .copyright {
            text-align: right;
            font-size: 10px;
            color: #999;
            margin-top: 20px;
        }
    </style>
    <script>
    function doServerTest() {
        var form = document.getElementById('TestServerForm');
        var dbfile = document.getElementById('dbfile').value;
        var params = 'actn=C&dbfile=' + encodeURIComponent(dbfile);
        
        document.getElementById('ServerTest').innerHTML = 'Connecting...';
        
        fetch(window.location.href, {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: params
        })
        .then(r => r.arrayBuffer())
        .then(data => {
            var view = new DataView(data);
            var errno = view.getInt32(6, false); // big-endian, offset 6
            var outputDiv = document.getElementById('ServerTest');
            if (errno === 0) {
                outputDiv.innerHTML = '<span class="success">Connection Success!</span>';
            } else {
                outputDiv.innerHTML = '<span class="error">Error: ' + errno + '</span>';
            }
        })
        .catch(err => {
            document.getElementById('ServerTest').innerHTML = '<span class="error">HTTP Error: ' + err + '</span>';
        });
        
        return false;
    }
    </script>
</head>
<body>
<div id="page">
    <p><span class="title">Navicat™</span><br><span class="subtitle">The gateway to your database!</span></p>
    
    <fieldset>
        <legend>System Environment Test</legend>
        <table>
            <tr>
                <td>[SQLite2] PHP version &gt;= 5.0.0</td>
                <td align="right">
                    {$this->formatTestResult($sqlite2PhpVersion, $phpVersion)}
                </td>
            </tr>
            <tr>
                <td>[SQLite2] sqlite_open() available</td>
                <td align="right">
                    {$this->formatTestResult($sqlite2Available)}
                </td>
            </tr>
            <tr>
                <td>[SQLite3] PHP version &gt;= 5.3.0</td>
                <td align="right">
                    {$this->formatTestResult($sqlite3PhpVersion, $phpVersion)}
                </td>
            </tr>
            <tr>
                <td>[SQLite3] SQLite3 class available</td>
                <td align="right">
                    {$this->formatTestResult($sqlite3Available)}
                </td>
            </tr>
            <tr>
                <td>Current tunnel file version</td>
                <td align="right">{$this->getVersionString()}</td>
            </tr>
        </table>
    </fieldset>
    
    <fieldset>
        <legend>Server Test</legend>
        <form id="TestServerForm" onsubmit="return doServerTest();">
            <table>
                <tr>
                    <td>Database File:</td>
                    <td><input type="text" id="dbfile" name="dbfile" value="" placeholder="path/to/database.sqlite"></td>
                </tr>
                <tr>
                    <td></td>
                    <td><input type="submit" value="Test Connection"></td>
                </tr>
            </table>
        </form>
        <div id="ServerTest"></div>
    </fieldset>
    
    <p class="copyright">Copyright © PremiumSoft™ CyberTech Ltd. All Rights Reserved.</p>
</div>
</body>
</html>
HTML;
    }
    
    /**
     * 格式化测试结果
     */
    protected function formatTestResult(bool $success, ?string $extra = null): string
    {
        if ($success) {
            $text = 'Yes';
            if ($extra !== null) {
                $text .= ' (' . htmlspecialchars($extra) . ')';
            }
            return '<span class="success">' . $text . '</span>';
        } else {
            return '<span class="error">No</span>';
        }
    }
    
    /**
     * 获取版本字符串
     */
    protected function getVersionString(): string
    {
        $major = intval(self::VERSION / 100);
        $minor = self::VERSION % 100;
        return $major . '.' . $minor;
    }
    
    /**
     * 设置是否允许测试页面
     */
    public function setAllowTestMenu(bool $allow): self
    {
        $this->allowTestMenu = $allow;
        return $this;
    }
    
    /**
     * 设置数据库根目录
     */
    public function setDatabaseRoot(?string $path): self
    {
        $this->databaseRoot = $path;
        return $this;
    }
    
    /**
     * 设置允许的数据库列表
     */
    public function setAllowedDatabases(array $databases): self
    {
        $this->allowedDatabases = $databases;
        return $this;
    }
    
    /**
     * 设置 Basic Auth 认证
     * 
     * @param string|null $username 用户名，设置为 null 禁用认证
     * @param string|null $password 密码
     */
    public function setBasicAuth(?string $username, ?string $password = null): self
    {
        $this->authUsername = $username;
        $this->authPassword = $password;
        return $this;
    }
}
