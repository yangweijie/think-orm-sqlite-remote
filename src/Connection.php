<?php
declare(strict_types=1);

namespace yangweijie\orm\sqlite\remote;

use think\db\Connection as BaseConnection;
use think\db\builder\Sqlite as SqliteBuilder;
use think\db\exception\DbException;
use think\db\BaseQuery;

/**
 * SQLite Remote Connection via HTTP Tunnel
 *
 * 复用 think-orm 的 SQLite Builder 和 Query，
 * 仅重写查询执行部分，通过 HTTP Tunnel 远程执行
 */
class Connection extends BaseConnection
{
    protected string $tunnelUrl = '';
    protected string $dbFile = '';
    protected bool $encodeBase64 = false;
    protected int $tunnelTimeout = 30;
    protected int $lastInsertId = 0;
    
    /**
     * Basic Auth 用户名
     */
    protected ?string $authUsername = null;
    
    /**
     * Basic Auth 密码
     */
    protected ?string $authPassword = null;

    public function getQueryClass(): string
    {
        return $this->config['query'] ?? \think\db\Query::class;
    }

    public function getBuilderClass(): string
    {
        return $this->config['builder'] ?? SqliteBuilder::class;
    }

    /**
     * 初始化连接
     */
    protected function initConnect(bool $master = true): void
    {
        if (!$this->linkID) {
            $this->connect();
        }
    }

    public function connect(array $config = [], $linkNum = 0)
    {
        if (!empty($config)) {
            $this->config = array_merge($this->config, $config);
        }

        $this->tunnelUrl = $this->config['tunnel_url'] ?? '';
        $this->dbFile = $this->config['database'] ?? '';
        $this->encodeBase64 = $this->config['encode_base64'] ?? false;
        $this->tunnelTimeout = $this->config['timeout'] ?? 30;
        
        // Basic Auth 配置
        $this->authUsername = $this->config['auth_username'] ?? null;
        $this->authPassword = $this->config['auth_password'] ?? null;

        if (empty($this->tunnelUrl)) {
            throw new DbException('Tunnel URL is not configured', $this->config);
        }

        if (empty($this->dbFile)) {
            throw new DbException('Database file path is not configured', $this->config);
        }

        $this->testConnection();

        $this->links[$linkNum] = true;
        $this->linkID = true;

        return $this;
    }

    protected function testConnection(): bool
    {
        $result = $this->executeRaw('C', []);

        if ($result['errno'] !== 0) {
            throw new DbException(
                'Connection failed: ' . ($result['error'] ?? 'Unknown error'),
                $this->config,
                '',
                $result['errno']
            );
        }

        return true;
    }

    protected function executeRaw(string $action, array $queries): array
    {
        $postData = [
            'actn'   => $action,
            'dbfile' => $this->dbFile,
            'q'      => $queries,
        ];

        if ($this->encodeBase64) {
            $postData['encodeBase64'] = '1';
            $postData['q'] = array_map('base64_encode', $queries);
        }

        $ch = curl_init();

        $options = [
            CURLOPT_URL            => $this->tunnelUrl,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query($postData),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $this->tunnelTimeout,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
        ];
        
        // 添加 Basic Auth
        if ($this->authUsername !== null) {
            $options[CURLOPT_USERPWD] = $this->authUsername . ':' . ($this->authPassword ?? '');
        }
        
        curl_setopt_array($ch, $options);

        $response = curl_exec($ch);

        if (curl_errno($ch)) {
            $error = curl_error($ch);
            curl_close($ch);
            throw new DbException('HTTP request failed: ' . $error, $this->config);
        }

        curl_close($ch);

        return $this->parseResponse($response);
    }

    protected function parseResponse(string $response): array
    {
        $responseLen = strlen($response);

        if ($responseLen < 16) {
            return ['errno' => 1, 'error' => 'Invalid response (too short)'];
        }

        $offset = 0;

        // 解析头部 (16 bytes)
        $magic = unpack('N', substr($response, $offset, 4))[1];
        $offset += 4;
        $version = unpack('n', substr($response, $offset, 2))[1];
        $offset += 2;
        $errno = unpack('N', substr($response, $offset, 4))[1];
        $offset += 4;
        $offset += 6; // reserved

        // 连接测试响应（返回版本信息，没有结果集头）
        if ($errno === 0 && $responseLen < $offset + 32) {
            // 尝试读取连接信息（版本信息）
            $versionInfo = [];
            while ($offset < $responseLen) {
                [$block, $offset] = $this->readBlock($response, $offset, $responseLen);
                $versionInfo[] = $block;
            }
            return ['errno' => 0, 'error' => '', 'version_info' => $versionInfo];
        }

        // 错误响应
        if ($errno !== 0) {
            $errorMsg = '';
            if ($responseLen > $offset) {
                [$errorMsg] = $this->readBlock($response, $offset, $responseLen);
            }
            return ['errno' => $errno, 'error' => $errorMsg];
        }

        // 检查是否有结果集头（32 bytes）
        if ($responseLen < $offset + 32) {
            return ['errno' => 1, 'error' => 'Invalid response (missing result header)'];
        }

        // 解析结果集头（参考 ntunnel_sqlite.php EchoResultSetHeader）
        // 格式: errno(4) + affectedRows(4) + insertId(4) + numFields(4) + numRows(4) + dummy(12)
        $resultErrno = unpack('N', substr($response, $offset, 4))[1];
        $offset += 4;
        $affectedRows = unpack('N', substr($response, $offset, 4))[1];
        $offset += 4;
        $insertId = unpack('N', substr($response, $offset, 4))[1];
        $offset += 4;
        $numFields = unpack('N', substr($response, $offset, 4))[1];
        $offset += 4;
        $numRows = unpack('N', substr($response, $offset, 4))[1];
        $offset += 4;
        $offset += 12; // dummy

        $this->numRows = $affectedRows;
        $this->lastInsertId = $insertId;

        // 无字段，表示 update/insert/delete
        if ($numFields == 0) {
            return [
                'errno'         => 0,
                'error'         => '',
                'affected_rows' => $affectedRows,
                'insert_id'     => $insertId,
            ];
        }

        // 限制最大行数和字段数，防止内存溢出
        $numFields = min($numFields, 1000);
        $numRows = min($numRows, 100000);

        // 解析字段
        $fields = [];
        for ($i = 0; $i < $numFields && $offset < $responseLen; $i++) {
            [$name, $offset] = $this->readBlock($response, $offset, $responseLen);
            [$table, $offset] = $this->readBlock($response, $offset, $responseLen);

            if ($offset + 12 > $responseLen) break;

            $type = unpack('N', substr($response, $offset, 4))[1];
            $offset += 4;
            $flag = unpack('N', substr($response, $offset, 4))[1];
            $offset += 4;
            $length = unpack('N', substr($response, $offset, 4))[1];
            $offset += 4;

            $fields[] = compact('name', 'table', 'type', 'flag', 'length');
        }

        // 解析行数据
        $rows = [];
        $actualFields = count($fields);
        for ($r = 0; $r < $numRows && $offset < $responseLen; $r++) {
            $row = [];
            for ($f = 0; $f < $actualFields && $offset < $responseLen; $f++) {
                $fieldName = $fields[$f]['name'] ?? $f;

                // NULL 值 (0xFF 标记)
                if (ord($response[$offset]) === 0xff) {
                    $row[$fieldName] = null;
                    $offset += 5;
                    continue;
                }

                [$value, $offset] = $this->readBlock($response, $offset, $responseLen);

                // 跳过类型字段
                if ($offset + 4 <= $responseLen) {
                    $offset += 4;
                }

                $row[$fieldName] = $value;
            }
            $rows[] = $row;
        }

        return [
            'errno'         => 0,
            'error'         => '',
            'rows'          => $rows,
            'affected_rows' => $affectedRows,
            'insert_id'     => $insertId,
        ];
    }

    protected function readBlock(string $response, int $offset, int $responseLen): array
    {
        if ($offset >= $responseLen) {
            return ['', $offset];
        }

        $lenByte = ord($response[$offset]);

        if ($lenByte < 254) {
            $offset++;
            $length = $lenByte;
        } elseif ($lenByte === 254) {
            $offset++;
            if ($offset + 4 > $responseLen) {
                return ['', $offset];
            }
            $length = unpack('N', substr($response, $offset, 4))[1];
            $offset += 4;
        } else {
            return [null, $offset + 1];
        }

        // 边界检查
        if ($offset + $length > $responseLen) {
            $length = max(0, $responseLen - $offset);
        }

        $value = substr($response, $offset, $length);
        return [$value, $offset + $length];
    }

    public function query(string $sql, array $bind = [], bool $master = false): array
    {
        $this->initConnect(true);
        $this->queryStr = $sql;
        $sql = $this->getRealSql($sql, $bind);
        $this->queryStartTime = microtime(true);

        $result = $this->executeRaw('Q', [$sql]);

        if (!empty($this->config['trigger_sql'])) {
            $this->trigger('', $master);
        }

        if ($result['errno'] !== 0) {
            throw new DbException($result['error'] ?? 'Query failed', $this->config, $sql, $result['errno']);
        }

        return $result['rows'] ?? [];
    }

    public function execute(string $sql, array $bind = []): int
    {
        $this->initConnect(true);
        $this->queryStr = $sql;
        $sql = $this->getRealSql($sql, $bind);
        $this->queryStartTime = microtime(true);

        $result = $this->executeRaw('Q', [$sql]);

        if (!empty($this->config['trigger_sql'])) {
            $this->trigger('', true);
        }

        if ($result['errno'] !== 0) {
            throw new DbException($result['error'] ?? 'Execute failed', $this->config, $sql, $result['errno']);
        }

        $this->numRows = $result['affected_rows'] ?? 0;
        $this->lastInsertId = $result['insert_id'] ?? 0;

        return $this->numRows;
    }

    public function find(BaseQuery $query): array
    {
        $query->parseOptions();
        $sql = $this->builder->select($query, true);
        $result = $this->query($sql, $query->getBind());
        return $result[0] ?? [];
    }

    public function select(BaseQuery $query): array
    {
        $query->parseOptions();
        $sql = $this->builder->select($query);
        return $this->query($sql, $query->getBind());
    }

    public function insert(BaseQuery $query, bool $getLastInsID = false)
    {
        $query->parseOptions();
        $sql = $this->builder->insert($query);
        $result = '' == $sql ? 0 : $this->execute($sql, $query->getBind());

        if ($result && $getLastInsID && $this->lastInsertId) {
            return $this->lastInsertId;
        }

        return $result;
    }

    public function insertAll(BaseQuery $query, array $dataSet = []): int
    {
        if (empty($dataSet) || !is_array(reset($dataSet))) {
            return 0;
        }

        $options = $query->parseOptions();
        $limit = (int)($options['limit'] ?? 0) ?: (count($dataSet) >= 5000 ? 1000 : 0);

        if ($limit) {
            $this->startTrans();
            try {
                $count = 0;
                foreach (array_chunk($dataSet, $limit, true) as $item) {
                    $sql = $this->builder->insertAll($query, $item);
                    $count += $this->execute($sql, $query->getBind());
                }
                $this->commit();
                return $count;
            } catch (\Throwable $e) {
                $this->rollback();
                throw $e;
            }
        }

        $sql = $this->builder->insertAll($query, $dataSet);
        return $this->execute($sql, $query->getBind());
    }

    public function update(BaseQuery $query): int
    {
        $query->parseOptions();
        $sql = $this->builder->update($query);
        return '' == $sql ? 0 : $this->execute($sql, $query->getBind());
    }

    public function delete(BaseQuery $query): int
    {
        $query->parseOptions();
        $sql = $this->builder->delete($query);
        return $this->execute($sql, $query->getBind());
    }

    public function value(BaseQuery $query, string $field, $default = null)
    {
        $result = $this->find($query->field($field));
        return $result[$field] ?? $default;
    }

    public function column(BaseQuery $query, string|array $column, string $key = ''): array
    {
        $results = $this->select($query->field($column));
        if (empty($results)) return [];

        $columns = is_array($column) ? $column : array_map('trim', explode(',', $column));

        if (count($columns) === 1) {
            return $key ? array_column($results, $columns[0], $key) : array_column($results, $columns[0]);
        }

        return $key ? array_column($results, null, $key) : $results;
    }

    public function transaction(callable $callback)
    {
        $this->startTrans();
        try {
            $result = $callback($this);
            $this->commit();
            return $result;
        } catch (\Throwable $e) {
            $this->rollback();
            throw $e;
        }
    }

    public function startTrans(): void
    {
        $this->initConnect(true);
        ++$this->transTimes;
        if ($this->transTimes == 1) {
            $this->execute('BEGIN TRANSACTION');
        }
    }

    public function commit(): void
    {
        if ($this->transTimes > 0) {
            --$this->transTimes;
            $this->execute('COMMIT');
        }
    }

    public function rollback(): void
    {
        if ($this->transTimes > 0) {
            --$this->transTimes;
            $this->execute('ROLLBACK');
        }
    }

    /**
     * 取得数据表的字段信息
     */
    public function getFields(string $tableName): array
    {
        [$tableName] = explode(' ', $tableName);
        $sql = "PRAGMA table_info('{$tableName}')";
        $result = $this->query($sql);

        $info = [];
        foreach ($result as $row) {
            $row = array_change_key_case($row);
            $name = $row['name'] ?? '';
            $info[$name] = [
                'name'    => $name,
                'type'    => $row['type'] ?? 'TEXT',
                'notnull' => 1 === (int)($row['notnull'] ?? 0),
                'default' => $row['dflt_value'] ?? null,
                'primary' => '1' == ($row['pk'] ?? '0'),
                'autoinc' => '1' == ($row['pk'] ?? '0'),
            ];
        }

        return $info;
    }

    /**
     * 取得数据库的表信息
     */
    public function getTables(string $dbName = ''): array
    {
        $sql = "SELECT name FROM sqlite_master WHERE type='table' "
            . "UNION ALL SELECT name FROM sqlite_temp_master WHERE type='table' ORDER BY name";
        $result = $this->query($sql);

        $info = [];
        foreach ($result as $key => $val) {
            $info[$key] = current($val);
        }

        return $info;
    }

    /**
     * 获取表结构信息（带缓存）
     * 公开方法，支持 optimize:schema 类似功能
     */
    public function getSchemaInfo(string $tableName, bool $force = false): array
    {
        // 内存缓存
        if (isset($this->info[$tableName]) && !$force) {
            return $this->info[$tableName];
        }

        // PSR-16 缓存支持（使用 tunnel_url + dbFile 作为唯一标识）
        $cacheKey = 'sqlite_schema_' . md5($this->tunnelUrl . ':' . $this->dbFile . ':' . $tableName);
        
        if ($this->config['fields_cache'] ?? false && !empty($this->cache) && !$force) {
            $cached = $this->cache->get($cacheKey);
            if (!empty($cached)) {
                $this->info[$tableName] = $cached;
                return $cached;
            }
        }

        $fields = $this->getFields($tableName);
        $info = [];
        $pk = [];
        $autoinc = null;

        foreach ($fields as $key => $val) {
            $info[$key] = $this->getFieldType($val['type']);

            if (!empty($val['primary'])) {
                $pk[] = $key;
            }
            if (!empty($val['autoinc'])) {
                $autoinc = $key;
            }
        }

        $bind = array_map(fn($val) => $this->getFieldBindType($val), $info);

        $result = [
            'fields'  => array_keys($info),
            'type'    => $info,
            'bind'    => $bind,
            'pk'      => $pk ? (count($pk) > 1 ? $pk : $pk[0]) : null,
            'autoinc' => $autoinc,
        ];

        $this->info[$tableName] = $result;

        // 写入 PSR-16 缓存
        if (!empty($this->cache) && ($this->config['fields_cache'] ?? false)) {
            $this->cache->set($cacheKey, $result);
        }

        return $result;
    }

    /**
     * 获取数据表信息
     */
    public function getTableInfo(array|string $tableName, string $fetch = '')
    {
        if (is_array($tableName)) {
            $tableName = key($tableName) ?: current($tableName);
        }

        if (str_contains($tableName, ',') || str_contains($tableName, ')')) {
            return [];
        }

        [$tableName] = explode(' ', $tableName);
        $info = $this->getSchemaInfo($tableName);

        return $fetch && isset($info[$fetch]) ? $info[$fetch] : $info;
    }

    /**
     * 获取数据表的主键
     */
    public function getPk($tableName)
    {
        return $this->getTableInfo($tableName, 'pk');
    }

    /**
     * 获取数据表的自增主键
     */
    public function getAutoInc($tableName)
    {
        return $this->getTableInfo($tableName, 'autoinc');
    }

    /**
     * 获取数据表字段信息
     */
    public function getTableFields(string $tableName): array
    {
        return $this->getTableInfo($tableName, 'fields');
    }

    /**
     * 获取数据表字段类型
     */
    public function getFieldsType($tableName, ?string $field = null)
    {
        $result = $this->getTableInfo($tableName, 'type');

        if ($field && isset($result[$field])) {
            return $result[$field];
        }

        return $result;
    }

    /**
     * 获取数据表绑定信息
     */
    public function getFieldsBind($tableName): array
    {
        return $this->getTableInfo($tableName, 'bind');
    }

    /**
     * 获取字段类型
     */
    protected function getFieldType(string $type): string
    {
        $type = strtolower($type);

        if (str_contains($type, 'set')) return 'set';
        if (str_contains($type, 'enum')) return 'enum';
        if (str_contains($type, 'bigint')) return 'bigint';
        if (str_contains($type, 'float') || str_contains($type, 'double') ||
            str_contains($type, 'decimal') || str_contains($type, 'real') ||
            str_contains($type, 'numeric')) return 'float';
        if (str_contains($type, 'int') || str_contains($type, 'serial') ||
            str_contains($type, 'bit')) return 'int';
        if (str_contains($type, 'bool')) return 'bool';
        if (str_starts_with($type, 'timestamp')) return 'timestamp';
        if (str_starts_with($type, 'datetime')) return 'datetime';
        if (str_starts_with($type, 'date')) return 'date';

        return 'string';
    }

    /**
     * 获取字段绑定类型
     */
    public function getFieldBindType(string $type): int
    {
        return match ($type) {
            'int', 'bool' => self::PARAM_INT,
            'float' => self::PARAM_FLOAT,
            default => self::PARAM_STR,
        };
    }

    public function getLastSql(): string
    {
        return $this->queryStr;
    }

    public function getLastInsID(BaseQuery $query, ?string $sequence = null)
    {
        return $this->lastInsertId;
    }

    public function close()
    {
        $this->links = [];
        $this->linkID = null;
        return $this;
    }
}