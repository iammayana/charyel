<?php

namespace App\Support;

use App\Exceptions\ProtectedDatabaseException;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Database\Events\MigrationsStarted;
use Illuminate\Support\Facades\Event;
use Symfony\Component\Console\Input\InputInterface;

class ProtectedDatabaseGuard
{
    /**
     * Databases that are strictly protected against automated or inadvertent destructive operations.
     */
    public const PROTECTED_DATABASES = [
        'charyel_dev',
        'charyel_prod',
        'charyel_production',
    ];

    /**
     * Commands classified as destructive schema or data altering operations.
     */
    public const DESTRUCTIVE_COMMANDS = [
        'migrate',
        'migrate:install',
        'migrate:rollback',
        'migrate:reset',
        'migrate:refresh',
        'migrate:fresh',
        'db:wipe',
        'db:seed',
    ];

    /**
     * Explicit read-only diagnostic commands that must remain accessible for inspection.
     */
    public const READ_ONLY_COMMANDS = [
        'migrate:status',
        'db:show',
        'db:table',
        'db:monitor',
    ];

    /**
     * Transient process-level authorization flag (strictly for in-memory, single-operation lifecycle).
     */
    protected static bool $transientAuthorizationActive = false;

    /**
     * Register preflight command and migration listeners.
     */
    public static function register(): void
    {
        // Ensure Symfony console command events dispatch Laravel's CommandStarting in all execution contexts
        if (app()->bound(\Illuminate\Contracts\Console\Kernel::class)) {
            $kernel = app()->make(\Illuminate\Contracts\Console\Kernel::class);
            if (method_exists($kernel, 'rerouteSymfonyCommandEvents')) {
                $kernel->rerouteSymfonyCommandEvents();
            }
        }

        // 1. Artisan command-level preflight guard (fires before any command logic runs)
        Event::listen(CommandStarting::class, function (CommandStarting $event) {
            self::handleCommandStarting($event);
        });

        // 2. Low-level migrator guard (fires directly from Migrator::run / rollback)
        Event::listen(MigrationsStarted::class, function (MigrationsStarted $event) {
            self::handleMigrationsStarted($event);
        });
    }

    /**
     * Handle Artisan CommandStarting event.
     *
     * @throws ProtectedDatabaseException
     */
    public static function handleCommandStarting(CommandStarting $event): void
    {
        $commandName = strtolower(trim($event->command));

        if (!self::isDestructiveCommand($commandName)) {
            return;
        }

        $target = self::resolveTargetFromInput($event->input);

        self::assertTargetAllowed($commandName, $target['connection'], $target['database']);
    }

    /**
     * Handle Migrator MigrationsStarted event.
     *
     * @throws ProtectedDatabaseException
     */
    public static function handleMigrationsStarted(MigrationsStarted $event): void
    {
        $connectionName = $event->options['database'] ?? config('database.default', 'mysql');
        $databaseName = (string) config("database.connections.{$connectionName}.database", '');

        self::assertTargetAllowed("migrator:{$event->method}", $connectionName, $databaseName);
    }

    /**
     * Enforce that the resolved command and database target are authorized.
     *
     * @throws ProtectedDatabaseException
     */
    public static function assertTargetAllowed(string $command, string $connection, string $database): void
    {
        if (!self::isProtectedDatabase($database)) {
            return;
        }

        // Verify that no persistent environment-variable bypass exists in .env file
        self::assertNoPersistentEnvBypass();

        // Check if an explicit, non-persistent single-execution authorization was granted
        if (self::$transientAuthorizationActive) {
            return;
        }

        throw new ProtectedDatabaseException(
            "PROTECTED DATABASE GUARD VIOLATION: Destructive Artisan command '{$command}' is strictly blocked against protected database '{$database}' (connection: '{$connection}'). Explicit owner authorization required.",
            command: $command,
            database: $database,
            connection: $connection
        );
    }

    /**
     * Check whether a command is classified as destructive.
     */
    public static function isDestructiveCommand(string $command): bool
    {
        $cmd = strtolower(trim($command));

        if (in_array($cmd, self::READ_ONLY_COMMANDS, true)) {
            return false;
        }

        if (in_array($cmd, self::DESTRUCTIVE_COMMANDS, true)) {
            return true;
        }

        // Catch any namespaced migration mutation command (except read-only status)
        if (str_starts_with($cmd, 'migrate:') && $cmd !== 'migrate:status') {
            return true;
        }

        return false;
    }

    /**
     * Check whether a database name matches protected targets.
     */
    public static function isProtectedDatabase(string $database): bool
    {
        $db = strtolower(trim($database));

        if ($db === '') {
            return false;
        }

        if (in_array($db, self::PROTECTED_DATABASES, true)) {
            return true;
        }

        // Protect any database containing 'prod' unless explicitly identified as a test database
        if (str_contains($db, 'prod') && !str_contains($db, 'test')) {
            return true;
        }

        return false;
    }

    /**
     * Resolve target connection and database name from console input.
     *
     * @return array{connection: string, database: string}
     */
    public static function resolveTargetFromInput(?InputInterface $input = null): array
    {
        $connectionName = null;
        $hasExplicitDatabaseOption = false;

        if ($input !== null && $input->hasOption('database')) {
            $opt = $input->getOption('database');
            if (!empty($opt) && is_string($opt)) {
                $connectionName = trim($opt);
                $hasExplicitDatabaseOption = true;
            }
        }

        $connectionName = $connectionName ?: config('database.default', 'mysql');
        $databaseName = (string) config("database.connections.{$connectionName}.database", '');

        if ($databaseName === '') {
            $url = (string) config("database.connections.{$connectionName}.url", '');
            if ($url !== '') {
                $parsed = parse_url($url);
                $databaseName = ltrim($parsed['path'] ?? '', '/');
            }
        }

        // Resolve effective database when --env is explicitly supplied and connection is not overridden
        if (!$hasExplicitDatabaseOption && $input !== null && $input->hasOption('env')) {
            $envOpt = $input->getOption('env');
            if (!empty($envOpt) && is_string($envOpt)) {
                $envName = strtolower(trim($envOpt));
                if (str_contains($envName, 'prod')) {
                    $databaseName = 'charyel_prod';
                } elseif ($envName === 'local' || $envName === 'development') {
                    $envFile = base_path('.env');
                    if (file_exists($envFile)) {
                        $content = file_get_contents($envFile);
                        if (preg_match('/^\s*DB_DATABASE\s*=\s*(.*)$/m', $content, $m)) {
                            $parsed = trim($m[1], " \t\n\r\0\x0B\"'");
                            if ($parsed !== '') {
                                $databaseName = $parsed;
                            }
                        }
                    }
                    if (empty($databaseName)) {
                        $databaseName = 'charyel_dev';
                    }
                }
            }
        }

        return [
            'connection' => $connectionName,
            'database' => $databaseName,
        ];
    }

    /**
     * Ensure that no persistent authorization token has been stored in .env.
     * Prevents accidental permanent bypasses.
     *
     * @throws \RuntimeException
     */
    public static function assertNoPersistentEnvBypass(): void
    {
        $envPath = base_path('.env');
        if (file_exists($envPath)) {
            $content = file_get_contents($envPath);
            if (preg_match('/^\s*ALLOW_PROTECTED_DB_MIGRATIONS\s*=/m', $content) ||
                preg_match('/^\s*CHARYEL_PROTECTED_MIGRATION_AUTH\s*=/m', $content)) {
                throw new \RuntimeException(
                    "DATABASE INTEGRITY GUARD ERROR: Persistent migration bypass configuration detected in .env file. Bypasses in .env are strictly prohibited. Remove the variable from .env immediately."
                );
            }
        }
    }

    /**
     * Temporarily grant transient single-execution authorization (in-memory only).
     */
    public static function grantTransientAuthorization(): void
    {
        self::$transientAuthorizationActive = true;
    }

    /**
     * Revoke transient authorization.
     */
    public static function revokeTransientAuthorization(): void
    {
        self::$transientAuthorizationActive = false;
    }
}
