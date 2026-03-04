<?php
declare(strict_types=1);

namespace yangweijie\orm\sqlite\remote\console\command\optimize;

use think\console\Command;
use think\console\Input;
use think\console\input\Option;
use think\console\Output;
use yangweijie\orm\sqlite\remote\Connection;

/**
 * 远程 SQLite Schema 缓存生成命令
 * 
 * 用法:
 *   php think optimize:schema-remote --connection=remote-sqlite
 *   php think optimize:schema-remote --connection=remote-sqlite --table=users
 *   php think optimize:schema-remote --connection=remote-sqlite --table=*
 */
class SchemaRemote extends Command
{
    protected function configure()
    {
        $this->setName('optimize:schema-remote')
            ->addOption('connection', 'c', Option::VALUE_REQUIRED, 'Connection name', 'remote-sqlite')
            ->addOption('table', 't', Option::VALUE_OPTIONAL, 'Table name (use * for all tables)', null)
            ->setDescription('Build remote SQLite schema cache.');
    }

    protected function execute(Input $input, Output $output)
    {
        $connectionName = $input->getOption('connection');
        $tableName = $input->getOption('table');

        try {
            $connection = $this->app->db->connect($connectionName);
        } catch (\Throwable $e) {
            return $output->error("Connection '{$connectionName}' not found: " . $e->getMessage());
        }

        if (!$connection instanceof Connection) {
            return $output->error("Connection '{$connectionName}' is not a remote SQLite connection.");
        }

        // 检查是否配置了缓存
        if (!$connection->getCache()) {
            $output->comment('No cache configured. Schema will only be cached in memory.');
            $output->comment('To enable persistent cache, configure a PSR-16 cache implementation.');
        }

        // 获取要缓存的表
        if ($tableName === '*') {
            $tables = $connection->getTables();
        } elseif ($tableName) {
            $tables = [$tableName];
        } else {
            // 默认缓存所有表
            $tables = $connection->getTables();
        }

        $output->info('Building schema cache for ' . count($tables) . ' table(s)...');

        $cached = 0;
        foreach ($tables as $table) {
            try {
                $info = $connection->getSchemaInfo($table, true);
                $fieldCount = count($info['fields'] ?? []);
                $output->writeln("  - {$table}: {$fieldCount} fields");
                $cached++;
            } catch (\Throwable $e) {
                $output->error("  - {$table}: " . $e->getMessage());
            }
        }

        $output->info("Successfully cached {$cached} table(s).");

        return 0;
    }
}
