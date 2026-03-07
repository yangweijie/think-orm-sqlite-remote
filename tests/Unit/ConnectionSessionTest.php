<?php

use yangweijie\orm\sqlite\remote\Server\ConnectionSession;

beforeEach(function () {
    // 每个测试使用唯一的数据库文件
    $this->dbPath = __DIR__ . '/../../test_' . uniqid() . '.db';
});

afterEach(function () {
    // 清理测试数据库
    if (isset($this->dbPath)) {
        if (file_exists($this->dbPath)) unlink($this->dbPath);
        if (file_exists($this->dbPath . '-wal')) unlink($this->dbPath . '-wal');
        if (file_exists($this->dbPath . '-shm')) unlink($this->dbPath . '-shm');
    }
});

describe('ConnectionSession WAL Configuration', function () {
    it('applies default WAL config on creation', function () {
        $conn = new SQLite3($this->dbPath, SQLITE3_OPEN_READWRITE | SQLITE3_OPEN_CREATE);
        $session = new ConnectionSession($conn, $this->dbPath);

        $journalMode = $conn->querySingle('PRAGMA journal_mode;');
        expect($journalMode)->toBe('wal');

        $busyTimeout = $conn->querySingle('PRAGMA busy_timeout;');
        expect($busyTimeout)->toBe(5000);

        $synchronous = $conn->querySingle('PRAGMA synchronous;');
        expect($synchronous)->toBe(1); // NORMAL = 1

        $session->close();
    });

    it('applies custom WAL config', function () {
        $conn = new SQLite3($this->dbPath, SQLITE3_OPEN_READWRITE | SQLITE3_OPEN_CREATE);
        $session = new ConnectionSession($conn, $this->dbPath, [
            'busy_timeout' => 10000,
            'journal_mode' => 'DELETE',
            'synchronous' => 'FULL',
            'wal_autocheckpoint' => 500,
        ]);

        $journalMode = $conn->querySingle('PRAGMA journal_mode;');
        expect($journalMode)->toBe('delete');

        $busyTimeout = $conn->querySingle('PRAGMA busy_timeout;');
        expect($busyTimeout)->toBe(10000);

        $synchronous = $conn->querySingle('PRAGMA synchronous;');
        expect($synchronous)->toBe(2); // FULL = 2

        $session->close();
    });
});

describe('ConnectionSession Transaction', function () {
    it('begins transaction with IMMEDIATE lock', function () {
        $conn = new SQLite3($this->dbPath, SQLITE3_OPEN_READWRITE | SQLITE3_OPEN_CREATE);
        $session = new ConnectionSession($conn, $this->dbPath);

        $result = $session->begin();
        expect($result['errno'])->toBe(0);
        expect($session->isInTransaction())->toBeTrue();

        $session->close();
    });

    it('commits transaction successfully', function () {
        $conn = new SQLite3($this->dbPath, SQLITE3_OPEN_READWRITE | SQLITE3_OPEN_CREATE);
        $session = new ConnectionSession($conn, $this->dbPath);

        $session->begin();
        $session->query('CREATE TABLE test (id INTEGER PRIMARY KEY, name TEXT)');
        $session->query("INSERT INTO test (name) VALUES ('hello')");

        $result = $session->commit();
        expect($result['errno'])->toBe(0);
        expect($session->isInTransaction())->toBeFalse();

        // Verify data persisted
        $result = $session->query('SELECT * FROM test');
        expect($result['rows'])->toHaveCount(1);
        expect($result['rows'][0]['name'])->toBe('hello');

        $session->close();
    });

    it('rolls back transaction successfully', function () {
        $conn = new SQLite3($this->dbPath, SQLITE3_OPEN_READWRITE | SQLITE3_OPEN_CREATE);
        $session = new ConnectionSession($conn, $this->dbPath);

        $session->begin();
        $session->query('CREATE TABLE test (id INTEGER PRIMARY KEY, name TEXT)');
        $session->query("INSERT INTO test (name) VALUES ('original')");
        $session->commit();

        $session->begin();
        $session->query("INSERT INTO test (name) VALUES ('should_be_rolled_back')");
        $result = $session->rollback();

        expect($result['errno'])->toBe(0);
        expect($session->isInTransaction())->toBeFalse();

        // Verify rollback worked
        $result = $session->query('SELECT * FROM test');
        expect($result['rows'])->toHaveCount(1);
        expect($result['rows'][0]['name'])->toBe('original');

        $session->close();
    });

    it('detects transaction timeout', function () {
        $conn = new SQLite3($this->dbPath, SQLITE3_OPEN_READWRITE | SQLITE3_OPEN_CREATE);
        $session = new ConnectionSession($conn, $this->dbPath, [
            'transaction_timeout' => 1, // 1 second
        ]);

        $session->begin();
        expect($session->isTransactionTimeout())->toBeFalse();

        sleep(2);
        expect($session->isTransactionTimeout())->toBeTrue();

        $session->close();
    });

    it('auto rolls back on close when in transaction', function () {
        $conn = new SQLite3($this->dbPath, SQLITE3_OPEN_READWRITE | SQLITE3_OPEN_CREATE);
        $session = new ConnectionSession($conn, $this->dbPath);

        $session->begin();
        $session->query('CREATE TABLE test (id INTEGER PRIMARY KEY, name TEXT)');
        $session->query("INSERT INTO test (name) VALUES ('original')");
        $session->commit();

        $session->begin();
        $session->query("INSERT INTO test (name) VALUES ('should_be_rolled_back')");

        // Close without commit
        $session->close();

        // Reopen and verify
        $conn2 = new SQLite3($this->dbPath, SQLITE3_OPEN_READWRITE);
        $result = $conn2->querySingle('SELECT COUNT(*) FROM test');
        expect($result)->toBe(1); // Only original row
        $conn2->close();
    });
});

describe('ConnectionSession Query', function () {
    it('executes SELECT query', function () {
        $conn = new SQLite3($this->dbPath, SQLITE3_OPEN_READWRITE | SQLITE3_OPEN_CREATE);
        $session = new ConnectionSession($conn, $this->dbPath);

        $session->query('CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT, email TEXT)');
        $session->query("INSERT INTO users (name, email) VALUES ('john', 'john@example.com')");
        $session->query("INSERT INTO users (name, email) VALUES ('jane', 'jane@example.com')");

        $result = $session->query('SELECT * FROM users ORDER BY id');

        expect($result['errno'])->toBe(0);
        expect($result['rows'])->toHaveCount(2);
        expect($result['fields'])->toBe(['id', 'name', 'email']);

        $session->close();
    });

    it('executes INSERT query and returns insert_id', function () {
        $conn = new SQLite3($this->dbPath, SQLITE3_OPEN_READWRITE | SQLITE3_OPEN_CREATE);
        $session = new ConnectionSession($conn, $this->dbPath);

        $session->query('CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT)');

        $result = $session->query("INSERT INTO users (name) VALUES ('test')");

        expect($result['errno'])->toBe(0);
        expect($result['insert_id'])->toBe(1);
        expect($result['affected_rows'])->toBe(1);

        $session->close();
    });

    it('executes UPDATE query and returns affected_rows', function () {
        $conn = new SQLite3($this->dbPath, SQLITE3_OPEN_READWRITE | SQLITE3_OPEN_CREATE);
        $session = new ConnectionSession($conn, $this->dbPath);

        $session->query('CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT)');
        $session->query("INSERT INTO users (name) VALUES ('john')");
        $session->query("INSERT INTO users (name) VALUES ('jane')");

        $result = $session->query("UPDATE users SET name = 'updated' WHERE id = 1");

        expect($result['errno'])->toBe(0);
        expect($result['affected_rows'])->toBe(1);

        $session->close();
    });

    it('handles SQL syntax error gracefully', function () {
        $conn = new SQLite3($this->dbPath, SQLITE3_OPEN_READWRITE | SQLITE3_OPEN_CREATE);
        $session = new ConnectionSession($conn, $this->dbPath);

        // 设置错误处理器来捕获 warning
        set_error_handler(function ($errno, $errstr) {
            return true; // 抑制 warning
        });

        $result = $session->query('INVALID SQL SYNTAX');

        restore_error_handler();

        expect($result['errno'])->not->toBe(0);
        expect($result['error'])->toBeString();

        $session->close();
    });
});

describe('ConnectionSession Checkpoint', function () {
    it('executes checkpoint in WAL mode', function () {
        $conn = new SQLite3($this->dbPath, SQLITE3_OPEN_READWRITE | SQLITE3_OPEN_CREATE);
        $session = new ConnectionSession($conn, $this->dbPath, [
            'journal_mode' => 'WAL',
        ]);

        $session->query('CREATE TABLE test (id INTEGER PRIMARY KEY)');
        $session->query('INSERT INTO test VALUES (1)');

        // WAL file should exist
        expect(file_exists($this->dbPath . '-wal'))->toBeTrue();

        $result = $session->checkpoint('PASSIVE');
        expect($result['errno'])->toBe(0);

        $session->close();
    });
});