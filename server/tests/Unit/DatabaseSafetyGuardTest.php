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
     * Test 3: Authorized Local Windows test runner identity succeeds.
     */
    public function test_authorizes_local_windows_test_runner_identity(): void
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
     * Test 4: Authorized GitHub Actions CI test runner identity succeeds when inside CI context.
     */
    public function test_authorizes_github_actions_ci_identity_when_in_ci_context(): void
    {
        $this->expectNotToPerformAssertions();
        DatabaseSafetyGuard::validateMysqlIdentity(
            database: 'charyel_test',
            user: 'charyel_test@%',
            port: 3306,
            version: '9.7.2',
            host: '127.0.0.1',
            isCiContext: true
        );
    }

    /**
     * Test 5: GitHub Actions CI profile is strictly rejected outside of CI context.
     */
    public function test_rejects_github_actions_ci_identity_outside_ci_context(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("CI test profile 'charyel_test' is strictly prohibited outside of an authenticated GitHub Actions test execution context.");

        DatabaseSafetyGuard::validateMysqlIdentity(
            database: 'charyel_test',
            user: 'charyel_test@%',
            port: 3306,
            version: '9.7.2',
            host: '127.0.0.1',
            isCiContext: false
        );
    }

    /**
     * Test 6: Spoofed CI context targeting local development database 'charyel_dev' fails closed.
     */
    public function test_rejects_spoofed_ci_context_targeting_charyel_dev(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("Target database 'charyel_dev' is explicitly protected against test operations. Destructive testing blocked.");

        DatabaseSafetyGuard::validateMysqlIdentity(
            database: 'charyel_dev',
            user: 'charyel_test@%',
            port: 3306,
            version: '9.7.2',
            host: '127.0.0.1',
            isCiContext: true
        );
    }

    /**
     * Test 7: Spoofed CI context targeting production database fails closed.
     */
    public function test_rejects_spoofed_ci_context_targeting_production(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("Target database 'charyel_production' is explicitly protected against test operations. Destructive testing blocked.");

        DatabaseSafetyGuard::validateMysqlIdentity(
            database: 'charyel_production',
            user: 'charyel_test@%',
            port: 3306,
            version: '9.7.2',
            host: '127.0.0.1',
            isCiContext: true
        );
    }

    /**
     * Test 8: Spoofed CI context targeting short name 'charyel_prod' fails closed.
     */
    public function test_rejects_spoofed_ci_context_targeting_charyel_prod(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("Target database 'charyel_prod' is explicitly protected against test operations. Destructive testing blocked.");

        DatabaseSafetyGuard::validateMysqlIdentity(
            database: 'charyel_prod',
            user: 'charyel_test@%',
            port: 3306,
            version: '9.7.2',
            host: '127.0.0.1',
            isCiContext: true
        );
    }

    /**
     * Test 9: Spoofed CI context targeting arbitrary/unknown database fails closed.
     */
    public function test_rejects_spoofed_ci_context_targeting_unknown_database(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("Active database 'charyel_staging' does not match any authorized test database");

        DatabaseSafetyGuard::validateMysqlIdentity(
            database: 'charyel_staging',
            user: 'charyel_test@%',
            port: 3306,
            version: '9.7.2',
            host: '127.0.0.1',
            isCiContext: true
        );
    }

    /**
     * Test 10: Unauthorized user on local test profile is rejected.
     */
    public function test_rejects_unauthorized_user_on_local_profile(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("Active user 'charyel_dev@127.0.0.1' is not authorized for profile 'Local Windows Testing'");

        DatabaseSafetyGuard::validateMysqlIdentity(
            database: 'charyel_schema_test',
            user: 'charyel_dev@127.0.0.1',
            port: 3308,
            version: '9.7.2',
            host: '127.0.0.1'
        );
    }

    /**
     * Test 11: Unauthorized user (e.g. root) on CI profile even in CI context is rejected.
     */
    public function test_rejects_unauthorized_user_on_ci_profile_even_in_ci_context(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("Active user 'root@%' is not authorized for profile 'GitHub Actions CI'");

        DatabaseSafetyGuard::validateMysqlIdentity(
            database: 'charyel_test',
            user: 'root@%',
            port: 3306,
            version: '9.7.2',
            host: '127.0.0.1',
            isCiContext: true
        );
    }

    /**
     * Test 12: Wrong port on local testing profile (e.g. 3306 instead of 3308) is rejected.
     */
    public function test_rejects_wrong_port_on_local_profile(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("Active port '3306' does not match required port 3308 for profile 'Local Windows Testing'.");

        DatabaseSafetyGuard::validateMysqlIdentity(
            database: 'charyel_schema_test',
            user: 'charyel_test_runner@127.0.0.1',
            port: 3306,
            version: '9.7.2',
            host: '127.0.0.1'
        );
    }

    /**
     * Test 13: Wrong port on CI profile (e.g. 3308 instead of 3306) even in CI context is rejected.
     */
    public function test_rejects_wrong_port_on_ci_profile_even_in_ci_context(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("Active port '3308' does not match required port 3306 for profile 'GitHub Actions CI'.");

        DatabaseSafetyGuard::validateMysqlIdentity(
            database: 'charyel_test',
            user: 'charyel_test@%',
            port: 3308,
            version: '9.7.2',
            host: '127.0.0.1',
            isCiContext: true
        );
    }

    /**
     * Test 14: Non-9.7.2 MySQL version is rejected across profiles.
     */
    public function test_rejects_wrong_mysql_version(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("Active MySQL version '8.0.35' does not match required version prefix '9.7.2'.");

        DatabaseSafetyGuard::validateMysqlIdentity(
            database: 'charyel_schema_test',
            user: 'charyel_test_runner@127.0.0.1',
            port: 3308,
            version: '8.0.35',
            host: '127.0.0.1'
        );
    }

    /**
     * Test 15: Remote host configuration is rejected.
     */
    public function test_rejects_remote_host(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("Configured host '192.168.1.50' is not an authorized local loopback for profile 'Local Windows Testing'");

        DatabaseSafetyGuard::validateMysqlIdentity(
            database: 'charyel_schema_test',
            user: 'charyel_test_runner@127.0.0.1',
            port: 3308,
            version: '9.7.2',
            host: '192.168.1.50'
        );
    }

    /**
     * Test 16: Production VPS host on CI profile even in CI context is rejected.
     */
    public function test_rejects_vps_host_on_ci_profile_even_in_ci_context(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("Configured host '192.210.241.55' is not an authorized local loopback for profile 'GitHub Actions CI'");

        DatabaseSafetyGuard::validateMysqlIdentity(
            database: 'charyel_test',
            user: 'charyel_test@%',
            port: 3306,
            version: '9.7.2',
            host: '192.210.241.55',
            isCiContext: true
        );
    }

    /**
     * Test 17: Local environment correctly evaluates isGitHubActionsEnvironment as false.
     */
    public function test_is_github_actions_environment_returns_false_by_default_locally(): void
    {
        // On this local machine without CI=true and GITHUB_ACTIONS=true
        $this->assertFalse(DatabaseSafetyGuard::isGitHubActionsEnvironment());
    }
}
