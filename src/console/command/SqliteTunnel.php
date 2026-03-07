<?php
declare(strict_types=1);

namespace yangweijie\orm\sqlite\remote\console\command;

use think\console\Command;
use think\console\Input;
use think\console\Output;
use think\console\input\Option;
use yangweijie\orm\sqlite\remote\Server\TunnelServer;
use Psl\Async;

/**
 * SQLite Tunnel 守护进程命令
 * 
 * 启动方式：
 *   php think sqlite-tunnel:start
 *   php think sqlite-tunnel:start --port=9527
 *   php think sqlite-tunnel:start --host=0.0.0.0 --port=9527
 *   php think sqlite-tunnel:start --auth-username=admin --auth-password=secret
 *   php think sqlite-tunnel:start --database-root=/data/sqlite
 * 
 * WAL 模式配置（高并发推荐）：
 *   php think sqlite-tunnel:start --wal-mode=WAL --busy-timeout=5000
 */
class SqliteTunnel extends Command
{
    protected function configure(): void
    {
        $this->setName('sqlite-tunnel:start')
            ->setDescription('Start SQLite Tunnel Server (connection pool daemon for transaction support)')
            ->addOption('host', 'H', Option::VALUE_OPTIONAL, 'Listen host', '127.0.0.1')
            ->addOption('port', 'p', Option::VALUE_OPTIONAL, 'Listen port', 9527)
            ->addOption('auth-username', 'u', Option::VALUE_OPTIONAL, 'Basic Auth username')
            ->addOption('auth-password', 'P', Option::VALUE_OPTIONAL, 'Basic Auth password')
            ->addOption('database-root', 'd', Option::VALUE_OPTIONAL, 'Database root directory (security limit)')
            ->addOption('idle-timeout', 't', Option::VALUE_OPTIONAL, 'Idle connection timeout (seconds)', 300)
            ->addOption('max-connections', 'm', Option::VALUE_OPTIONAL, 'Max connections', 100)
            // WAL 配置
            ->addOption('wal-mode', null, Option::VALUE_OPTIONAL, 'Journal mode (WAL/DELETE)', 'WAL')
            ->addOption('busy-timeout', null, Option::VALUE_OPTIONAL, 'Lock wait timeout (ms)', 5000)
            ->addOption('wal-checkpoint', null, Option::VALUE_OPTIONAL, 'WAL auto checkpoint threshold', 1000)
            ->addOption('synchronous', null, Option::VALUE_OPTIONAL, 'Sync mode (OFF/NORMAL/FULL)', 'NORMAL')
            ->addOption('transaction-timeout', null, Option::VALUE_OPTIONAL, 'Transaction timeout (seconds)', 60);
    }

    protected function execute(Input $input, Output $output): int
    {
        $host = $input->getOption('host');
        $port = (int) $input->getOption('port');
        $authUsername = $input->getOption('auth-username');
        $authPassword = $input->getOption('auth-password');
        $databaseRoot = $input->getOption('database-root');
        $idleTimeout = (int) $input->getOption('idle-timeout');
        $maxConnections = (int) $input->getOption('max-connections');

        // WAL 配置
        $walMode = $input->getOption('wal-mode');
        $busyTimeout = (int) $input->getOption('busy-timeout');
        $walCheckpoint = (int) $input->getOption('wal-checkpoint');
        $synchronous = $input->getOption('synchronous');
        $transactionTimeout = (int) $input->getOption('transaction-timeout');

        $output->writeln("");
        $output->writeln("<info>SQLite Tunnel Server</info>");
        $output->writeln("<comment>────────────────────────────────────</comment>");
        $output->writeln("  <info>Host:</info>            {$host}");
        $output->writeln("  <info>Port:</info>            {$port}");
        $output->writeln("  <info>Idle Timeout:</info>    {$idleTimeout}s");
        $output->writeln("  <info>Max Connections:</info> {$maxConnections}");

        if ($authUsername) {
            $output->writeln("  <info>Auth:</info>            {$authUsername}:****");
        }
        if ($databaseRoot) {
            $output->writeln("  <info>Database Root:</info>   {$databaseRoot}");
        }

        // WAL 配置显示
        $output->writeln("<comment>── WAL Configuration ────────────────</comment>");
        $output->writeln("  <info>Journal Mode:</info>     {$walMode}");
        $output->writeln("  <info>Busy Timeout:</info>     {$busyTimeout}ms");
        $output->writeln("  <info>Checkpoint:</info>       every {$walCheckpoint} pages");
        $output->writeln("  <info>Synchronous:</info>      {$synchronous}");
        $output->writeln("  <info>Txn Timeout:</info>      {$transactionTimeout}s");
        $output->writeln("<comment>────────────────────────────────────</comment>");
        $output->writeln("");

        $walConfig = [
            'journal_mode' => $walMode,
            'busy_timeout' => $busyTimeout,
            'wal_autocheckpoint' => $walCheckpoint,
            'synchronous' => $synchronous,
            'transaction_timeout' => $transactionTimeout,
        ];

        // 使用 psl 异步运行
        Async\main(function () use (
            $host,
            $port,
            $authUsername,
            $authPassword,
            $databaseRoot,
            $idleTimeout,
            $maxConnections,
            $walConfig,
            $output
        ): int {
            $server = new TunnelServer(
                host: $host,
                port: $port,
                maxConnections: $maxConnections,
                idleTimeout: $idleTimeout,
                output: $output
            );

            if ($authUsername && $authPassword) {
                $server->setAuth($authUsername, $authPassword);
            }

            if ($databaseRoot) {
                $server->setDatabaseRoot($databaseRoot);
            }

            $server->setWalConfig($walConfig);

            $server->start();

            return 0;
        });

        return 0;
    }
}
