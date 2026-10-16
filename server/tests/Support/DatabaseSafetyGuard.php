<?php

namespace Tests\Support;

use Illuminate\Support\Facades\DB;
use RuntimeException;

class DatabaseSafetyGuard
{
    /**
     * Strictly authorized MySQL test profiles.
     * Each profile represents a verified, disposable test environment.
     * Any connection failing to match all criteria of an authorized profile fails closed.
     */
    public const AUTHORIZED_MYSQL_PROFILES = [
        'local_windows_isolated' => [
            'name' => 'Local Windows Testing',
            'database' => 'charyel_schema_test',
            'users' => [
                'charyel_test_runner@127.0.0.1',
                'charyel_test_runner@localhost',
            ],
            'port' => 3308,
            'hosts' => ['127.0.0.1', 'localhost'],
            'version_prefix' => '9.7.2',
        ],
        'github_actions_ci' => [
            'name' => 'GitHub Actions CI',
            'database' => 'charyel_test',
            'users' => [
                'charyel_test@%',
                'charyel_test@127.0.0.1',
                'charyel_test@localhost',
            ],
            'port' => 3306,
            'hosts' => ['127.0.0.1', 'localhost'],
            'version_prefix' => '9.7.2',
        ],
    ];

    /**
     * Databases that are strictly prohibited from destructive testing under any circumstance.
     */
    public const FORBIDDEN_DATABASES = [
        'charyel_dev',
        'charyel_prod',
        'charyel_production',
    ];

    // Backward-compatibility references for the local testing profile
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
     * 2. Authorized MySQL 9.7.2 test runner matching either:
     *    a. Local Windows isolated test runner (charyel_schema_test on port 3308)
     *    b. GitHub Actions CI test runner (charyel_test on port 3306)
     *
     * Everything else (including charyel_dev, charyel_prod, unauthorized users/ports, non-memory sqlite) fails closed.
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
            "DATABASE SAFETY GUARD VIOLATION: Unsupported driver '{$driver}'. Only ':memory:' SQLite or authorized MySQL test databases are permitted."
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
     * Determine whether execution is strictly inside an authenticated GitHub Actions CI runner.
     * Optional parameters allow deterministic unit testing of all logical branches.
     */
    public static function isGitHubActionsEnvironment(?string $ciVal = null, ?string $gaVal = null): bool
    {
        $ci = $ciVal ?? (getenv('CI') ?: ($_SERVER['CI'] ?? ($_ENV['CI'] ?? null)));
        $ga = $gaVal ?? (getenv('GITHUB_ACTIONS') ?: ($_SERVER['GITHUB_ACTIONS'] ?? ($_ENV['GITHUB_ACTIONS'] ?? null)));

        return in_array($ci, ['true', true, '1', 1], true)
            && in_array($ga, ['true', true, '1', 1], true);
    }

    /**
     * Validate MySQL live connection parameters against the strictly authorized test profiles.
     *
     * @throws RuntimeException
     */
    public static function validateMysqlIdentity(
        string $database,
        string $user,
        int $port,
        string $version,
        string $host,
        ?bool $isCiContext = null
    ): void {
        // 1. Explicitly check for forbidden / production / development database names
        $lowerDb = strtolower($database);
        if (in_array($lowerDb, self::FORBIDDEN_DATABASES, true) || str_contains($lowerDb, 'prod')) {
            throw new RuntimeException(
                "DATABASE SAFETY GUARD VIOLATION: Target database '{$database}' is explicitly protected against test operations. Destructive testing blocked."
            );
        }

        // 2. Resolve matching profile by target database name
        $profile = null;
        $profileKey = null;
        foreach (self::AUTHORIZED_MYSQL_PROFILES as $key => $candidate) {
            if ($candidate['database'] === $database) {
                $profile = $candidate;
                $profileKey = $key;
                break;
            }
        }

        if ($profile === null) {
            $allowedDbs = implode("', '", array_column(self::AUTHORIZED_MYSQL_PROFILES, 'database'));
            throw new RuntimeException(
                "DATABASE SAFETY GUARD VIOLATION: Active database '{$database}' does not match any authorized test database (authorized: '{$allowedDbs}')."
            );
        }

        // 3. Execution Context Validation:
        // The GitHub Actions CI profile requires BOTH an explicit CI execution context AND the exact disposable CI database identity.
        if ($profileKey === 'github_actions_ci') {
            $ciActive = $isCiContext ?? self::isGitHubActionsEnvironment();
            if (!$ciActive) {
                throw new RuntimeException(
                    "DATABASE SAFETY GUARD VIOLATION: CI test profile '{$database}' is strictly prohibited outside of an authenticated GitHub Actions test execution context."
                );
            }
        }

        // 4. Strict identity validation against the matched profile
        $errors = [];

        if (!in_array($user, $profile['users'], true)) {
            $allowedUsers = implode("', '", $profile['users']);
            $errors[] = "Active user '{$user}' is not authorized for profile '{$profile['name']}' (authorized: '{$allowedUsers}').";
        }

        if ($port !== $profile['port']) {
            $errors[] = "Active port '{$port}' does not match required port {$profile['port']} for profile '{$profile['name']}'.";
        }

        if (!str_starts_with($version, $profile['version_prefix'])) {
            $errors[] = "Active MySQL version '{$version}' does not match required version prefix '{$profile['version_prefix']}'.";
        }

        if (!in_array($host, $profile['hosts'], true)) {
            $allowedHosts = implode("', '", $profile['hosts']);
            $errors[] = "Configured host '{$host}' is not an authorized local loopback for profile '{$profile['name']}' (authorized: '{$allowedHosts}').";
        }

        if (!empty($errors)) {
            $errorSummary = implode(' ', $errors);
            throw new RuntimeException(
                "DATABASE SAFETY GUARD VIOLATION: Unauthorized MySQL target. Destructive operations blocked. {$errorSummary}"
            );
        }
    }
}
