<?php

namespace Tests\Feature;

use App\Models\GuestIdentity;
use App\Models\User;
use App\Models\UserProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\Support\DatabaseSafetyGuard;
use Tests\TestCase;

class GuestAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        DatabaseSafetyGuard::assertDestructiveOperationsAllowed();
    }

    /**
     * Helper to generate a canonical 256-bit hex secret (64 characters).
     */
    protected function generateValidHexSecret(): string
    {
        return bin2hex(random_bytes(32));
    }

    /**
     * Helper to generate a canonical 256-bit base64url secret (43-44 characters).
     */
    protected function generateValidBase64UrlSecret(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    public function test_first_time_guest_registration_creates_guest_identity_and_issues_scoped_token(): void
    {
        $uuid7 = (string) Str::uuid7();
        $secret = $this->generateValidHexSecret();

        $response = $this->postJson('/api/v1/auth/guest', [
            'guest_uuid' => $uuid7,
            'guest_secret' => $secret,
            'device_name' => 'Pixel 8 Guest PWA',
        ]);

        $response->assertStatus(201)
            ->assertJson([
                'status' => 'success',
                'data' => [
                    'auth_mode' => 'bearer',
                    'guest_uuid' => $uuid7,
                    'token_type' => 'Bearer',
                    'abilities' => ['guest:play'],
                    'is_claimed' => false,
                ],
            ]);

        $token = $response->json('data.token');
        $this->assertNotEmpty($token);

        // Verify sensitive secrets are NEVER disclosed
        $responseContent = $response->getContent();
        $this->assertStringNotContainsString($secret, $responseContent);
        $this->assertStringNotContainsString('secret_hash', $responseContent);

        // Verify database state
        $this->assertDatabaseHas('guest_identities', [
            'guest_uuid' => $uuid7,
            'claimed_by_user_id' => null,
            'claimed_at' => null,
        ]);

        $guest = GuestIdentity::where('guest_uuid', $uuid7)->first();
        $this->assertNotNull($guest);
        $this->assertTrue(Hash::check($secret, $guest->secret_hash));

        // Verify Sanctum token in personal_access_tokens
        $this->assertDatabaseHas('personal_access_tokens', [
            'tokenable_type' => GuestIdentity::class,
            'tokenable_id' => $guest->id,
            'name' => 'Pixel 8 Guest PWA',
        ]);
    }

    public function test_first_time_guest_registration_accepts_valid_base64url_secret(): void
    {
        $uuid7 = (string) Str::uuid7();
        $secret = $this->generateValidBase64UrlSecret();

        $response = $this->postJson('/api/v1/auth/guest', [
            'guest_uuid' => $uuid7,
            'guest_secret' => $secret,
        ]);

        $response->assertStatus(201)
            ->assertJson([
                'status' => 'success',
                'data' => [
                    'auth_mode' => 'bearer',
                    'guest_uuid' => $uuid7,
                    'abilities' => ['guest:play'],
                ],
            ]);

        $guest = GuestIdentity::where('guest_uuid', $uuid7)->first();
        $this->assertTrue(Hash::check($secret, $guest->secret_hash));
    }

    public function test_guest_reauthentication_with_valid_secret_issues_new_token_and_revokes_old_token(): void
    {
        $uuid7 = (string) Str::uuid7();
        $secret = $this->generateValidHexSecret();

        // 1. Initial registration
        $regResponse = $this->postJson('/api/v1/auth/guest', [
            'guest_uuid' => $uuid7,
            'guest_secret' => $secret,
            'device_name' => 'Session 1',
        ]);
        $regResponse->assertStatus(201);
        $token1 = $regResponse->json('data.token');

        $guest = GuestIdentity::where('guest_uuid', $uuid7)->first();
        $this->assertDatabaseCount('personal_access_tokens', 1);

        // 2. Reauthenticate with same UUID and valid secret
        $reauthResponse = $this->postJson('/api/v1/auth/guest', [
            'guest_uuid' => $uuid7,
            'guest_secret' => $secret,
            'device_name' => 'Session 2',
        ]);

        $reauthResponse->assertStatus(200)
            ->assertJson([
                'status' => 'success',
                'data' => [
                    'auth_mode' => 'bearer',
                    'guest_uuid' => $uuid7,
                    'abilities' => ['guest:play'],
                ],
            ]);

        $token2 = $reauthResponse->json('data.token');
        $this->assertNotEquals($token1, $token2);

        // Old token was revoked; exactly 1 active token exists for this guest
        $this->assertEquals(1, $guest->tokens()->count());

        // Token 1 must now be rejected
        $this->withHeader('Authorization', 'Bearer ' . $token1)
            ->getJson('/api/v1/auth/guest/me')
            ->assertStatus(401);

        // Token 2 must succeed
        $this->withHeader('Authorization', 'Bearer ' . $token2)
            ->getJson('/api/v1/auth/guest/me')
            ->assertStatus(200);
    }

    public function test_guest_reauthentication_fails_with_401_on_invalid_secret_without_disclosing_claim_state(): void
    {
        $uuid7 = (string) Str::uuid7();
        $realSecret = $this->generateValidHexSecret();
        $wrongSecret = $this->generateValidHexSecret();

        // Register guest
        $this->postJson('/api/v1/auth/guest', [
            'guest_uuid' => $uuid7,
            'guest_secret' => $realSecret,
        ])->assertStatus(201);

        // Attempt reauthentication with wrong secret
        $response = $this->postJson('/api/v1/auth/guest', [
            'guest_uuid' => $uuid7,
            'guest_secret' => $wrongSecret,
        ]);

        $response->assertStatus(401)
            ->assertJson([
                'status' => 'error',
                'message' => 'Invalid guest credentials.',
            ]);
    }

    public function test_guest_registration_rejects_non_canonical_uuidv7(): void
    {
        // UUIDv4 (version 4 instead of 7)
        $uuid4 = 'c4a8a183-5a0e-4f7b-9c6a-c454be34cf5e';
        $secret = $this->generateValidHexSecret();

        $response = $this->postJson('/api/v1/auth/guest', [
            'guest_uuid' => $uuid4,
            'guest_secret' => $secret,
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['guest_uuid']);
    }

    public function test_guest_registration_rejects_weak_or_short_secrets(): void
    {
        $uuid7 = (string) Str::uuid7();

        // 16 bytes hex (32 chars) instead of required 32 bytes (64 chars)
        $shortSecret = bin2hex(random_bytes(16));

        $response = $this->postJson('/api/v1/auth/guest', [
            'guest_uuid' => $uuid7,
            'guest_secret' => $shortSecret,
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['guest_secret']);

        // Plain text short password
        $plainSecret = 'ShortSecret123!';
        $this->postJson('/api/v1/auth/guest', [
            'guest_uuid' => $uuid7,
            'guest_secret' => $plainSecret,
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['guest_secret']);
    }

    public function test_guest_registration_rejects_malformed_secrets(): void
    {
        $uuid7 = (string) Str::uuid7();

        // 64 characters with invalid non-hex characters
        $malformed = str_repeat('x', 64);

        $response = $this->postJson('/api/v1/auth/guest', [
            'guest_uuid' => $uuid7,
            'guest_secret' => $malformed,
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['guest_secret']);
    }

    public function test_guest_token_issued_with_thirty_day_fixed_expiration(): void
    {
        $uuid7 = (string) Str::uuid7();
        $secret = $this->generateValidHexSecret();

        $response = $this->postJson('/api/v1/auth/guest', [
            'guest_uuid' => $uuid7,
            'guest_secret' => $secret,
        ]);

        $response->assertStatus(201);
        $expiresAtString = $response->json('data.expires_at');
        $this->assertNotEmpty($expiresAtString);

        $guest = GuestIdentity::where('guest_uuid', $uuid7)->first();
        $tokenRow = PersonalAccessToken::where('tokenable_id', $guest->id)
            ->where('tokenable_type', GuestIdentity::class)
            ->first();

        $this->assertNotNull($tokenRow);
        $this->assertNotNull($tokenRow->expires_at);

        // Assert expiration is set approximately 30 days into the future
        $diffInDays = now()->diffInDays($tokenRow->expires_at);
        $this->assertGreaterThanOrEqual(29, $diffInDays);
        $this->assertLessThanOrEqual(30, $diffInDays);
    }

    public function test_expired_guest_token_is_rejected_by_sanctum(): void
    {
        $uuid7 = (string) Str::uuid7();
        $secret = $this->generateValidHexSecret();

        $guest = GuestIdentity::create([
            'guest_uuid' => $uuid7,
            'secret_hash' => Hash::make($secret),
        ]);

        // Issue token that expired 1 hour ago
        $expiredToken = $guest->createToken('Expired Device', ['guest:play'], now()->subHour())->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer ' . $expiredToken)
            ->getJson('/api/v1/auth/guest/me');

        $response->assertStatus(401);
    }

    public function test_expired_guest_token_can_be_silently_renewed_using_persistent_secret(): void
    {
        $uuid7 = (string) Str::uuid7();
        $secret = $this->generateValidHexSecret();

        $guest = GuestIdentity::create([
            'guest_uuid' => $uuid7,
            'secret_hash' => Hash::make($secret),
        ]);

        // Old expired token
        $expiredToken = $guest->createToken('Old Expired Token', ['guest:play'], now()->subDays(31))->plainTextToken;

        // Verify old token is rejected
        $this->withHeader('Authorization', 'Bearer ' . $expiredToken)
            ->getJson('/api/v1/auth/guest/me')
            ->assertStatus(401);

        // Silent renewal via POST /api/v1/auth/guest using persistent secret
        $renewResponse = $this->postJson('/api/v1/auth/guest', [
            'guest_uuid' => $uuid7,
            'guest_secret' => $secret,
            'device_name' => 'Silent Renewal Device',
        ]);

        $renewResponse->assertStatus(200);
        $freshToken = $renewResponse->json('data.token');

        // Fresh token can now access guest routes cleanly
        $this->withHeader('Authorization', 'Bearer ' . $freshToken)
            ->getJson('/api/v1/auth/guest/me')
            ->assertStatus(200)
            ->assertJson([
                'status' => 'success',
                'data' => [
                    'guest_uuid' => $uuid7,
                    'is_claimed' => false,
                ],
            ]);
    }

    public function test_guest_logout_revokes_current_bearer_token(): void
    {
        $uuid7 = (string) Str::uuid7();
        $secret = $this->generateValidHexSecret();

        $response = $this->postJson('/api/v1/auth/guest', [
            'guest_uuid' => $uuid7,
            'guest_secret' => $secret,
        ]);
        $token = $response->json('data.token');

        $this->assertDatabaseCount('personal_access_tokens', 1);

        // Revoke token via universal logout
        $logoutResponse = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/v1/auth/logout');

        $logoutResponse->assertStatus(200)
            ->assertJson([
                'status' => 'success',
                'message' => 'Logged out successfully.',
            ]);

        // Token must be removed from database
        $this->assertDatabaseCount('personal_access_tokens', 0);

        // Subsequent access with revoked token is rejected
        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/v1/auth/guest/me')
            ->assertStatus(401);
    }

    public function test_guest_token_can_access_permitted_guest_routes(): void
    {
        $uuid7 = (string) Str::uuid7();
        $secret = $this->generateValidHexSecret();

        $res = $this->postJson('/api/v1/auth/guest', [
            'guest_uuid' => $uuid7,
            'guest_secret' => $secret,
        ]);
        $token = $res->json('data.token');

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/v1/auth/guest/me');

        $response->assertStatus(200)
            ->assertJson([
                'status' => 'success',
                'data' => [
                    'guest_uuid' => $uuid7,
                    'is_claimed' => false,
                ],
            ]);
    }

    public function test_guest_token_is_forbidden_from_accessing_registered_user_routes(): void
    {
        $uuid7 = (string) Str::uuid7();
        $secret = $this->generateValidHexSecret();

        $res = $this->postJson('/api/v1/auth/guest', [
            'guest_uuid' => $uuid7,
            'guest_secret' => $secret,
        ]);
        $guestToken = $res->json('data.token');

        // Attempting to access registered user's /me route
        $response = $this->withHeader('Authorization', 'Bearer ' . $guestToken)
            ->getJson('/api/v1/auth/me');

        $response->assertStatus(403)
            ->assertJson([
                'status' => 'error',
                'message' => 'Action requires an authenticated registered user account.',
            ]);

        // Attempting to access registered user's logout-all
        $logoutAllRes = $this->withHeader('Authorization', 'Bearer ' . $guestToken)
            ->postJson('/api/v1/auth/logout-all');

        $logoutAllRes->assertStatus(403);
    }

    public function test_registered_user_cannot_access_guest_routes(): void
    {
        $user = User::create([
            'name' => 'Registered Pilgrim',
            'email' => 'pilgrim@example.com',
            'password' => Hash::make('Kingdom2026!Key'),
        ]);

        $userToken = $user->createToken('User Device', ['user:all'])->plainTextToken;

        // Registered user presenting token to guest/me
        $response = $this->withHeader('Authorization', 'Bearer ' . $userToken)
            ->getJson('/api/v1/auth/guest/me');

        $response->assertStatus(403)
            ->assertJson([
                'status' => 'error',
                'message' => 'Action requires an authenticated guest identity.',
            ]);
    }

    public function test_reauthentication_rejected_with_403_after_identity_is_claimed(): void
    {
        $uuid7 = (string) Str::uuid7();
        $secret = $this->generateValidHexSecret();

        $user = User::create([
            'name' => 'Claiming User',
            'email' => 'claimuser@example.com',
            'password' => Hash::make('Kingdom2026!Key'),
        ]);

        // Create guest identity and simulate that it has been claimed
        $guest = GuestIdentity::create([
            'guest_uuid' => $uuid7,
            'secret_hash' => Hash::make($secret),
            'claimed_by_user_id' => $user->id,
            'claimed_at' => now(),
        ]);

        // Attempt reauthentication presenting the correct secret for this claimed guest
        $response = $this->postJson('/api/v1/auth/guest', [
            'guest_uuid' => $uuid7,
            'guest_secret' => $secret,
        ]);

        $response->assertStatus(403)
            ->assertJson([
                'status' => 'error',
                'message' => 'Guest identity has been claimed and converted into a registered user account.',
            ]);

        // Assert NO usable token was issued
        $this->assertEquals(0, $guest->tokens()->count());
    }

    public function test_wrong_secret_for_claimed_guest_still_returns_401_without_leaking_claim_status(): void
    {
        $uuid7 = (string) Str::uuid7();
        $realSecret = $this->generateValidHexSecret();
        $attackerSecret = $this->generateValidHexSecret();

        $user = User::create([
            'name' => 'Owner',
            'email' => 'owner@example.com',
            'password' => Hash::make('Kingdom2026!Key'),
        ]);

        // Claimed guest identity
        GuestIdentity::create([
            'guest_uuid' => $uuid7,
            'secret_hash' => Hash::make($realSecret),
            'claimed_by_user_id' => $user->id,
            'claimed_at' => now(),
        ]);

        // Attacker guesses or probes with invalid secret
        $response = $this->postJson('/api/v1/auth/guest', [
            'guest_uuid' => $uuid7,
            'guest_secret' => $attackerSecret,
        ]);

        // MUST return 401 Unauthorized, never disclosing claim status
        $response->assertStatus(401)
            ->assertJson([
                'status' => 'error',
                'message' => 'Invalid guest credentials.',
            ]);
    }

    public function test_claimed_guest_token_is_rejected_by_guest_middleware(): void
    {
        $uuid7 = (string) Str::uuid7();
        $secret = $this->generateValidHexSecret();

        $guest = GuestIdentity::create([
            'guest_uuid' => $uuid7,
            'secret_hash' => Hash::make($secret),
        ]);

        $token = $guest->createToken('Old Token', ['guest:play'])->plainTextToken;

        // Now guest becomes claimed
        $user = User::create([
            'name' => 'Claimant',
            'email' => 'claimant@example.com',
            'password' => Hash::make('Kingdom2026!Key'),
        ]);
        $guest->update([
            'claimed_by_user_id' => $user->id,
            'claimed_at' => now(),
        ]);

        // Even if token was not deleted, EnsureGuestPrincipal must reject it with 403
        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/v1/auth/guest/me');

        $response->assertStatus(403)
            ->assertJson([
                'status' => 'error',
                'message' => 'Guest identity has been claimed and converted into a registered user account.',
            ]);
    }

    public function test_guest_endpoint_rate_limits_after_repeated_attempts(): void
    {
        $uuid7 = (string) Str::uuid7();
        $secret = $this->generateValidHexSecret();

        // Register guest
        $this->postJson('/api/v1/auth/guest', [
            'guest_uuid' => $uuid7,
            'guest_secret' => $secret,
        ])->assertStatus(201);

        // Attempt 9 failed reauthentications (total 10 attempts against limit of 10/min)
        for ($i = 0; $i < 9; $i++) {
            $this->postJson('/api/v1/auth/guest', [
                'guest_uuid' => $uuid7,
                'guest_secret' => $this->generateValidHexSecret(),
            ])->assertStatus(401);
        }

        // 11th request must receive HTTP 429 Too Many Requests
        $response = $this->postJson('/api/v1/auth/guest', [
            'guest_uuid' => $uuid7,
            'guest_secret' => $this->generateValidHexSecret(),
        ]);

        $response->assertStatus(429);
    }

    public function test_concurrent_guest_registration_race_is_handled_gracefully(): void
    {
        $uuid7 = (string) Str::uuid7();
        $secret = $this->generateValidHexSecret();

        // Simulate Process A inserting into the database right before Process B's transaction
        GuestIdentity::create([
            'guest_uuid' => $uuid7,
            'secret_hash' => Hash::make($secret),
        ]);

        // Process B attempts to register the same guest_uuid presenting the same secret
        $response = $this->postJson('/api/v1/auth/guest', [
            'guest_uuid' => $uuid7,
            'guest_secret' => $secret,
        ]);

        // Handled cleanly as reauthentication
        $response->assertStatus(200)
            ->assertJson([
                'status' => 'success',
                'data' => [
                    'auth_mode' => 'bearer',
                    'guest_uuid' => $uuid7,
                    'abilities' => ['guest:play'],
                ],
            ]);
    }
}
