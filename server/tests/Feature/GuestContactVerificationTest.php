<?php

namespace Tests\Feature;

use App\Contracts\OtpDeliveryServiceContract;
use App\Models\ContactVerificationToken;
use App\Models\GuestIdentity;
use App\Models\User;
use App\Services\Verification\ArrayOtpDeliveryService;
use App\Services\Verification\OtpKeyManager;
use App\Services\Verification\ProductionOtpDeliveryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\Support\DatabaseSafetyGuard;
use Tests\TestCase;

class GuestContactVerificationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        DatabaseSafetyGuard::assertDestructiveOperationsAllowed();

        /** @var ArrayOtpDeliveryService $delivery */
        $delivery = app(OtpDeliveryServiceContract::class);
        if ($delivery instanceof ArrayOtpDeliveryService) {
            $delivery->clear();
        }
    }

    /**
     * Helper to create a guest identity with contact details and return bearer token.
     */
    protected function createGuestWithToken(array $attributes = [], array $abilities = ['guest:play']): array
    {
        $uuid7 = (string) Str::uuid7();
        $secret = bin2hex(random_bytes(32));

        $guest = GuestIdentity::create(array_merge([
            'guest_uuid' => $uuid7,
            'secret_hash' => Hash::make($secret),
            'email' => 'pilgrim_' . Str::random(6) . '@example.com',
            'phone_number' => '+23480' . random_int(10000000, 99999999),
        ], $attributes));

        $token = $guest->createToken('TestDevice', $abilities)->plainTextToken;

        return [$guest, $token];
    }

    /**
     * Helper to create a registered user with contact details and return bearer token.
     */
    protected function createUserWithToken(array $attributes = [], array $abilities = ['user:all']): array
    {
        $user = User::create(array_merge([
            'name' => 'Registered Pilgrim',
            'email' => 'user_' . Str::random(6) . '@example.com',
            'phone_number' => '+23481' . random_int(10000000, 99999999),
            'password' => Hash::make('Kingdom2026!Key'),
        ], $attributes));

        $token = $user->createToken('UserDevice', $abilities)->plainTextToken;

        return [$user, $token];
    }

    // =========================================================================
    // 1. CHALLENGE ISSUANCE TESTS
    // =========================================================================

    public function test_guest_can_issue_email_verification_challenge(): void
    {
        [$guest, $token] = $this->createGuestWithToken(['email' => 'faithful@example.com']);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/v1/auth/verification/challenge', [
                'contact_type' => 'email',
            ]);

        $response->assertStatus(201)
            ->assertJson([
                'status' => 'success',
                'message' => 'Verification challenge issued.',
                'data' => [
                    'contact_type' => 'email',
                    'masked_contact' => 'f***l@example.com',
                    'max_attempts' => 5,
                ],
            ]);

        $challengeId = $response->json('data.challenge_id');
        $this->assertNotNull($challengeId);

        $this->assertDatabaseHas('contact_verification_tokens', [
            'challenge_id' => $challengeId,
            'guest_identity_id' => $guest->id,
            'user_id' => null,
            'contact_type' => 'email',
            'contact_value' => 'faithful@example.com',
            'attempts' => 0,
            'max_attempts' => 5,
            'verified_at' => null,
            'locked_at' => null,
        ]);

        /** @var ArrayOtpDeliveryService $delivery */
        $delivery = app(OtpDeliveryServiceContract::class);
        $this->assertNotNull($delivery->latestOtpFor('faithful@example.com'));
        $this->assertEquals(6, strlen($delivery->latestOtpFor('faithful@example.com')));
    }

    public function test_guest_can_issue_phone_verification_challenge(): void
    {
        [$guest, $token] = $this->createGuestWithToken(['phone_number' => '+2348012345678']);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/v1/auth/verification/challenge', [
                'contact_type' => 'phone',
            ]);

        $response->assertStatus(201)
            ->assertJson([
                'status' => 'success',
                'data' => [
                    'contact_type' => 'phone',
                    'masked_contact' => '+234****5678',
                    'max_attempts' => 5,
                ],
            ]);

        $challengeId = $response->json('data.challenge_id');
        $this->assertDatabaseHas('contact_verification_tokens', [
            'challenge_id' => $challengeId,
            'guest_identity_id' => $guest->id,
            'contact_type' => 'phone',
            'contact_value' => '+2348012345678',
        ]);
    }

    public function test_registered_user_can_issue_verification_challenges(): void
    {
        [$user, $token] = $this->createUserWithToken([
            'email' => 'user_pilgrim@example.com',
            'phone_number' => '+2348099887766',
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/v1/auth/verification/challenge', [
                'contact_type' => 'email',
            ]);

        $response->assertStatus(201);
        $challengeId = $response->json('data.challenge_id');

        $this->assertDatabaseHas('contact_verification_tokens', [
            'challenge_id' => $challengeId,
            'user_id' => $user->id,
            'guest_identity_id' => null,
            'contact_type' => 'email',
            'contact_value' => 'user_pilgrim@example.com',
        ]);
    }

    public function test_challenge_request_requires_authentication(): void
    {
        $response = $this->postJson('/api/v1/auth/verification/challenge', [
            'contact_type' => 'email',
        ]);

        $response->assertStatus(401);
    }

    public function test_challenge_rejected_if_contact_channel_not_configured(): void
    {
        [$guest, $token] = $this->createGuestWithToken([
            'email' => 'emailonly@example.com',
            'phone_number' => null,
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/v1/auth/verification/challenge', [
                'contact_type' => 'phone',
            ]);

        $response->assertStatus(422)
            ->assertJson([
                'status' => 'error',
                'code' => 'CONTACT_NOT_CONFIGURED',
            ]);
    }

    public function test_challenge_rejected_if_contact_already_verified(): void
    {
        [$guest, $token] = $this->createGuestWithToken([
            'email' => 'verified@example.com',
            'email_verified_at' => now(),
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/v1/auth/verification/challenge', [
                'contact_type' => 'email',
            ]);

        $response->assertStatus(409)
            ->assertJson([
                'status' => 'error',
                'code' => 'CONTACT_ALREADY_VERIFIED',
            ]);
    }

    public function test_claimed_guest_is_forbidden_from_verification_endpoints(): void
    {
        $claimingUser = User::create([
            'name' => 'Claimer',
            'email' => 'claimer@example.com',
            'password' => Hash::make('Kingdom2026!Key'),
        ]);

        [$guest, $token] = $this->createGuestWithToken([
            'claimed_by_user_id' => $claimingUser->id,
            'claimed_at' => now(),
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/v1/auth/verification/challenge', [
                'contact_type' => 'email',
            ]);

        $response->assertStatus(403)
            ->assertJson([
                'status' => 'error',
                'code' => 'GUEST_CLAIMED',
            ]);
    }

    // =========================================================================
    // 2. VERIFICATION & CONSUMPTION TESTS
    // =========================================================================

    public function test_correct_otp_verifies_guest_email_and_marks_timestamps(): void
    {
        [$guest, $token] = $this->createGuestWithToken(['email' => 'correct@example.com']);

        $challengeRes = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/v1/auth/verification/challenge', ['contact_type' => 'email']);
        $challengeId = $challengeRes->json('data.challenge_id');

        /** @var ArrayOtpDeliveryService $delivery */
        $delivery = app(OtpDeliveryServiceContract::class);
        $otp = $delivery->latestOtpFor('correct@example.com');
        $this->assertNotNull($otp);

        $verifyRes = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/v1/auth/verification/verify', [
                'challenge_id' => $challengeId,
                'otp' => $otp,
            ]);

        $verifyRes->assertStatus(200)
            ->assertJson([
                'status' => 'success',
                'message' => 'Contact verified successfully.',
                'data' => [
                    'contact_type' => 'email',
                ],
            ]);

        $this->assertDatabaseHas('contact_verification_tokens', [
            'challenge_id' => $challengeId,
            'attempts' => 1,
        ]);

        $tokenRecord = ContactVerificationToken::where('challenge_id', $challengeId)->first();
        $this->assertNotNull($tokenRecord->verified_at);

        $guest->refresh();
        $this->assertNotNull($guest->email_verified_at);
        $this->assertTrue($guest->isEmailVerified());
    }

    public function test_correct_otp_verifies_guest_phone(): void
    {
        [$guest, $token] = $this->createGuestWithToken(['phone_number' => '+2348011223344']);

        $challengeRes = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/v1/auth/verification/challenge', ['contact_type' => 'phone']);
        $challengeId = $challengeRes->json('data.challenge_id');

        /** @var ArrayOtpDeliveryService $delivery */
        $delivery = app(OtpDeliveryServiceContract::class);
        $otp = $delivery->latestOtpFor('+2348011223344');

        $verifyRes = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/v1/auth/verification/verify', [
                'challenge_id' => $challengeId,
                'otp' => $otp,
            ]);

        $verifyRes->assertStatus(200);

        $guest->refresh();
        $this->assertNotNull($guest->phone_verified_at);
        $this->assertTrue($guest->isPhoneVerified());
    }

    public function test_correct_otp_verifies_registered_user_contact(): void
    {
        [$user, $token] = $this->createUserWithToken(['email' => 'user_verify@example.com']);

        $challengeRes = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/v1/auth/verification/challenge', ['contact_type' => 'email']);
        $challengeId = $challengeRes->json('data.challenge_id');

        /** @var ArrayOtpDeliveryService $delivery */
        $delivery = app(OtpDeliveryServiceContract::class);
        $otp = $delivery->latestOtpFor('user_verify@example.com');

        $verifyRes = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/v1/auth/verification/verify', [
                'challenge_id' => $challengeId,
                'otp' => $otp,
            ]);

        $verifyRes->assertStatus(200);

        $user->refresh();
        $this->assertNotNull($user->email_verified_at);
    }

    // =========================================================================
    // 3. ATTEMPTS PERSISTENCE & LOCKOUT TESTS
    // =========================================================================

    public function test_failed_otp_attempts_are_durably_committed_to_database(): void
    {
        [$guest, $token] = $this->createGuestWithToken(['email' => 'retry@example.com']);

        $challengeRes = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/v1/auth/verification/challenge', ['contact_type' => 'email']);
        $challengeId = $challengeRes->json('data.challenge_id');

        // Attempt 1: Wrong OTP
        $res1 = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/v1/auth/verification/verify', [
                'challenge_id' => $challengeId,
                'otp' => '000000',
            ]);

        $res1->assertStatus(422)
            ->assertJson([
                'status' => 'error',
                'code' => 'INVALID_OTP',
                'attempts' => 1,
                'remaining_attempts' => 4,
            ]);

        // CRITICAL: Verify that the attempt was persisted in the database!
        $this->assertDatabaseHas('contact_verification_tokens', [
            'challenge_id' => $challengeId,
            'attempts' => 1,
            'locked_at' => null,
        ]);

        // Attempt 2: Another wrong OTP
        $res2 = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/v1/auth/verification/verify', [
                'challenge_id' => $challengeId,
                'otp' => '111111',
            ]);

        $res2->assertStatus(422)
            ->assertJson([
                'status' => 'error',
                'code' => 'INVALID_OTP',
                'attempts' => 2,
                'remaining_attempts' => 3,
            ]);

        $this->assertDatabaseHas('contact_verification_tokens', [
            'challenge_id' => $challengeId,
            'attempts' => 2,
            'locked_at' => null,
        ]);
    }

    public function test_fifth_failed_attempt_locks_challenge_and_persists_lockout(): void
    {
        [$guest, $token] = $this->createGuestWithToken(['email' => 'lockout@example.com']);

        $challengeRes = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/v1/auth/verification/challenge', ['contact_type' => 'email']);
        $challengeId = $challengeRes->json('data.challenge_id');

        /** @var ArrayOtpDeliveryService $delivery */
        $delivery = app(OtpDeliveryServiceContract::class);
        $correctOtp = $delivery->latestOtpFor('lockout@example.com');

        // Attempts 1 to 4: Wrong OTP
        for ($i = 1; $i <= 4; $i++) {
            $res = $this->withHeader('Authorization', 'Bearer ' . $token)
                ->postJson('/api/v1/auth/verification/verify', [
                    'challenge_id' => $challengeId,
                    'otp' => '999999',
                ]);
            $res->assertStatus(422)
                ->assertJson(['attempts' => $i]);
        }

        // Attempt 5: Final wrong attempt locks the challenge
        $res5 = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/v1/auth/verification/verify', [
                'challenge_id' => $challengeId,
                'otp' => '999999',
            ]);

        $res5->assertStatus(423)
            ->assertJson([
                'status' => 'error',
                'code' => 'CHALLENGE_LOCKED',
                'attempts' => 5,
                'remaining_attempts' => 0,
            ]);

        $tokenRecord = ContactVerificationToken::where('challenge_id', $challengeId)->first();
        $this->assertEquals(5, $tokenRecord->attempts);
        $this->assertNotNull($tokenRecord->locked_at);

        // Attempt 6: Even submitting the CORRECT OTP is now rejected because challenge is locked!
        $res6 = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/v1/auth/verification/verify', [
                'challenge_id' => $challengeId,
                'otp' => $correctOtp,
            ]);

        $res6->assertStatus(423)
            ->assertJson([
                'status' => 'error',
                'code' => 'CHALLENGE_LOCKED',
            ]);
    }

    public function test_expired_challenge_is_rejected(): void
    {
        [$guest, $token] = $this->createGuestWithToken(['email' => 'expired@example.com']);

        $challengeRes = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/v1/auth/verification/challenge', ['contact_type' => 'email']);
        $challengeId = $challengeRes->json('data.challenge_id');

        /** @var ArrayOtpDeliveryService $delivery */
        $delivery = app(OtpDeliveryServiceContract::class);
        $otp = $delivery->latestOtpFor('expired@example.com');

        // Manually age the challenge past the 10-minute expiry window
        DB::table('contact_verification_tokens')
            ->where('challenge_id', $challengeId)
            ->update(['expires_at' => now()->subMinutes(11)]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/v1/auth/verification/verify', [
                'challenge_id' => $challengeId,
                'otp' => $otp,
            ]);

        $response->assertStatus(410)
            ->assertJson([
                'status' => 'error',
                'code' => 'CHALLENGE_EXPIRED',
            ]);
    }

    public function test_replay_of_consumed_challenge_is_rejected(): void
    {
        [$guest, $token] = $this->createGuestWithToken(['email' => 'replay@example.com']);

        $challengeRes = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/v1/auth/verification/challenge', ['contact_type' => 'email']);
        $challengeId = $challengeRes->json('data.challenge_id');

        /** @var ArrayOtpDeliveryService $delivery */
        $delivery = app(OtpDeliveryServiceContract::class);
        $otp = $delivery->latestOtpFor('replay@example.com');

        // First verification: Succeeds
        $res1 = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/v1/auth/verification/verify', [
                'challenge_id' => $challengeId,
                'otp' => $otp,
            ]);
        $res1->assertStatus(200);

        // Second verification: Replay rejected!
        $res2 = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/v1/auth/verification/verify', [
                'challenge_id' => $challengeId,
                'otp' => $otp,
            ]);

        $res2->assertStatus(409)
            ->assertJson([
                'status' => 'error',
                'code' => 'CHALLENGE_ALREADY_CONSUMED',
            ]);
    }

    public function test_cross_principal_verification_is_forbidden(): void
    {
        [$guestA, $tokenA] = $this->createGuestWithToken(['email' => 'guinea_a@example.com']);
        [$guestB, $tokenB] = $this->createGuestWithToken(['email' => 'guinea_b@example.com']);

        $challengeRes = $this->withHeader('Authorization', 'Bearer ' . $tokenA)
            ->postJson('/api/v1/auth/verification/challenge', ['contact_type' => 'email']);
        $challengeId = $challengeRes->json('data.challenge_id');

        /** @var ArrayOtpDeliveryService $delivery */
        $delivery = app(OtpDeliveryServiceContract::class);
        $otp = $delivery->latestOtpFor('guinea_a@example.com');

        // Reset auth guards so Sanctum resolves tokenB afresh
        auth()->forgetGuards();

        // Guest B attempts to submit Guest A's challenge
        $response = $this->withHeader('Authorization', 'Bearer ' . $tokenB)
            ->postJson('/api/v1/auth/verification/verify', [
                'challenge_id' => $challengeId,
                'otp' => $otp,
            ]);

        $response->assertStatus(403)
            ->assertJson([
                'status' => 'error',
                'code' => 'FORBIDDEN',
            ]);
    }

    public function test_challenge_invalidated_when_contact_detail_modified(): void
    {
        [$guest, $token] = $this->createGuestWithToken(['email' => 'initial@example.com']);

        $challengeRes = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/v1/auth/verification/challenge', ['contact_type' => 'email']);
        $challengeId = $challengeRes->json('data.challenge_id');

        /** @var ArrayOtpDeliveryService $delivery */
        $delivery = app(OtpDeliveryServiceContract::class);
        $otp = $delivery->latestOtpFor('initial@example.com');

        // Simulate contact modification on the guest identity
        DB::table('guest_identities')
            ->where('id', $guest->id)
            ->update(['email' => 'altered@example.com']);

        // Reset auth guards so Sanctum reloads the updated guest record from database
        auth()->forgetGuards();

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/v1/auth/verification/verify', [
                'challenge_id' => $challengeId,
                'otp' => $otp,
            ]);

        $response->assertStatus(409)
            ->assertJson([
                'status' => 'error',
                'code' => 'CONTACT_MODIFIED',
            ]);

        $tokenRecord = ContactVerificationToken::where('challenge_id', $challengeId)->first();
        $this->assertNotNull($tokenRecord->locked_at);
    }

    // =========================================================================
    // 4. RATE LIMITING & COOLDOWN TESTS
    // =========================================================================

    public function test_cooldown_rate_limit_enforces_60_seconds_between_requests(): void
    {
        [$guest, $token] = $this->createGuestWithToken(['email' => 'cooldown@example.com']);

        // First request: Succeeds
        $res1 = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/v1/auth/verification/challenge', ['contact_type' => 'email']);
        $res1->assertStatus(201);

        // Immediate second request: Blocked by 60s cooldown!
        $res2 = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/v1/auth/verification/challenge', ['contact_type' => 'email']);

        $res2->assertStatus(429)
            ->assertJson([
                'status' => 'error',
                'code' => 'COOLDOWN_ACTIVE',
            ]);
    }

    public function test_contact_sliding_window_blocks_more_than_three_challenges_in_15_minutes(): void
    {
        [$guest, $token] = $this->createGuestWithToken(['email' => 'flood@example.com']);

        // Seed 3 prior challenges within 15 minutes for this contact (aged past 60s cooldown)
        for ($i = 1; $i <= 3; $i++) {
            ContactVerificationToken::create([
                'challenge_id' => (string) Str::uuid7(),
                'guest_identity_id' => $guest->id,
                'contact_type' => 'email',
                'contact_value' => 'flood@example.com',
                'purpose' => 'guest_contact_verification',
                'token_digest' => hash('sha256', 'seed_' . $i),
                'key_id' => 'v1',
                'attempts' => 0,
                'max_attempts' => 5,
                'expires_at' => now()->addMinutes(10),
                'created_at' => now()->subMinutes(5),
            ]);
        }

        // 4th challenge request for this contact must be blocked by the sliding window
        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/v1/auth/verification/challenge', ['contact_type' => 'email']);

        $response->assertStatus(429)
            ->assertJson([
                'status' => 'error',
                'code' => 'TOO_MANY_CHALLENGES_FOR_CONTACT',
            ]);
    }

    // =========================================================================
    // 5. FAIL-SAFE DELIVERY TESTS
    // =========================================================================

    public function test_delivery_failure_revokes_challenge_and_returns_502(): void
    {
        [$guest, $token] = $this->createGuestWithToken(['email' => 'failsafe@example.com']);

        // Mock delivery service to simulate an SMTP or network dispatch failure
        $mockDelivery = $this->createMock(OtpDeliveryServiceContract::class);
        $mockDelivery->expects($this->once())
            ->method('sendEmailOtp')
            ->willThrowException(new RuntimeException('Connection timed out to mail server'));

        $this->app->instance(OtpDeliveryServiceContract::class, $mockDelivery);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/v1/auth/verification/challenge', ['contact_type' => 'email']);

        $response->assertStatus(502)
            ->assertJson([
                'status' => 'error',
                'code' => 'DELIVERY_FAILED',
                'message' => 'Failed to deliver verification code. Please try again later.',
            ]);

        // Verify challenge record was immediately locked upon dispatch failure
        $tokenRecord = ContactVerificationToken::where('guest_identity_id', $guest->id)
            ->where('contact_value', 'failsafe@example.com')
            ->first();

        $this->assertNotNull($tokenRecord);
        $this->assertNotNull($tokenRecord->locked_at);
        $this->assertTrue($tokenRecord->isLocked());
    }

    public function test_concurrent_challenge_issuance_is_serialized_and_rate_limited(): void
    {
        [$guest, $token] = $this->createGuestWithToken(['email' => 'concurrent@example.com']);

        // 1. Simulate an active lock on this contact to represent an in-flight competing request
        $contactLockKey = 'otp_issuance_contact:email:' . sha1('concurrent@example.com');
        $lock = \Illuminate\Support\Facades\Cache::lock($contactLockKey, 10);
        $lock->get(); // Hold the lock

        try {
            // A simultaneous request arriving while lock is held must be rejected with 429 CONCURRENT_REQUEST
            $response = $this->withHeader('Authorization', 'Bearer ' . $token)
                ->postJson('/api/v1/auth/verification/challenge', ['contact_type' => 'email']);

            $response->assertStatus(429)
                ->assertJson([
                    'status' => 'error',
                    'code' => 'CONCURRENT_REQUEST',
                ]);
        } finally {
            $lock->release();
        }

        // 2. Once lock is released, subsequent request proceeds cleanly
        $response2 = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/v1/auth/verification/challenge', ['contact_type' => 'email']);

        $response2->assertStatus(201);
    }

    // =========================================================================
    // 6. CRYPTOGRAPHIC KEY MANAGEMENT & ROTATION TESTS
    // =========================================================================

    public function test_key_rotation_supports_verifying_previously_issued_challenges(): void
    {
        [$guest, $token] = $this->createGuestWithToken(['email' => 'rotate@example.com']);

        $keyV1 = bin2hex(random_bytes(32));
        $keyV2 = bin2hex(random_bytes(32));

        // Configure initial rotation state: active is v1
        config([
            'auth.verification.active_key_id' => 'v1',
            'auth.verification.keys' => [
                'v1' => $keyV1,
                'v2' => $keyV2,
            ],
        ]);

        $challengeRes = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/v1/auth/verification/challenge', ['contact_type' => 'email']);
        $challengeRes->assertStatus(201);
        $challengeId = $challengeRes->json('data.challenge_id');

        /** @var ArrayOtpDeliveryService $delivery */
        $delivery = app(OtpDeliveryServiceContract::class);
        $otp = $delivery->latestOtpFor('rotate@example.com');

        $this->assertDatabaseHas('contact_verification_tokens', [
            'challenge_id' => $challengeId,
            'key_id' => 'v1',
        ]);

        // Key Rotation Cutover: active key is now v2, but v1 is retained in keys map
        config(['auth.verification.active_key_id' => 'v2']);

        // Challenge issued with v1 must verify successfully using the rotation map!
        $verifyRes = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/v1/auth/verification/verify', [
                'challenge_id' => $challengeId,
                'otp' => $otp,
            ]);

        $verifyRes->assertStatus(200);

        // If an unrecognized or retired key ID is requested, lookup fails closed
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unrecognized key_id [v_retired]');
        OtpKeyManager::resolveKey('v_retired');
    }

    public function test_production_environment_fails_closed_on_missing_or_short_keys(): void
    {
        // Simulate production environment
        $this->app->detectEnvironment(fn () => 'production');

        config([
            'auth.verification.active_key_id' => 'v1',
            'auth.verification.keys' => ['v1' => 'short-insecure-key'],
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('must be at least 32 bytes in production');
        OtpKeyManager::resolveKey('v1');
    }

    public function test_production_environment_forbids_array_delivery_driver(): void
    {
        $this->app->detectEnvironment(fn () => 'production');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('ArrayOtpDeliveryService is strictly forbidden in production');
        new ArrayOtpDeliveryService();
    }

    public function test_production_environment_forbids_unapproved_live_sms(): void
    {
        $service = new ProductionOtpDeliveryService();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Live SMS delivery is not yet configured for production');
        $service->sendSmsOtp('+2348011223344', '123456', []);
    }

    // =========================================================================
    // 7. SCHEMA GUARD TESTS
    // =========================================================================

    public function test_schema_guard_returns_503_when_verification_table_missing(): void
    {
        Schema::shouldReceive('hasTable')
            ->with('contact_verification_tokens')
            ->andReturn(false);

        [$guest, $token] = $this->createGuestWithToken(['email' => 'guard@example.com']);

        $res1 = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/v1/auth/verification/challenge', ['contact_type' => 'email']);
        $res1->assertStatus(503)
            ->assertJson([
                'status' => 'error',
                'code' => 'SCHEMA_UPGRADE_REQUIRED',
            ]);

        $res2 = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/v1/auth/verification/verify', [
                'challenge_id' => (string) Str::uuid7(),
                'otp' => '123456',
            ]);
        $res2->assertStatus(503);

        $res3 = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/v1/auth/verification/status');
        $res3->assertStatus(503);
    }

    // =========================================================================
    // 8. VERIFICATION STATUS ENDPOINT TESTS
    // =========================================================================

    public function test_status_endpoint_returns_contact_and_active_challenges(): void
    {
        [$guest, $token] = $this->createGuestWithToken([
            'email' => 'status@example.com',
            'phone_number' => '+2348012345678',
        ]);

        // Issue email challenge
        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/v1/auth/verification/challenge', ['contact_type' => 'email']);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/v1/auth/verification/status');

        $response->assertStatus(200)
            ->assertJson([
                'status' => 'success',
                'data' => [
                    'principal_type' => 'guest',
                    'email' => 'status@example.com',
                    'is_email_verified' => false,
                    'phone_number' => '+2348012345678',
                    'is_phone_verified' => false,
                ],
            ]);

        $this->assertCount(1, $response->json('data.active_challenges'));
        $this->assertEquals('email', $response->json('data.active_challenges.0.contact_type'));
        $this->assertEquals(5, $response->json('data.active_challenges.0.remaining_attempts'));
    }
}
