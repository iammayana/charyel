<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Support\DatabaseSafetyGuard;

class DatabaseSafetyGuardTest extends TestCase
{
    /**
     * Test 1: In-memory SQLite is authorized.
     */
    public function test_authorizes_sqlite_memory_database(): void
    {
        $this->expectNotToPerformAssertions();
        DatabaseSafetyGuard::validateSqliteDatabase(':memory:');
    }

    /**
     * Test 2: File-based SQLite database is rejected.
     */
    public function test_rejects_sqlite_file_database(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("SQLite database '/path/to/database.sqlite' is not ':memory:'. Destructive testing blocked.");

        DatabaseSafetyGuard::validateSqliteDatabase('/path/to/database.sqlite');
    }

    /**
     * Test 3: Authorized MySQL 9.7.2 test runner identity succeeds.
     */
    public function test_authorizes_exact_mysql_test_runner_identity(): void
    {
        $this->expectNotToPerformAssertions();
        DatabaseSafetyGuard::validateMysqlIdentity(
            database: 'charyel_schema_test',
            user: 'charyel_test_runner@127.0.0.1',
            port: 3308,
            version: '9.7.2',
            host: '127.0.0.1'
        );
    }

    /**
     * Test 4: Local development database 'charyel_dev' is strictly rejected.
     */
    public function test_rejects_charyel_dev_database(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("Active database 'charyel_dev' does not match required test database 'charyel_schema_test'.");

        DatabaseSafetyGuard::validateMysqlIdentity(
            database: 'charyel_dev',
            user: 'charyel_test_runner@127.0.0.1',
            port: 3308,
            version: '9.7.2',
            host: '127.0.0.1'
        );
    }

    /**
     * Test 5: Production database names are strictly rejected.
     */
    public function test_rejects_production_database(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("Active database 'charyel_production' does not match required test database 'charyel_schema_test'.");

        DatabaseSafetyGuard::validateMysqlIdentity(
            database: 'charyel_production',
            user: 'charyel_test_runner@127.0.0.1',
            port: 3308,
            version: '9.7.2',
            host: '127.0.0.1'
        );
    }

    /**
     * Test 6: Unauthorized database user (e.g. root or charyel_dev) is rejected.
     */
    public function test_rejects_unauthorized_database_user(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("Active user 'charyel_dev@127.0.0.1' does not match required test runner user 'charyel_test_runner@127.0.0.1'.");

        DatabaseSafetyGuard::validateMysqlIdentity(
            database: 'charyel_schema_test',
            user: 'charyel_dev@127.0.0.1',
            port: 3308,
            version: '9.7.2',
            host: '127.0.0.1'
        );
    }

    /**
     * Test 7: Standard Laragon port 3306 or other non-3308 port is rejected.
     */
    public function test_rejects_wrong_port(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("Active port '3306' does not match required test port 3308.");

        DatabaseSafetyGuard::validateMysqlIdentity(
            database: 'charyel_schema_test',
            user: 'charyel_test_runner@127.0.0.1',
            port: 3306,
            version: '9.7.2',
            host: '127.0.0.1'
        );
    }

    /**
     * Test 8: Non-9.7.2 MySQL version is rejected.
     */
    public function test_rejects_wrong_mysql_version(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("Active MySQL version '8.0.35' does not match required version '9.7.2'.");

        DatabaseSafetyGuard::validateMysqlIdentity(
            database: 'charyel_schema_test',
            user: 'charyel_test_runner@127.0.0.1',
            port: 3308,
            version: '8.0.35',
            host: '127.0.0.1'
        );
    }

    /**
     * Test 9: Remote host configuration is rejected.
     */
    public function test_rejects_remote_host(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("Configured host '192.168.1.50' is not local loopback '127.0.0.1'.");

        DatabaseSafetyGuard::validateMysqlIdentity(
            database: 'charyel_schema_test',
            user: 'charyel_test_runner@127.0.0.1',
            port: 3308,
            version: '9.7.2',
            host: '192.168.1.50'
        );
    }
}
