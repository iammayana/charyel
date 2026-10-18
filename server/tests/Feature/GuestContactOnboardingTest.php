<?php

namespace Tests\Feature;

use App\Models\GuestIdentity;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Support\DatabaseSafetyGuard;
use Tests\TestCase;

class GuestContactOnboardingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        DatabaseSafetyGuard::assertDestructiveOperationsAllowed();

        // Register a temporary route for testing the EnsureGuestHasContact middleware
        Route::middleware(['auth:sanctum', 'auth.guest:guest:play', 'guest.contact'])
            ->get('/api/v1/test/guest-gameplay-guard', function () {
                return response()->json([
                    'status' => 'success',
                    'message' => 'Gameplay access granted.',
                ]);
            });
    }

    /**
     * Helper to generate a canonical 256-bit hex secret (64 characters).
     */
    protected function generateValidHexSecret(): string
    {
        return bin2hex(random_bytes(32));
    }

    public function test_guest_registration_with_email_only_succeeds(): void
    {
        $uuid7 = (string) Str::uuid7();
        $secret = $this->generateValidHexSecret();

        $response = $this->postJson('/api/v1/auth/guest', [
            'guest_uuid' => $uuid7,
            'guest_secret' => $secret,
            'email' => 'pilgrim@example.com',
            'device_name' => 'Pilgrim Device',
        ]);

        $response->assertStatus(201)
            ->assertJson([
                'status' => 'success',
                'data' => [
                    'auth_mode' => 'bearer',
                    'guest_uuid' => $uuid7,
                    'is_claimed' => false,
                    'is_legacy' => false,
                    'contact' => [
                        'email' => 'pilgrim@example.com',
                        'phone_number' => null,
                        'email_verified' => false,
                        'phone_verified' => false,
                        'has_contact' => true,
                    ],
                ],
            ]);

        $this->assertDatabaseHas('guest_identities', [
            'guest_uuid' => $uuid7,
            'email' => 'pilgrim@example.com',
            'phone_number' => null,
            'is_legacy' => 0,
        ]);
    }

    public function test_guest_registration_with_phone_only_succeeds_with_e164_format(): void
    {
        $uuid7 = (string) Str::uuid7();
        $secret = $this->generateValidHexSecret();

        $response = $this->postJson('/api/v1/auth/guest', [
            'guest_uuid' => $uuid7,
            'guest_secret' => $secret,
            'phone_number' => '+15551234567',
        ]);

        $response->assertStatus(201)
            ->assertJson([
                'status' => 'success',
                'data' => [
                    'auth_mode' => 'bearer',
                    'guest_uuid' => $uuid7,
                    'is_claimed' => false,
                    'is_legacy' => false,
                    'contact' => [
                        'email' => null,
                        'phone_number' => '+15551234567',
                        'email_verified' => false,
                        'phone_verified' => false,
                        'has_contact' => true,
                    ],
                ],
            ]);

        $this->assertDatabaseHas('guest_identities', [
            'guest_uuid' => $uuid7,
            'email' => null,
            'phone_number' => '+15551234567',
            'is_legacy' => 0,
        ]);
    }

    public function test_guest_registration_with_both_email_and_phone_succeeds(): void
    {
        $uuid7 = (string) Str::uuid7();
        $secret = $this->generateValidHexSecret();

        $response = $this->postJson('/api/v1/auth/guest', [
            'guest_uuid' => $uuid7,
            'guest_secret' => $secret,
            'email' => 'dual@example.com',
            'phone_number' => '+442071838750',
        ]);

        $response->assertStatus(201)
            ->assertJson([
                'status' => 'success',
                'data' => [
                    'guest_uuid' => $uuid7,
                    'is_legacy' => false,
                    'contact' => [
                        'email' => 'dual@example.com',
                        'phone_number' => '+442071838750',
                        'email_verified' => false,
                        'phone_verified' => false,
                        'has_contact' => true,
                    ],
                ],
            ]);

        $this->assertDatabaseHas('guest_identities', [
            'guest_uuid' => $uuid7,
            'email' => 'dual@example.com',
            'phone_number' => '+442071838750',
        ]);
    }

    public function test_guest_registration_without_any_contact_fails_with_422(): void
    {
        $uuid7 = (string) Str::uuid7();
        $secret = $this->generateValidHexSecret();

        $response = $this->postJson('/api/v1/auth/guest', [
            'guest_uuid' => $uuid7,
            'guest_secret' => $secret,
        ]);

        $response->assertStatus(422)
            ->assertJson([
                'status' => 'error',
                'code' => 'GUEST_CONTACT_REQUIRED',
            ]);

        $this->assertDatabaseMissing('guest_identities', [
            'guest_uuid' => $uuid7,
        ]);
    }

    public function test_guest_registration_rejects_empty_contact_strings(): void
    {
        $uuid7 = (string) Str::uuid7();
        $secret = $this->generateValidHexSecret();

        $response = $this->postJson('/api/v1/auth/guest', [
            'guest_uuid' => $uuid7,
            'guest_secret' => $secret,
            'email' => '   ',
            'phone_number' => '',
        ]);

        $response->assertStatus(422);
        $this->assertDatabaseMissing('guest_identities', [
            'guest_uuid' => $uuid7,
        ]);
    }

    public function test_guest_registration_rejects_malformed_email(): void
    {
        $uuid7 = (string) Str::uuid7();
        $secret = $this->generateValidHexSecret();

        $response = $this->postJson('/api/v1/auth/guest', [
            'guest_uuid' => $uuid7,
            'guest_secret' => $secret,
            'email' => 'invalid-email-format',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['email']);
    }

    public function test_guest_registration_rejects_malformed_phone_number(): void
    {
        $uuid7 = (string) Str::uuid7();
        $secret = $this->generateValidHexSecret();

        // Missing plus prefix
        $res1 = $this->postJson('/api/v1/auth/guest', [
            'guest_uuid' => $uuid7,
            'guest_secret' => $secret,
            'phone_number' => '15551234567',
        ]);
        $res1->assertStatus(422)->assertJsonValidationErrors(['phone_number']);

        // Non-digit characters
        $res2 = $this->postJson('/api/v1/auth/guest', [
            'guest_uuid' => $uuid7,
            'guest_secret' => $secret,
            'phone_number' => '+1555ABC4567',
        ]);
        $res2->assertStatus(422)->assertJsonValidationErrors(['phone_number']);

        // Too short (less than 7 digits)
        $res3 = $this->postJson('/api/v1/auth/guest', [
            'guest_uuid' => $uuid7,
            'guest_secret' => $secret,
            'phone_number' => '+12345',
        ]);
        $res3->assertStatus(422)->assertJsonValidationErrors(['phone_number']);
    }

    public function test_client_cannot_force_is_legacy_flag_on_registration(): void
    {
        $uuid7 = (string) Str::uuid7();
        $secret = $this->generateValidHexSecret();

        $response = $this->postJson('/api/v1/auth/guest', [
            'guest_uuid' => $uuid7,
            'guest_secret' => $secret,
            'email' => 'clientlegacy@example.com',
            'is_legacy' => true,
        ]);

        $response->assertStatus(201)
            ->assertJson([
                'status' => 'success',
                'data' => [
                    'is_legacy' => false,
                ],
            ]);

        $guest = GuestIdentity::where('guest_uuid', $uuid7)->first();
        $this->assertNotNull($guest);
        $this->assertFalse((bool) $guest->is_legacy);
    }

    public function test_legacy_guest_reauthentication_without_contact_succeeds(): void
    {
        $uuid7 = (string) Str::uuid7();
        $secret = $this->generateValidHexSecret();

        // Genuinely pre-existing guest identity backfilled with is_legacy = 1
        $legacyGuest = new GuestIdentity();
        $legacyGuest->guest_uuid = $uuid7;
        $legacyGuest->secret_hash = Hash::make($secret);
        $legacyGuest->is_legacy = true;
        $legacyGuest->email = null;
        $legacyGuest->phone_number = null;
        $legacyGuest->save();

        // Reauthenticate presenting only uuid and secret
        $response = $this->postJson('/api/v1/auth/guest', [
            'guest_uuid' => $uuid7,
            'guest_secret' => $secret,
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'status' => 'success',
                'data' => [
                    'guest_uuid' => $uuid7,
                    'is_legacy' => true,
                    'contact' => [
                        'email' => null,
                        'phone_number' => null,
                        'has_contact' => false,
                    ],
                ],
            ]);
    }

    public function test_transitional_contactless_guest_reauthentication_without_contact_succeeds_and_retains_non_legacy(): void
    {
        $uuid7 = (string) Str::uuid7();
        $secret = $this->generateValidHexSecret();

        // Transitional contactless identity (is_legacy = 0)
        $transitionalGuest = new GuestIdentity();
        $transitionalGuest->guest_uuid = $uuid7;
        $transitionalGuest->secret_hash = Hash::make($secret);
        $transitionalGuest->is_legacy = false;
        $transitionalGuest->email = null;
        $transitionalGuest->phone_number = null;
        $transitionalGuest->save();

        // Reauthenticate presenting only uuid and secret
        $response = $this->postJson('/api/v1/auth/guest', [
            'guest_uuid' => $uuid7,
            'guest_secret' => $secret,
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'status' => 'success',
                'data' => [
                    'guest_uuid' => $uuid7,
                    'is_legacy' => false, // MUST NOT be converted to is_legacy = true
                    'contact' => [
                        'email' => null,
                        'phone_number' => null,
                        'has_contact' => false,
                    ],
                ],
            ]);

        $guestInDb = GuestIdentity::where('guest_uuid', $uuid7)->first();
        $this->assertFalse((bool) $guestInDb->is_legacy);
    }

    public function test_legacy_guest_can_attach_contact_during_reauthentication(): void
    {
        $uuid7 = (string) Str::uuid7();
        $secret = $this->generateValidHexSecret();

        $legacyGuest = new GuestIdentity();
        $legacyGuest->guest_uuid = $uuid7;
        $legacyGuest->secret_hash = Hash::make($secret);
        $legacyGuest->is_legacy = true;
        $legacyGuest->email = null;
        $legacyGuest->phone_number = null;
        $legacyGuest->save();

        $response = $this->postJson('/api/v1/auth/guest', [
            'guest_uuid' => $uuid7,
            'guest_secret' => $secret,
            'email' => 'attached@example.com',
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'status' => 'success',
                'data' => [
                    'contact' => [
                        'email' => 'attached@example.com',
                        'has_contact' => true,
                    ],
                ],
            ]);

        $this->assertDatabaseHas('guest_identities', [
            'guest_uuid' => $uuid7,
            'email' => 'attached@example.com',
            'is_legacy' => 1,
        ]);
    }

    public function test_authenticated_guest_can_attach_missing_email_via_put_contact(): void
    {
        $uuid7 = (string) Str::uuid7();
        $secret = $this->generateValidHexSecret();

        $guest = GuestIdentity::create([
            'guest_uuid' => $uuid7,
            'secret_hash' => Hash::make($secret),
            'phone_number' => '+15553334444',
        ]);
        $token = $guest->createToken('Device', ['guest:play'])->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->putJson('/api/v1/auth/guest/contact', [
                'email' => 'added@example.com',
            ]);

        $response->assertStatus(200)
            ->assertJson([
                'status' => 'success',
                'message' => 'Contact details attached successfully.',
                'data' => [
                    'guest_uuid' => $uuid7,
                    'contact' => [
                        'email' => 'added@example.com',
                        'phone_number' => '+15553334444',
                        'has_contact' => true,
                    ],
                ],
            ]);

        $this->assertDatabaseHas('guest_identities', [
            'guest_uuid' => $uuid7,
            'email' => 'added@example.com',
            'phone_number' => '+15553334444',
        ]);
    }

    public function test_authenticated_guest_can_attach_missing_phone_via_put_contact(): void
    {
        $uuid7 = (string) Str::uuid7();
        $secret = $this->generateValidHexSecret();

        $guest = GuestIdentity::create([
            'guest_uuid' => $uuid7,
            'secret_hash' => Hash::make($secret),
            'email' => 'existing@example.com',
        ]);
        $token = $guest->createToken('Device', ['guest:play'])->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->putJson('/api/v1/auth/guest/contact', [
                'phone_number' => '+15558889999',
            ]);

        $response->assertStatus(200)
            ->assertJson([
                'status' => 'success',
                'data' => [
                    'contact' => [
                        'email' => 'existing@example.com',
                        'phone_number' => '+15558889999',
                        'has_contact' => true,
                    ],
                ],
            ]);

        $this->assertDatabaseHas('guest_identities', [
            'guest_uuid' => $uuid7,
            'email' => 'existing@example.com',
            'phone_number' => '+15558889999',
        ]);
    }

    public function test_put_guest_contact_rejects_empty_request_with_422(): void
    {
        $uuid7 = (string) Str::uuid7();
        $secret = $this->generateValidHexSecret();

        $guest = GuestIdentity::create([
            'guest_uuid' => $uuid7,
            'secret_hash' => Hash::make($secret),
            'email' => 'existing@example.com',
        ]);
        $token = $guest->createToken('Device', ['guest:play'])->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->putJson('/api/v1/auth/guest/contact', []);

        $response->assertStatus(422)
            ->assertJson([
                'status' => 'error',
                'code' => 'GUEST_CONTACT_REQUIRED',
            ]);
    }

    public function test_attempting_to_replace_existing_email_via_put_contact_returns_409_conflict(): void
    {
        $uuid7 = (string) Str::uuid7();
        $secret = $this->generateValidHexSecret();

        $guest = GuestIdentity::create([
            'guest_uuid' => $uuid7,
            'secret_hash' => Hash::make($secret),
            'email' => 'original@example.com',
        ]);
        $token = $guest->createToken('Device', ['guest:play'])->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->putJson('/api/v1/auth/guest/contact', [
                'email' => 'replacement@example.com',
            ]);

        $response->assertStatus(409)
            ->assertJson([
                'status' => 'error',
                'code' => 'CONTACT_REPLACEMENT_FORBIDDEN',
                'message' => 'Existing email cannot be replaced without verified contact-change workflow.',
            ]);

        $this->assertDatabaseHas('guest_identities', [
            'guest_uuid' => $uuid7,
            'email' => 'original@example.com',
        ]);
    }

    public function test_attempting_to_replace_existing_phone_via_put_contact_returns_409_conflict(): void
    {
        $uuid7 = (string) Str::uuid7();
        $secret = $this->generateValidHexSecret();

        $guest = GuestIdentity::create([
            'guest_uuid' => $uuid7,
            'secret_hash' => Hash::make($secret),
            'phone_number' => '+15551112222',
        ]);
        $token = $guest->createToken('Device', ['guest:play'])->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->putJson('/api/v1/auth/guest/contact', [
                'phone_number' => '+15559998888',
            ]);

        $response->assertStatus(409)
            ->assertJson([
                'status' => 'error',
                'code' => 'CONTACT_REPLACEMENT_FORBIDDEN',
                'message' => 'Existing phone number cannot be replaced without verified contact-change workflow.',
            ]);

        $this->assertDatabaseHas('guest_identities', [
            'guest_uuid' => $uuid7,
            'phone_number' => '+15551112222',
        ]);
    }

    public function test_idempotent_put_contact_with_identical_value_succeeds(): void
    {
        $uuid7 = (string) Str::uuid7();
        $secret = $this->generateValidHexSecret();

        $guest = GuestIdentity::create([
            'guest_uuid' => $uuid7,
            'secret_hash' => Hash::make($secret),
            'email' => 'same@example.com',
        ]);
        $token = $guest->createToken('Device', ['guest:play'])->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->putJson('/api/v1/auth/guest/contact', [
                'email' => 'same@example.com',
            ]);

        $response->assertStatus(200);
    }

    public function test_reauthentication_rejects_contact_replacement_with_409_conflict(): void
    {
        $uuid7 = (string) Str::uuid7();
        $secret = $this->generateValidHexSecret();

        // Guest with existing email
        GuestIdentity::create([
            'guest_uuid' => $uuid7,
            'secret_hash' => Hash::make($secret),
            'email' => 'locked@example.com',
        ]);

        // Attempt reauthentication providing a different email
        $response = $this->postJson('/api/v1/auth/guest', [
            'guest_uuid' => $uuid7,
            'guest_secret' => $secret,
            'email' => 'hijack@example.com',
        ]);

        $response->assertStatus(409)
            ->assertJson([
                'status' => 'error',
                'code' => 'CONTACT_REPLACEMENT_FORBIDDEN',
            ]);

        $this->assertDatabaseHas('guest_identities', [
            'guest_uuid' => $uuid7,
            'email' => 'locked@example.com',
        ]);
    }

    public function test_reauthentication_with_wrong_secret_does_not_attach_contact_or_disclose_state(): void
    {
        $uuid7 = (string) Str::uuid7();
        $realSecret = $this->generateValidHexSecret();
        $wrongSecret = $this->generateValidHexSecret();

        // Contactless guest
        $guest = new GuestIdentity();
        $guest->guest_uuid = $uuid7;
        $guest->secret_hash = Hash::make($realSecret);
        $guest->email = null;
        $guest->save();

        // Attacker attempts reauthentication with wrong secret and malicious email
        $response = $this->postJson('/api/v1/auth/guest', [
            'guest_uuid' => $uuid7,
            'guest_secret' => $wrongSecret,
            'email' => 'attacker@example.com',
        ]);

        $response->assertStatus(401)
            ->assertJson([
                'status' => 'error',
                'message' => 'Invalid guest credentials.',
            ]);

        // Database must remain completely unmodified
        $this->assertDatabaseHas('guest_identities', [
            'guest_uuid' => $uuid7,
            'email' => null,
        ]);
    }

    public function test_claimed_guest_is_forbidden_from_attaching_contact_via_put(): void
    {
        $uuid7 = (string) Str::uuid7();
        $secret = $this->generateValidHexSecret();

        $user = User::create([
            'name' => 'Claimant',
            'email' => 'claimant@example.com',
            'password' => Hash::make('Kingdom2026!Key'),
        ]);

        $guest = GuestIdentity::create([
            'guest_uuid' => $uuid7,
            'secret_hash' => Hash::make($secret),
            'claimed_by_user_id' => $user->id,
            'claimed_at' => now(),
        ]);
        $token = $guest->createToken('Old Token', ['guest:play'])->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->putJson('/api/v1/auth/guest/contact', [
                'email' => 'newcontact@example.com',
            ]);

        $response->assertStatus(403);
    }

    public function test_registered_user_cannot_access_put_guest_contact(): void
    {
        $user = User::create([
            'name' => 'Registered User',
            'email' => 'registered@example.com',
            'password' => Hash::make('Kingdom2026!Key'),
        ]);
        $token = $user->createToken('Device', ['user:all'])->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->putJson('/api/v1/auth/guest/contact', [
                'email' => 'userguest@example.com',
            ]);

        $response->assertStatus(403);
    }

    public function test_unauthenticated_request_to_put_guest_contact_returns_401(): void
    {
        $response = $this->putJson('/api/v1/auth/guest/contact', [
            'email' => 'noauth@example.com',
        ]);

        $response->assertStatus(401);
    }

    public function test_guest_me_endpoint_returns_contact_and_legacy_state(): void
    {
        $uuid7 = (string) Str::uuid7();
        $secret = $this->generateValidHexSecret();

        $guest = GuestIdentity::create([
            'guest_uuid' => $uuid7,
            'secret_hash' => Hash::make($secret),
            'email' => 'checkme@example.com',
            'phone_number' => '+15554445555',
        ]);
        $token = $guest->createToken('Device', ['guest:play'])->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/v1/auth/guest/me');

        $response->assertStatus(200)
            ->assertJson([
                'status' => 'success',
                'data' => [
                    'guest_uuid' => $uuid7,
                    'is_claimed' => false,
                    'is_legacy' => false,
                    'contact' => [
                        'email' => 'checkme@example.com',
                        'phone_number' => '+15554445555',
                        'email_verified' => false,
                        'phone_verified' => false,
                        'has_contact' => true,
                    ],
                ],
            ]);
    }

    public function test_ensure_guest_has_contact_middleware_blocks_contactless_guest(): void
    {
        $uuid7 = (string) Str::uuid7();
        $secret = $this->generateValidHexSecret();

        // 1. Contactless guest (legacy or transitional)
        $contactlessGuest = new GuestIdentity();
        $contactlessGuest->guest_uuid = $uuid7;
        $contactlessGuest->secret_hash = Hash::make($secret);
        $contactlessGuest->is_legacy = true;
        $contactlessGuest->email = null;
        $contactlessGuest->phone_number = null;
        $contactlessGuest->save();

        $token = $contactlessGuest->createToken('Device', ['guest:play'])->plainTextToken;

        // Attempting to access protected gameplay route without contact MUST return 403
        $blockedResponse = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/v1/test/guest-gameplay-guard');

        $blockedResponse->assertStatus(403)
            ->assertJson([
                'status' => 'error',
                'code' => 'GUEST_CONTACT_REQUIRED',
                'message' => 'Contact details (email or phone) are required before continuing.',
            ]);

        // 2. Reset auth guards so Sanctum resolves the second token afresh
        auth()->forgetGuards();

        $guestWithContact = GuestIdentity::create([
            'guest_uuid' => (string) Str::uuid7(),
            'secret_hash' => Hash::make($secret),
            'email' => 'player@example.com',
        ]);
        $playToken = $guestWithContact->createToken('Play Device', ['guest:play'])->plainTextToken;

        $allowedResponse = $this->withHeader('Authorization', 'Bearer ' . $playToken)
            ->getJson('/api/v1/test/guest-gameplay-guard');

        $allowedResponse->assertStatus(200)
            ->assertJson([
                'status' => 'success',
                'message' => 'Gameplay access granted.',
            ]);
    }




    public function test_schema_upgrade_required_guard_returns_503_when_columns_missing(): void
    {
        // Mock Schema::hasColumns to simulate an unmigrated database environment
        Schema::shouldReceive('hasColumns')
            ->with('guest_identities', ['email', 'phone_number', 'is_legacy'])
            ->andReturn(false);

        $uuid7 = (string) Str::uuid7();
        $secret = $this->generateValidHexSecret();

        $response = $this->postJson('/api/v1/auth/guest', [
            'guest_uuid' => $uuid7,
            'guest_secret' => $secret,
            'email' => 'guard@example.com',
        ]);

        $response->assertStatus(503)
            ->assertJson([
                'status' => 'error',
                'code' => 'SCHEMA_UPGRADE_REQUIRED',
                'message' => 'Database schema upgrade required. Please run pending migrations.',
            ]);
    }
}
