<?php

namespace Tests\Feature;

use App\Exceptions\ProtectedDatabaseException;
use App\Support\ProtectedDatabaseGuard;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class ProtectedDatabaseGuardTest extends TestCase
{
    protected function tearDown(): void
    {
        ProtectedDatabaseGuard::revokeTransientAuthorization();
        parent::tearDown();
    }

    /**
     * Test 1: Destructive Artisan commands are blocked when connection database is protected.
     */
    public function test_destructive_commands_are_blocked_when_targeting_protected_database(): void
    {
        config([
            'database.connections.simulated_dev' => [
                'driver' => 'mysql',
                'host' => '127.0.0.1',
                'port' => 3308,
                'database' => 'charyel_dev',
                'username' => 'dummy',
                'password' => 'dummy',
            ],
        ]);

        $destructiveCommands = [
            'migrate',
            'migrate:rollback',
            'migrate:fresh',
            'db:wipe',
        ];

        foreach ($destructiveCommands as $cmd) {
            try {
                Artisan::call($cmd, ['--database' => 'simulated_dev']);
                $this->fail("Expected ProtectedDatabaseException was not thrown for command '{$cmd}'.");
            } catch (ProtectedDatabaseException $e) {
                $this->assertEquals('charyel_dev', $e->database);
                $this->assertEquals('simulated_dev', $e->connection);
                $this->assertStringContainsString('strictly blocked against protected database', $e->getMessage());
            }
        }
    }

    /**
     * Test 2: Connection overrides cannot bypass protection.
     */
    public function test_connection_override_cannot_bypass_protection(): void
    {
        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite' => [
                'driver' => 'sqlite',
                'database' => ':memory:',
            ],
            'database.connections.prod_target' => [
                'driver' => 'mysql',
                'host' => '127.0.0.1',
                'port' => 3306,
                'database' => 'charyel_prod',
                'username' => 'dummy',
                'password' => 'dummy',
            ],
        ]);

        $this->expectException(ProtectedDatabaseException::class);
        $this->expectExceptionMessage("strictly blocked against protected database 'charyel_prod'");

        Artisan::call('migrate', ['--database' => 'prod_target']);
    }

    /**
     * Test 3: Production naming variations are protected.
     */
    public function test_production_naming_variations_are_protected(): void
    {
        $this->assertTrue(ProtectedDatabaseGuard::isProtectedDatabase('charyel_dev'));
        $this->assertTrue(ProtectedDatabaseGuard::isProtectedDatabase('charyel_prod'));
        $this->assertTrue(ProtectedDatabaseGuard::isProtectedDatabase('charyel_production'));
        $this->assertTrue(ProtectedDatabaseGuard::isProtectedDatabase('app_prod_db'));

        // Disposable and test databases must NOT be classified as protected
        $this->assertFalse(ProtectedDatabaseGuard::isProtectedDatabase('charyel_schema_test'));
        $this->assertFalse(ProtectedDatabaseGuard::isProtectedDatabase('charyel_test'));
        $this->assertFalse(ProtectedDatabaseGuard::isProtectedDatabase(':memory:'));
        $this->assertFalse(ProtectedDatabaseGuard::isProtectedDatabase(''));
    }

    /**
     * Test 4: Read-only diagnostic commands remain accessible and unblocked.
     */
    public function test_read_only_commands_are_not_classified_as_destructive(): void
    {
        $this->assertFalse(ProtectedDatabaseGuard::isDestructiveCommand('migrate:status'));
        $this->assertFalse(ProtectedDatabaseGuard::isDestructiveCommand('db:show'));
        $this->assertFalse(ProtectedDatabaseGuard::isDestructiveCommand('db:table'));
        $this->assertFalse(ProtectedDatabaseGuard::isDestructiveCommand('db:monitor'));

        $this->assertTrue(ProtectedDatabaseGuard::isDestructiveCommand('migrate'));
        $this->assertTrue(ProtectedDatabaseGuard::isDestructiveCommand('migrate:rollback'));
        $this->assertTrue(ProtectedDatabaseGuard::isDestructiveCommand('migrate:reset'));
        $this->assertTrue(ProtectedDatabaseGuard::isDestructiveCommand('migrate:refresh'));
        $this->assertTrue(ProtectedDatabaseGuard::isDestructiveCommand('migrate:fresh'));
        $this->assertTrue(ProtectedDatabaseGuard::isDestructiveCommand('db:wipe'));
        $this->assertTrue(ProtectedDatabaseGuard::isDestructiveCommand('db:seed'));
    }

    /**
     * Test 5: Disposable test databases remain functional.
     */
    public function test_disposable_test_databases_are_allowed(): void
    {
        // Must not throw for disposable targets
        ProtectedDatabaseGuard::assertTargetAllowed('migrate', 'sqlite', ':memory:');
        ProtectedDatabaseGuard::assertTargetAllowed('migrate', 'mysql_test', 'charyel_schema_test');
        ProtectedDatabaseGuard::assertTargetAllowed('migrate', 'ci_test', 'charyel_test');

        $this->assertTrue(true);
    }

    /**
     * Test 6: Transient authorization permits single-operation execution and revokes cleanly.
     */
    public function test_transient_authorization_lifecycle(): void
    {
        // 1. Initially blocked
        $blocked = false;
        try {
            ProtectedDatabaseGuard::assertTargetAllowed('migrate', 'simulated', 'charyel_dev');
        } catch (ProtectedDatabaseException $e) {
            $blocked = true;
        }
        $this->assertTrue($blocked);

        // 2. Grant transient authorization
        ProtectedDatabaseGuard::grantTransientAuthorization();
        ProtectedDatabaseGuard::assertTargetAllowed('migrate', 'simulated', 'charyel_dev');

        // 3. Revoke transient authorization
        ProtectedDatabaseGuard::revokeTransientAuthorization();
        $this->expectException(ProtectedDatabaseException::class);
        ProtectedDatabaseGuard::assertTargetAllowed('migrate', 'simulated', 'charyel_dev');
    }

    /**
     * Test 7: db:seed, migrate:reset and migrate:refresh targeting protected database are blocked.
     */
    public function test_additional_destructive_commands_are_blocked_against_protected_db(): void
    {
        config([
            'database.connections.simulated_protected' => [
                'driver' => 'mysql',
                'host' => '127.0.0.1',
                'port' => 3308,
                'database' => 'charyel_dev',
                'username' => 'dummy',
                'password' => 'dummy',
            ],
        ]);

        $commands = ['db:seed', 'migrate:reset', 'migrate:refresh'];
        foreach ($commands as $cmd) {
            try {
                Artisan::call($cmd, ['--database' => 'simulated_protected']);
                $this->fail("Expected ProtectedDatabaseException was not thrown for command '{$cmd}'.");
            } catch (ProtectedDatabaseException $e) {
                $this->assertEquals('charyel_dev', $e->database);
                $this->assertEquals('simulated_protected', $e->connection);
            }
        }
    }

    /**
     * Test 8: The --force flag cannot bypass the protected database guard.
     */
    public function test_force_flag_cannot_bypass_guard(): void
    {
        config([
            'database.connections.simulated_dev' => [
                'driver' => 'mysql',
                'host' => '127.0.0.1',
                'port' => 3308,
                'database' => 'charyel_dev',
                'username' => 'dummy',
                'password' => 'dummy',
            ],
        ]);

        $destructiveWithForce = [
            'migrate',
            'migrate:rollback',
            'migrate:fresh',
            'migrate:reset',
            'migrate:refresh',
            'db:wipe',
        ];

        foreach ($destructiveWithForce as $cmd) {
            try {
                Artisan::call($cmd, ['--database' => 'simulated_dev', '--force' => true]);
                $this->fail("Expected ProtectedDatabaseException with --force was not thrown for command '{$cmd}'.");
            } catch (ProtectedDatabaseException $e) {
                $this->assertEquals('charyel_dev', $e->database);
                $this->assertEquals('simulated_dev', $e->connection);
            }
        }
    }

    /**
     * Test 9: Default connection pointing to protected database is blocked without --database override.
     */
    public function test_default_connection_pointing_to_protected_database_is_blocked(): void
    {
        config([
            'database.default' => 'protected_default',
            'database.connections.protected_default' => [
                'driver' => 'mysql',
                'host' => '127.0.0.1',
                'port' => 3308,
                'database' => 'charyel_dev',
                'username' => 'dummy',
                'password' => 'dummy',
            ],
        ]);

        $this->expectException(ProtectedDatabaseException::class);
        $this->expectExceptionMessage("strictly blocked against protected database 'charyel_dev'");

        Artisan::call('migrate');
    }

    /**
     * Test 10: Explicit --env targeting local or production environments resolves and blocks.
     */
    public function test_env_option_targeting_protected_environment_is_blocked(): void
    {
        // 1. Simulating --env=production
        try {
            Artisan::call('migrate', ['--env' => 'production']);
            $this->fail("Expected ProtectedDatabaseException was not thrown for --env=production.");
        } catch (ProtectedDatabaseException $e) {
            $this->assertTrue(ProtectedDatabaseGuard::isProtectedDatabase($e->database));
        }

        // 2. Simulating --env=local (resolves to charyel_dev from .env)
        try {
            Artisan::call('migrate', ['--env' => 'local']);
            $this->fail("Expected ProtectedDatabaseException was not thrown for --env=local.");
        } catch (ProtectedDatabaseException $e) {
            $this->assertEquals('charyel_dev', $e->database);
        }
    }
}
