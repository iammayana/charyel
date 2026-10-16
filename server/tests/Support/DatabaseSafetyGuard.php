<?php

namespace Tests\Support;

use Illuminate\Support\Facades\DB;
use RuntimeException;

class DatabaseSafetyGuard
{
    public const REQUIRED_MYSQL_DATABASE = 'charyel_schema_test';
    public const REQUIRED_MYSQL_USER = 'charyel_test_runner@127.0.0.1';
    public const REQUIRED_MYSQL_PORT = 3308;
    public const REQUIRED_MYSQL_HOST = '127.0.0.1';
    public const REQUIRED_MYSQL_VERSION_PREFIX = '9.7.2';

    /**
     * Enforce that the active database connection is strictly authorized for destructive testing.
     *
     * Permitted:
     * 1. In-memory SQLite: driver === 'sqlite' and database === ':memory:'
     * 2. Isolated MySQL 9.7.2 test runner:
     *    - Driver: mysql
     *    - Database: charyel_schema_test
     *    - User: charyel_test_runner@127.0.0.1
     *    - Port: 3308
     *    - Host: 127.0.0.1
     *    - Version: 9.7.2*
     *
     * Everything else (including charyel_dev, production, port 3306, non-memory sqlite) fails closed.
     *
     * @throws RuntimeException
     */
    public static function assertDestructiveOperationsAllowed(?string $connectionName = null): void
    {
        $connection = DB::connection($connectionName);
        $driver = $connection->getDriverName();

        if ($driver === 'sqlite') {
            $database = (string) $connection->getDatabaseName();
            self::validateSqliteDatabase($database);
            return;
        }

        if ($driver === 'mysql') {
            $meta = $connection->selectOne(
                "SELECT DATABASE() as db, CURRENT_USER() as user, @@port as port, @@version as version"
            );

            if (!$meta) {
                throw new RuntimeException("DATABASE SAFETY GUARD VIOLATION: Failed to query live connection identity.");
            }

            $currentDb = (string) ($meta->db ?? '');
            $currentUser = (string) ($meta->user ?? '');
            $currentPort = (int) ($meta->port ?? 0);
            $currentVersion = (string) ($meta->version ?? '');
            $connName = $connection->getName();
            $configHost = (string) config("database.connections.{$connName}.host", '');

            self::validateMysqlIdentity($currentDb, $currentUser, $currentPort, $currentVersion, $configHost);
            return;
        }

        throw new RuntimeException(
            "DATABASE SAFETY GUARD VIOLATION: Unsupported driver '{$driver}'. Only ':memory:' SQLite or isolated MySQL test database are authorized."
        );
    }

    /**
     * Validate SQLite database path. Must strictly be ':memory:'.
     *
     * @throws RuntimeException
     */
    public static function validateSqliteDatabase(string $database): void
    {
        if ($database !== ':memory:') {
            throw new RuntimeException(
                "DATABASE SAFETY GUARD VIOLATION: SQLite database '{$database}' is not ':memory:'. Destructive testing blocked."
            );
        }
    }

    /**
     * Validate MySQL live connection parameters against the strictly authorized isolated test target.
     *
     * @throws RuntimeException
     */
    public static function validateMysqlIdentity(
        string $database,
        string $user,
        int $port,
        string $version,
        string $host
    ): void {
        $errors = [];

        if ($database !== self::REQUIRED_MYSQL_DATABASE) {
            $errors[] = "Active database '{$database}' does not match required test database '" . self::REQUIRED_MYSQL_DATABASE . "'.";
        }

        if ($user !== self::REQUIRED_MYSQL_USER) {
            $errors[] = "Active user '{$user}' does not match required test runner user '" . self::REQUIRED_MYSQL_USER . "'.";
        }

        if ($port !== self::REQUIRED_MYSQL_PORT) {
            $errors[] = "Active port '{$port}' does not match required test port " . self::REQUIRED_MYSQL_PORT . ".";
        }

        if (!str_starts_with($version, self::REQUIRED_MYSQL_VERSION_PREFIX)) {
            $errors[] = "Active MySQL version '{$version}' does not match required version '" . self::REQUIRED_MYSQL_VERSION_PREFIX . "'.";
        }

        if ($host !== self::REQUIRED_MYSQL_HOST && $host !== 'localhost') {
            $errors[] = "Configured host '{$host}' is not local loopback '" . self::REQUIRED_MYSQL_HOST . "'.";
        }

        if (!empty($errors)) {
            $errorSummary = implode(' ', $errors);
            throw new RuntimeException(
                "DATABASE SAFETY GUARD VIOLATION: Unauthorized MySQL target. Destructive operations blocked. {$errorSummary}"
            );
        }
    }
}
