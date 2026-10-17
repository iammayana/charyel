<?php

namespace Tests\Feature;

use App\Models\Church;
use App\Models\Country;
use App\Models\Locality;
use App\Models\Region;
use App\Models\User;
use App\Models\UserProfile;
use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Tests\Support\DatabaseSafetyGuard;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        DatabaseSafetyGuard::assertDestructiveOperationsAllowed();
    }

    /**
     * Helper to set up geographic hierarchy and a verified church.
     */
    protected function createHierarchyAndChurch(string $churchName = 'Grace Community Church'): Church
    {
        $country = Country::firstOrCreate(['code' => 'NG'], [
            'name' => 'Nigeria',
            'currency_code' => 'NGN',
            'is_active' => true,
            'created_at' => now(),
        ]);

        $region = Region::firstOrCreate(['country_code' => 'NG', 'name' => 'Lagos State'], [
            'code' => 'LA',
            'created_at' => now(),
        ]);

        $locality = Locality::firstOrCreate(['region_id' => $region->id, 'slug' => 'ikeja'], [
            'name' => 'Ikeja',
            'created_at' => now(),
        ]);

        return Church::create([
            'locality_id' => $locality->id,
            'name' => $churchName,
            'slug' => 'ikeja-' . uniqid(),
            'denomination' => 'Non-Denominational',
            'is_verified' => true,
        ]);
    }

    public function test_user_can_register_via_session_mode(): void
    {
        $response = $this->postJson('/api/v1/auth/register', [
            'name' => 'Brother David',
            'email' => 'david@example.com',
            'password' => 'Kingdom2026!Key',
            'password_confirmation' => 'Kingdom2026!Key',
            'pilgrim_name' => 'David_Seeker',
            'token_mode' => 'session',
        ]);

        $response->assertStatus(201)
            ->assertJson([
                'status' => 'success',
                'message' => 'User registered successfully.',
                'data' => [
                    'auth_mode' => 'session',
                    'user' => [
                        'name' => 'Brother David',
                        'email' => 'david@example.com',
                    ],
                    'profile' => [
                        'pilgrim_name' => 'David_Seeker',
                        'total_xp' => 0,
                        'current_streak' => 0,
                    ],
                ],
            ]);

        // Verify no bearer token is leaked in session mode
        $this->assertArrayNotHasKey('token', $response->json('data'));

        // Verify records in database
        $this->assertDatabaseHas('users', ['email' => 'david@example.com']);
        $user = User::where('email', 'david@example.com')->first();
        $this->assertNotNull($user);

        $this->assertDatabaseHas('user_profiles', [
            'user_id' => $user->id,
            'pilgrim_name' => 'David_Seeker',
            'total_xp' => 0,
        ]);

        // Verify session authentication state
        $this->assertAuthenticatedAs($user);
    }

    public function test_user_can_register_via_bearer_mode_with_device_name(): void
    {
        $response = $this->postJson('/api/v1/auth/register', [
            'name' => 'Sister Mary',
            'email' => 'mary@example.com',
            'password' => 'Kingdom2026!Key',
            'password_confirmation' => 'Kingdom2026!Key',
            'pilgrim_name' => 'Mary_Praise',
            'token_mode' => 'bearer',
            'device_name' => 'Pixel 8 PWA',
        ]);

        $response->assertStatus(201)
            ->assertJson([
                'status' => 'success',
                'data' => [
                    'auth_mode' => 'bearer',
                    'token_type' => 'Bearer',
                    'abilities' => ['user:all'],
                    'user' => [
                        'name' => 'Sister Mary',
                        'email' => 'mary@example.com',
                    ],
                    'profile' => [
                        'pilgrim_name' => 'Mary_Praise',
                        'total_xp' => 0,
                    ],
                ],
            ]);

        $plainToken = $response->json('data.token');
        $this->assertNotEmpty($plainToken);

        $user = User::where('email', 'mary@example.com')->first();
        $this->assertDatabaseHas('personal_access_tokens', [
            'tokenable_type' => User::class,
            'tokenable_id' => $user->id,
            'name' => 'Pixel 8 PWA',
        ]);
    }

    public function test_user_can_register_with_church_membership(): void
    {
        $church = $this->createHierarchyAndChurch('Covenant Chapel');

        $response = $this->postJson('/api/v1/auth/register', [
            'name' => 'Joshua Stone',
            'email' => 'joshua@example.com',
            'password' => 'Kingdom2026!Key',
            'password_confirmation' => 'Kingdom2026!Key',
            'pilgrim_name' => 'Joshua_Warrior',
            'church_id' => $church->id,
            'token_mode' => 'bearer',
            'device_name' => 'Desktop Chrome',
        ]);

        $response->assertStatus(201);

        $user = User::where('email', 'joshua@example.com')->first();
        $this->assertNotNull($user);

        // Verify user profile links church
        $this->assertEquals($church->id, $user->profile->primary_church_id);

        // Verify church membership was created cleanly with status and role
        $this->assertDatabaseHas('church_memberships', [
            'user_id' => $user->id,
            'church_id' => $church->id,
            'role' => 'pilgrim',
            'is_primary' => 1,
            'status' => 'active',
        ]);
    }

    public function test_registration_fails_on_duplicate_email(): void
    {
        User::create([
            'name' => 'Existing Pilgrim',
            'email' => 'duplicate@example.com',
            'password' => Hash::make('Kingdom2026!Key'),
        ]);

        $response = $this->postJson('/api/v1/auth/register', [
            'name' => 'Another Pilgrim',
            'email' => 'duplicate@example.com',
            'password' => 'Kingdom2026!Key',
            'password_confirmation' => 'Kingdom2026!Key',
            'pilgrim_name' => 'Another_One',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['email']);
    }

    public function test_registration_enforces_strong_passwords(): void
    {
        $response = $this->postJson('/api/v1/auth/register', [
            'name' => 'Weak Pilgrim',
            'email' => 'weak@example.com',
            'password' => 'weak',
            'password_confirmation' => 'weak',
            'pilgrim_name' => 'Weak_One',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['password']);
    }

    public function test_registration_requires_device_name_when_bearer_mode_selected(): void
    {
        $response = $this->postJson('/api/v1/auth/register', [
            'name' => 'Device Pilgrim',
            'email' => 'nodevice@example.com',
            'password' => 'Kingdom2026!Key',
            'password_confirmation' => 'Kingdom2026!Key',
            'pilgrim_name' => 'Device_One',
            'token_mode' => 'bearer',
            // Missing device_name
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['device_name']);
    }

    public function test_user_can_login_via_session_mode(): void
    {
        $user = User::create([
            'name' => 'Login Pilgrim',
            'email' => 'login@example.com',
            'password' => Hash::make('Kingdom2026!Key'),
        ]);

        UserProfile::create([
            'user_id' => $user->id,
            'pilgrim_name' => 'Login_Tester',
            'avatar_seed' => 'seed123',
            'total_xp' => 150,
            'current_streak' => 2,
            'longest_streak' => 2,
            'is_leaderboard_public' => true,
            'show_church_affiliation' => false,
        ]);

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'login@example.com',
            'password' => 'Kingdom2026!Key',
            'token_mode' => 'session',
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'status' => 'success',
                'message' => 'Authenticated successfully.',
                'data' => [
                    'auth_mode' => 'session',
                    'user' => [
                        'email' => 'login@example.com',
                    ],
                    'profile' => [
                        'pilgrim_name' => 'Login_Tester',
                        'total_xp' => 150,
                    ],
                ],
            ]);

        $this->assertAuthenticatedAs($user);
    }

    public function test_user_can_login_via_bearer_mode(): void
    {
        $user = User::create([
            'name' => 'Bearer Pilgrim',
            'email' => 'bearer@example.com',
            'password' => Hash::make('Kingdom2026!Key'),
        ]);

        UserProfile::create([
            'user_id' => $user->id,
            'pilgrim_name' => 'Bearer_Tester',
            'avatar_seed' => 'seed456',
            'total_xp' => 50,
            'current_streak' => 1,
            'longest_streak' => 1,
            'is_leaderboard_public' => false,
            'show_church_affiliation' => false,
        ]);

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'bearer@example.com',
            'password' => 'Kingdom2026!Key',
            'token_mode' => 'bearer',
            'device_name' => 'iPhone Native',
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'status' => 'success',
                'data' => [
                    'auth_mode' => 'bearer',
                    'token_type' => 'Bearer',
                    'abilities' => ['user:all'],
                ],
            ]);

        $token = $response->json('data.token');
        $this->assertNotEmpty($token);
    }

    public function test_login_fails_with_invalid_credentials(): void
    {
        User::create([
            'name' => 'Real User',
            'email' => 'real@example.com',
            'password' => Hash::make('Kingdom2026!Key'),
        ]);

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'real@example.com',
            'password' => 'WrongPassword999!',
        ]);

        $response->assertStatus(401)
            ->assertJson([
                'status' => 'error',
                'message' => 'Invalid email or password.',
            ]);

        $this->assertGuest();
    }

    public function test_login_throttles_after_repeated_failures(): void
    {
        User::create([
            'name' => 'Brute Force Target',
            'email' => 'target@example.com',
            'password' => Hash::make('Kingdom2026!Key'),
        ]);

        for ($i = 0; $i < 5; $i++) {
            $res = $this->postJson('/api/v1/auth/login', [
                'email' => 'target@example.com',
                'password' => 'WrongPass!',
            ]);
            $res->assertStatus(401);
        }

        // 6th attempt must be throttled
        $throttled = $this->postJson('/api/v1/auth/login', [
            'email' => 'target@example.com',
            'password' => 'WrongPass!',
        ]);

        $throttled->assertStatus(429);
    }

    public function test_authenticated_user_can_access_me_profile(): void
    {
        $church = $this->createHierarchyAndChurch('Grace Temple');

        $user = User::create([
            'name' => 'Profile User',
            'email' => 'profile@example.com',
            'password' => Hash::make('Kingdom2026!Key'),
        ]);

        UserProfile::create([
            'user_id' => $user->id,
            'locality_id' => $church->locality_id,
            'primary_church_id' => $church->id,
            'pilgrim_name' => 'Profile_Pilgrim',
            'avatar_seed' => 'seed789',
            'total_xp' => 300,
            'current_streak' => 3,
            'longest_streak' => 5,
            'is_leaderboard_public' => true,
            'show_church_affiliation' => true,
        ]);

        $token = $user->createToken('Test Token', ['user:all'])->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/v1/auth/me');

        $response->assertStatus(200)
            ->assertJson([
                'status' => 'success',
                'data' => [
                    'user' => [
                        'email' => 'profile@example.com',
                    ],
                    'profile' => [
                        'pilgrim_name' => 'Profile_Pilgrim',
                        'total_xp' => 300,
                    ],
                ],
            ]);
    }

    public function test_unauthenticated_request_to_me_is_rejected(): void
    {
        $response = $this->getJson('/api/v1/auth/me');

        $response->assertStatus(401);
    }

    public function test_ensure_user_principal_blocks_unauthorized_token_ability(): void
    {
        $user = User::create([
            'name' => 'Scoped User',
            'email' => 'scoped@example.com',
            'password' => Hash::make('Kingdom2026!Key'),
        ]);

        // Token only has 'guest:play', lacking 'user:all'
        $restrictedToken = $user->createToken('Restricted Token', ['guest:play'])->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer ' . $restrictedToken)
            ->getJson('/api/v1/auth/me');

        $response->assertStatus(403)
            ->assertJson([
                'status' => 'error',
                'message' => 'Token lacks required ability [user:all].',
            ]);
    }

    public function test_logout_revokes_bearer_token(): void
    {
        $user = User::create([
            'name' => 'Logout User',
            'email' => 'logout@example.com',
            'password' => Hash::make('Kingdom2026!Key'),
        ]);

        $token = $user->createToken('Logout Device', ['user:all'])->plainTextToken;

        $this->assertDatabaseCount('personal_access_tokens', 1);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/v1/auth/logout');

        $response->assertStatus(200)
            ->assertJson([
                'status' => 'success',
                'message' => 'Logged out successfully.',
            ]);

        // Token should be deleted from database
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_logout_all_revokes_all_tokens_for_user(): void
    {
        $user = User::create([
            'name' => 'Multi Device User',
            'email' => 'multidevice@example.com',
            'password' => Hash::make('Kingdom2026!Key'),
        ]);

        $user->createToken('Phone', ['user:all']);
        $user->createToken('Tablet', ['user:all']);
        $activeToken = $user->createToken('Laptop', ['user:all'])->plainTextToken;

        $this->assertDatabaseCount('personal_access_tokens', 3);

        $response = $this->withHeader('Authorization', 'Bearer ' . $activeToken)
            ->postJson('/api/v1/auth/logout-all');

        $response->assertStatus(200)
            ->assertJson([
                'status' => 'success',
            ]);

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_login_regenerates_session_id_to_prevent_session_fixation(): void
    {
        $user = User::create([
            'name' => 'Fixation Test User',
            'email' => 'fixation@example.com',
            'password' => Hash::make('Kingdom2026!Key'),
        ]);

        UserProfile::create([
            'user_id' => $user->id,
            'pilgrim_name' => 'Fixation_Pilgrim',
            'total_xp' => 0,
        ]);

        // Start with an initial session
        $this->startSession();
        $initialSessionId = session()->getId();

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'fixation@example.com',
            'password' => 'Kingdom2026!Key',
            'token_mode' => 'session',
        ]);

        $response->assertStatus(200);

        // Verify that the session ID changed upon successful login
        $newSessionId = session()->getId();
        $this->assertNotEmpty($newSessionId);
        $this->assertNotEquals($initialSessionId, $newSessionId);
    }

    public function test_stateful_session_persists_authentication_across_requests(): void
    {
        $user = User::create([
            'name' => 'Stateful User',
            'email' => 'stateful@example.com',
            'password' => Hash::make('Kingdom2026!Key'),
        ]);

        UserProfile::create([
            'user_id' => $user->id,
            'pilgrim_name' => 'Stateful_Pilgrim',
            'total_xp' => 120,
        ]);

        // Login via stateful session mode
        $loginRes = $this->postJson('/api/v1/auth/login', [
            'email' => 'stateful@example.com',
            'password' => 'Kingdom2026!Key',
            'token_mode' => 'session',
        ]);
        $loginRes->assertStatus(200);

        // Make subsequent request to /me WITHOUT any Authorization header
        $meRes = $this->getJson('/api/v1/auth/me');

        $meRes->assertStatus(200)
            ->assertJson([
                'status' => 'success',
                'data' => [
                    'user' => [
                        'email' => 'stateful@example.com',
                    ],
                    'profile' => [
                        'pilgrim_name' => 'Stateful_Pilgrim',
                        'total_xp' => 120,
                    ],
                ],
            ]);
    }

    public function test_cookie_mode_logout_invalidates_current_session(): void
    {
        $user = User::create([
            'name' => 'Cookie Logout User',
            'email' => 'cookielogout@example.com',
            'password' => Hash::make('Kingdom2026!Key'),
        ]);

        UserProfile::create([
            'user_id' => $user->id,
            'pilgrim_name' => 'Cookie_Logout',
            'total_xp' => 50,
        ]);

        // Real browser session login
        $this->postJson('/api/v1/auth/login', [
            'email' => 'cookielogout@example.com',
            'password' => 'Kingdom2026!Key',
            'token_mode' => 'session',
        ])->assertStatus(200);

        $response = $this->postJson('/api/v1/auth/logout');
        $response->assertStatus(200)
            ->assertJson([
                'status' => 'success',
                'message' => 'Logged out successfully.',
            ]);

        $this->assertGuest('web');

        // Subsequent call without session must be unauthenticated
        $this->getJson('/api/v1/auth/me')->assertStatus(401);
    }

    public function test_logout_all_revokes_persisted_database_sessions_across_all_devices(): void
    {
        config(['session.driver' => 'database']);

        $user = User::create([
            'name' => 'Cross Device User',
            'email' => 'crossdevice@example.com',
            'password' => Hash::make('Kingdom2026!Key'),
        ]);

        $otherUser = User::create([
            'name' => 'Other User',
            'email' => 'other@example.com',
            'password' => Hash::make('Kingdom2026!Key'),
        ]);

        // Simulate active database session records across multiple browser devices
        DB::table('sessions')->insert([
            [
                'id' => 'session_device_mobile_' . Str::random(10),
                'user_id' => $user->id,
                'ip_address' => '192.168.1.10',
                'user_agent' => 'Mobile Safari',
                'payload' => base64_encode(serialize(['_token' => 'dummy1'])),
                'last_activity' => time(),
            ],
            [
                'id' => 'session_device_desktop_' . Str::random(10),
                'user_id' => $user->id,
                'ip_address' => '192.168.1.20',
                'user_agent' => 'Desktop Chrome',
                'payload' => base64_encode(serialize(['_token' => 'dummy2'])),
                'last_activity' => time(),
            ],
            [
                'id' => 'session_other_user_' . Str::random(10),
                'user_id' => $otherUser->id,
                'ip_address' => '10.0.0.5',
                'user_agent' => 'Other Device',
                'payload' => base64_encode(serialize(['_token' => 'dummy3'])),
                'last_activity' => time(),
            ],
        ]);

        // User also has personal access tokens
        $user->createToken('Mobile App', ['user:all']);
        $token = $user->createToken('Laptop', ['user:all'])->plainTextToken;

        $this->assertEquals(2, DB::table('sessions')->where('user_id', $user->id)->count());
        $this->assertEquals(1, DB::table('sessions')->where('user_id', $otherUser->id)->count());
        $this->assertEquals(2, DB::table('personal_access_tokens')->where('tokenable_id', $user->id)->count());

        // Execute logoutAll
        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/v1/auth/logout-all');

        $response->assertStatus(200);

        // Verify that ALL database sessions for $user were purged across devices
        $this->assertEquals(0, DB::table('sessions')->where('user_id', $user->id)->count());

        // Verify that other user's session remains untouched
        $this->assertEquals(1, DB::table('sessions')->where('user_id', $otherUser->id)->count());

        // Verify all personal access tokens for $user were purged
        $this->assertEquals(0, DB::table('personal_access_tokens')->where('tokenable_id', $user->id)->count());
    }

    public function test_csrf_cookie_endpoint_sets_xsrf_token(): void
    {
        $response = $this->getJson('/sanctum/csrf-cookie');

        $response->assertStatus(204);
        $response->assertCookie('XSRF-TOKEN');
    }

    /**
     * Enforce strict CSRF verification in tests by bypassing runningUnitTests() bypass.
     */
    protected function enableStrictCsrfVerification(): void
    {
        $app = $this->app;
        $encrypter = $app->make(Encrypter::class);
        $app->instance(ValidateCsrfToken::class, new class($app, $encrypter) extends ValidateCsrfToken {
            protected function runningUnitTests(): bool
            {
                return false;
            }
        });
    }

    public function test_stateful_request_without_valid_csrf_token_is_rejected(): void
    {
        $this->enableStrictCsrfVerification();

        $response = $this->withHeaders([
            'Referer' => 'http://localhost',
            'Accept' => 'application/json',
        ])->postJson('/api/v1/auth/logout');

        $response->assertStatus(419)
            ->assertJson([
                'message' => 'CSRF token mismatch.',
            ]);
    }

    public function test_stateful_request_with_invalid_csrf_token_is_rejected(): void
    {
        $this->enableStrictCsrfVerification();

        $response = $this->withHeaders([
            'Referer' => 'http://localhost',
            'Accept' => 'application/json',
            'X-CSRF-TOKEN' => 'invalid_csrf_signature',
        ])->postJson('/api/v1/auth/logout');

        $response->assertStatus(419)
            ->assertJson([
                'message' => 'CSRF token mismatch.',
            ]);
    }

    public function test_stateful_request_with_valid_csrf_token_passes_csrf_check(): void
    {
        $this->enableStrictCsrfVerification();

        $user = User::create([
            'name' => 'CSRF Pilgrim',
            'email' => 'csrf@example.com',
            'password' => Hash::make('Kingdom2026!Key'),
        ]);

        $this->startSession();
        $token = session()->token();

        $response = $this->actingAs($user, 'web')
            ->withHeaders([
                'Referer' => 'http://localhost',
                'Accept' => 'application/json',
                'X-CSRF-TOKEN' => $token,
            ])->postJson('/api/v1/auth/logout');

        $this->assertNotEquals(419, $response->getStatusCode());
        $response->assertStatus(200)
            ->assertJson([
                'status' => 'success',
                'message' => 'Logged out successfully.',
            ]);
    }

    public function test_logout_all_handles_non_database_session_driver_gracefully(): void
    {
        config(['session.driver' => 'array']);

        $user = User::create([
            'name' => 'Array Session User',
            'email' => 'arraysession@example.com',
            'password' => Hash::make('Kingdom2026!Key'),
        ]);

        $token = $user->createToken('Device', ['user:all'])->plainTextToken;
        $this->assertDatabaseCount('personal_access_tokens', 1);

        Log::spy();

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/v1/auth/logout-all');

        $response->assertStatus(200)
            ->assertJson([
                'status' => 'success',
                'message' => 'Tokens and current session revoked. Cross-device session revocation is not supported by the active session driver.',
            ]);

        // Bearer tokens were still revoked
        $this->assertDatabaseCount('personal_access_tokens', 0);

        Log::shouldHaveReceived('warning')
            ->once()
            ->withArgs(function (string $message, array $context) use ($user) {
                return str_contains($message, 'does not support cross-device session revocation') &&
                    ($context['user_id'] ?? null) === $user->id;
            });
    }

    public function test_active_mysql_connection_matches_authorized_disposable_profile(): void
    {
        $connection = DB::connection();

        // If running in SQLite regression suite, verify in-memory
        if ($connection->getDriverName() === 'sqlite') {
            $this->assertEquals(':memory:', $connection->getDatabaseName());
            return;
        }

        // If running in MySQL suite, assert connection satisfies DatabaseSafetyGuard criteria
        $meta = $connection->selectOne(
            "SELECT DATABASE() as db, CURRENT_USER() as user, @@port as port, @@version as version"
        );

        $this->assertNotNull($meta);

        $authorizedDbs = array_column(DatabaseSafetyGuard::AUTHORIZED_MYSQL_PROFILES, 'database');
        $this->assertContains($meta->db, $authorizedDbs);

        if ($meta->db === 'charyel_test') {
            // GitHub Actions CI Profile
            $this->assertStringStartsWith('charyel_test@', $meta->user);
            $this->assertEquals(3306, (int) $meta->port);
        } else {
            // Local Windows Isolated Profile
            $this->assertEquals('charyel_schema_test', $meta->db);
            $this->assertStringStartsWith('charyel_test_runner@', $meta->user);
            $this->assertEquals(3308, (int) $meta->port);
        }

        $this->assertStringStartsWith('9.7.2', $meta->version);
    }
}
