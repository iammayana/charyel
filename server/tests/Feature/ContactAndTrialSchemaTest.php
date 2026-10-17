<?php

namespace Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\DatabaseSafetyGuard;
use Tests\TestCase;

class ContactAndTrialSchemaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        DatabaseSafetyGuard::assertDestructiveOperationsAllowed();
    }

    /**
     * Helper to create a guest identity.
     */
    protected function createGuest(array $attributes = []): int
    {
        return DB::table('guest_identities')->insertGetId(array_merge([
            'guest_uuid' => (string) Str::uuid7(),
            'secret_hash' => password_hash('guest_secret_123', PASSWORD_BCRYPT),
            'created_at' => now(),
        ], $attributes));
    }

    /**
     * Helper to create a user.
     */
    protected function createUser(array $attributes = []): int
    {
        return DB::table('users')->insertGetId(array_merge([
            'name' => 'Pilgrim ' . Str::random(5),
            'email' => 'pilgrim_' . Str::random(8) . '@example.com',
            'phone_number' => null,
            'password' => bcrypt('secret123'),
            'created_at' => now(),
            'updated_at' => now(),
        ], $attributes));
    }

    // =========================================================================
    // 1. USERS SCHEMA EVOLUTION & CONTACT CONSTRAINTS
    // =========================================================================

    public function test_user_can_be_created_with_email_only(): void
    {
        $userId = $this->createUser([
            'email' => 'email_only@example.com',
            'phone_number' => null,
        ]);

        $this->assertDatabaseHas('users', [
            'id' => $userId,
            'email' => 'email_only@example.com',
            'phone_number' => null,
        ]);
    }

    public function test_user_can_be_created_with_phone_only(): void
    {
        $userId = $this->createUser([
            'email' => null,
            'phone_number' => '+2348011112222',
        ]);

        $this->assertDatabaseHas('users', [
            'id' => $userId,
            'email' => null,
            'phone_number' => '+2348011112222',
        ]);
    }

    public function test_user_can_be_created_with_both_email_and_phone(): void
    {
        $userId = $this->createUser([
            'email' => 'both_contacts@example.com',
            'phone_number' => '+2348099998888',
        ]);

        $this->assertDatabaseHas('users', [
            'id' => $userId,
            'email' => 'both_contacts@example.com',
            'phone_number' => '+2348099998888',
        ]);
    }

    public function test_user_without_email_and_without_phone_is_rejected_by_check_constraint(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('CHECK constraints enforced on MySQL');
        }

        $this->expectException(QueryException::class);
        $this->createUser([
            'email' => null,
            'phone_number' => null,
        ]);
    }

    public function test_user_phone_number_uniqueness_is_enforced(): void
    {
        $this->createUser(['email' => 'user1@example.com', 'phone_number' => '+2348012345678']);

        $this->expectException(QueryException::class);
        $this->createUser(['email' => 'user2@example.com', 'phone_number' => '+2348012345678']);
    }

    public function test_multiple_users_can_have_null_phone_numbers(): void
    {
        $user1 = $this->createUser(['email' => 'null_phone_1@example.com', 'phone_number' => null]);
        $user2 = $this->createUser(['email' => 'null_phone_2@example.com', 'phone_number' => null]);

        $this->assertNotEquals($user1, $user2);
    }

    public function test_multiple_users_can_have_null_emails(): void
    {
        $user1 = $this->createUser(['email' => null, 'phone_number' => '+2348011111111']);
        $user2 = $this->createUser(['email' => null, 'phone_number' => '+2348022222222']);

        $this->assertNotEquals($user1, $user2);
    }

    // =========================================================================
    // 2. GUEST IDENTITIES EVOLUTION & TRIAL ATTRIBUTES
    // =========================================================================

    public function test_guest_identities_have_contact_and_trial_columns_with_defaults(): void
    {
        $guestId = $this->createGuest();

        $guest = DB::table('guest_identities')->where('id', $guestId)->first();
        $this->assertNotNull($guest);
        $this->assertEquals(0, $guest->is_legacy);
        $this->assertNull($guest->email);
        $this->assertNull($guest->phone_number);
        $this->assertNull($guest->email_verified_at);
        $this->assertNull($guest->phone_verified_at);
        $this->assertEquals(0, $guest->completed_quiz_count);
        $this->assertEquals(0, $guest->gross_lifetime_xp);
        $this->assertNull($guest->active_quiz_session_id);
        $this->assertNull($guest->trial_limit_reached_at);
    }

    public function test_guest_identity_supports_contact_capture_and_trial_updates(): void
    {
        $guestId = $this->createGuest([
            'email' => 'guest@example.com',
            'phone_number' => '+2348033334444',
            'email_verified_at' => now(),
            'phone_verified_at' => now(),
            'completed_quiz_count' => 2,
            'gross_lifetime_xp' => 100,
        ]);

        $guest = DB::table('guest_identities')->where('id', $guestId)->first();
        $this->assertEquals('guest@example.com', $guest->email);
        $this->assertEquals('+2348033334444', $guest->phone_number);
        $this->assertEquals(2, $guest->completed_quiz_count);
        $this->assertEquals(100, $guest->gross_lifetime_xp);
    }

    // =========================================================================
    // 3. CONTACT VERIFICATION TOKENS
    // =========================================================================

    public function test_contact_verification_token_can_be_created_for_guest(): void
    {
        $guestId = $this->createGuest();
        $challengeId = (string) Str::uuid7();

        DB::table('contact_verification_tokens')->insert([
            'challenge_id' => $challengeId,
            'guest_identity_id' => $guestId,
            'user_id' => null,
            'contact_type' => 'email',
            'contact_value' => 'guest@example.com',
            'purpose' => 'guest_registration',
            'token_digest' => hash('sha256', 'otp:guest@example.com:123456'),
            'key_id' => 'v1',
            'attempts' => 0,
            'max_attempts' => 5,
            'expires_at' => now()->addMinutes(10),
            'created_at' => now(),
        ]);

        $this->assertDatabaseHas('contact_verification_tokens', [
            'challenge_id' => $challengeId,
            'guest_identity_id' => $guestId,
            'user_id' => null,
            'contact_type' => 'email',
        ]);
    }

    public function test_contact_verification_token_can_be_created_for_user(): void
    {
        $userId = $this->createUser();
        $challengeId = (string) Str::uuid7();

        DB::table('contact_verification_tokens')->insert([
            'challenge_id' => $challengeId,
            'guest_identity_id' => null,
            'user_id' => $userId,
            'contact_type' => 'phone',
            'contact_value' => '+2348011223344',
            'purpose' => 'user_contact_add',
            'token_digest' => hash('sha256', 'otp:+2348011223344:654321'),
            'key_id' => 'v1',
            'attempts' => 0,
            'max_attempts' => 5,
            'expires_at' => now()->addMinutes(10),
            'created_at' => now(),
        ]);

        $this->assertDatabaseHas('contact_verification_tokens', [
            'challenge_id' => $challengeId,
            'user_id' => $userId,
            'guest_identity_id' => null,
            'contact_type' => 'phone',
        ]);
    }

    public function test_contact_verification_token_rejects_both_owners_null(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('CHECK constraints enforced on MySQL');
        }

        $this->expectException(QueryException::class);
        DB::table('contact_verification_tokens')->insert([
            'challenge_id' => (string) Str::uuid7(),
            'guest_identity_id' => null,
            'user_id' => null,
            'contact_type' => 'email',
            'contact_value' => 'orphan@example.com',
            'purpose' => 'guest_registration',
            'token_digest' => hash('sha256', 'otp:orphan@example.com:123456'),
            'key_id' => 'v1',
            'expires_at' => now()->addMinutes(10),
            'created_at' => now(),
        ]);
    }

    public function test_contact_verification_token_rejects_both_owners_set(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('CHECK constraints enforced on MySQL');
        }

        $guestId = $this->createGuest();
        $userId = $this->createUser();

        $this->expectException(QueryException::class);
        DB::table('contact_verification_tokens')->insert([
            'challenge_id' => (string) Str::uuid7(),
            'guest_identity_id' => $guestId,
            'user_id' => $userId,
            'contact_type' => 'email',
            'contact_value' => 'dual@example.com',
            'purpose' => 'guest_registration',
            'token_digest' => hash('sha256', 'otp:dual@example.com:123456'),
            'key_id' => 'v1',
            'expires_at' => now()->addMinutes(10),
            'created_at' => now(),
        ]);
    }

    public function test_contact_verification_token_challenge_id_is_unique(): void
    {
        $guestId = $this->createGuest();
        $challengeId = (string) Str::uuid7();

        DB::table('contact_verification_tokens')->insert([
            'challenge_id' => $challengeId,
            'guest_identity_id' => $guestId,
            'user_id' => null,
            'contact_type' => 'email',
            'contact_value' => 'test@example.com',
            'purpose' => 'guest_registration',
            'token_digest' => hash('sha256', 'otp:1'),
            'key_id' => 'v1',
            'expires_at' => now()->addMinutes(10),
            'created_at' => now(),
        ]);

        $this->expectException(QueryException::class);
        DB::table('contact_verification_tokens')->insert([
            'challenge_id' => $challengeId, // Duplicate challenge_id!
            'guest_identity_id' => $guestId,
            'user_id' => null,
            'contact_type' => 'email',
            'contact_value' => 'test@example.com',
            'purpose' => 'guest_registration',
            'token_digest' => hash('sha256', 'otp:2'),
            'key_id' => 'v1',
            'expires_at' => now()->addMinutes(10),
            'created_at' => now(),
        ]);
    }

    public function test_contact_verification_tokens_cascade_on_guest_deletion(): void
    {
        $guestId = $this->createGuest();
        $challengeId = (string) Str::uuid7();

        DB::table('contact_verification_tokens')->insert([
            'challenge_id' => $challengeId,
            'guest_identity_id' => $guestId,
            'user_id' => null,
            'contact_type' => 'email',
            'contact_value' => 'cascade@example.com',
            'purpose' => 'guest_registration',
            'token_digest' => hash('sha256', 'otp:cascade'),
            'key_id' => 'v1',
            'expires_at' => now()->addMinutes(10),
            'created_at' => now(),
        ]);

        $this->assertEquals(1, DB::table('contact_verification_tokens')->where('challenge_id', $challengeId)->count());

        DB::table('guest_identities')->where('id', $guestId)->delete();

        $this->assertEquals(0, DB::table('contact_verification_tokens')->where('challenge_id', $challengeId)->count());
    }

    // =========================================================================
    // 4. OFFLINE QUIZ AUTHORIZATIONS & ACTIVE-SLOT INVARIANTS
    // =========================================================================

    public function test_offline_quiz_authorization_stores_manifest_and_evaluates_active_slot(): void
    {
        $guestId = $this->createGuest();
        $ticketId = (string) Str::uuid7();
        $manifestPayload = ['questions' => ['q1', 'q2', 'q3'], 'seed' => 'seed_123'];
        $manifestHash = hash('sha256', json_encode($manifestPayload));

        DB::table('offline_quiz_authorizations')->insert([
            'ticket_id' => $ticketId,
            'guest_identity_id' => $guestId,
            'slot_number' => 1,
            'generation' => 1,
            'manifest_payload' => json_encode($manifestPayload),
            'manifest_hash' => $manifestHash,
            'signature' => hash_hmac('sha256', $ticketId, 'secret_key'),
            'key_id' => 'v1',
            'status' => 'issued',
            'issued_at' => now(),
            'expires_at' => now()->addDays(7),
            'created_at' => now(),
        ]);

        $ticket = DB::table('offline_quiz_authorizations')->where('ticket_id', $ticketId)->first();
        $this->assertNotNull($ticket);
        $this->assertEquals(1, $ticket->slot_number);
        $this->assertEquals(1, $ticket->active_slot);
        $this->assertEquals('issued', $ticket->status);
        $this->assertEquals($manifestHash, $ticket->manifest_hash);
    }

    public function test_active_slot_becomes_null_when_status_changes_from_issued(): void
    {
        $guestId = $this->createGuest();
        $ticketId = (string) Str::uuid7();

        DB::table('offline_quiz_authorizations')->insert([
            'ticket_id' => $ticketId,
            'guest_identity_id' => $guestId,
            'slot_number' => 2,
            'generation' => 1,
            'manifest_payload' => json_encode(['questions' => ['q1']]),
            'manifest_hash' => hash('sha256', 'payload'),
            'signature' => 'sig_test',
            'key_id' => 'v1',
            'status' => 'issued',
            'expires_at' => now()->addDays(7),
            'created_at' => now(),
        ]);

        $ticketBefore = DB::table('offline_quiz_authorizations')->where('ticket_id', $ticketId)->first();
        $this->assertEquals(2, $ticketBefore->active_slot);

        // Transition to consumed
        DB::table('offline_quiz_authorizations')
            ->where('ticket_id', $ticketId)
            ->update([
                'status' => 'consumed',
                'consumed_at' => now(),
            ]);

        $ticketAfter = DB::table('offline_quiz_authorizations')->where('ticket_id', $ticketId)->first();
        $this->assertNull($ticketAfter->active_slot);
    }

    public function test_duplicate_active_ticket_in_same_slot_is_rejected_by_unique_constraint(): void
    {
        $guestId = $this->createGuest();

        // Slot 1 issued
        DB::table('offline_quiz_authorizations')->insert([
            'ticket_id' => (string) Str::uuid7(),
            'guest_identity_id' => $guestId,
            'slot_number' => 1,
            'generation' => 1,
            'manifest_payload' => json_encode(['q' => 1]),
            'manifest_hash' => hash('sha256', 'p1'),
            'signature' => 'sig_1',
            'key_id' => 'v1',
            'status' => 'issued',
            'expires_at' => now()->addDays(7),
            'created_at' => now(),
        ]);

        // Attempting to issue a second ticket for slot 1 while first is still 'issued' must fail
        $this->expectException(QueryException::class);
        DB::table('offline_quiz_authorizations')->insert([
            'ticket_id' => (string) Str::uuid7(),
            'guest_identity_id' => $guestId,
            'slot_number' => 1,
            'generation' => 2,
            'manifest_payload' => json_encode(['q' => 2]),
            'manifest_hash' => hash('sha256', 'p2'),
            'signature' => 'sig_2',
            'key_id' => 'v1',
            'status' => 'issued', // Conflict: (guestId, active_slot=1) already exists!
            'expires_at' => now()->addDays(7),
            'created_at' => now(),
        ]);
    }

    public function test_multiple_inactive_tickets_in_same_slot_can_coexist(): void
    {
        $guestId = $this->createGuest();

        // Ticket 1: consumed (active_slot = NULL)
        DB::table('offline_quiz_authorizations')->insert([
            'ticket_id' => (string) Str::uuid7(),
            'guest_identity_id' => $guestId,
            'slot_number' => 1,
            'generation' => 1,
            'manifest_payload' => json_encode(['q' => 1]),
            'manifest_hash' => hash('sha256', 'p1'),
            'signature' => 'sig_1',
            'key_id' => 'v1',
            'status' => 'consumed',
            'expires_at' => now()->addDays(7),
            'consumed_at' => now(),
            'created_at' => now(),
        ]);

        // Ticket 2: expired (active_slot = NULL)
        DB::table('offline_quiz_authorizations')->insert([
            'ticket_id' => (string) Str::uuid7(),
            'guest_identity_id' => $guestId,
            'slot_number' => 1,
            'generation' => 2,
            'manifest_payload' => json_encode(['q' => 2]),
            'manifest_hash' => hash('sha256', 'p2'),
            'signature' => 'sig_2',
            'key_id' => 'v1',
            'status' => 'expired',
            'expires_at' => now()->addDays(7),
            'created_at' => now(),
        ]);

        // Ticket 3: newly issued on slot 1 with generation 3 (active_slot = 1)
        DB::table('offline_quiz_authorizations')->insert([
            'ticket_id' => (string) Str::uuid7(),
            'guest_identity_id' => $guestId,
            'slot_number' => 1,
            'generation' => 3,
            'manifest_payload' => json_encode(['q' => 3]),
            'manifest_hash' => hash('sha256', 'p3'),
            'signature' => 'sig_3',
            'key_id' => 'v1',
            'status' => 'issued',
            'expires_at' => now()->addDays(7),
            'created_at' => now(),
        ]);

        $this->assertEquals(3, DB::table('offline_quiz_authorizations')->where('guest_identity_id', $guestId)->count());
        $this->assertEquals(1, DB::table('offline_quiz_authorizations')->where('guest_identity_id', $guestId)->whereNotNull('active_slot')->count());
    }

    public function test_guest_can_hold_issued_tickets_in_different_slots(): void
    {
        $guestId = $this->createGuest();

        DB::table('offline_quiz_authorizations')->insert([
            'ticket_id' => (string) Str::uuid7(),
            'guest_identity_id' => $guestId,
            'slot_number' => 1,
            'generation' => 1,
            'manifest_payload' => json_encode(['q' => 1]),
            'manifest_hash' => hash('sha256', 'p1'),
            'signature' => 'sig_1',
            'key_id' => 'v1',
            'status' => 'issued',
            'expires_at' => now()->addDays(7),
            'created_at' => now(),
        ]);

        DB::table('offline_quiz_authorizations')->insert([
            'ticket_id' => (string) Str::uuid7(),
            'guest_identity_id' => $guestId,
            'slot_number' => 2,
            'generation' => 1,
            'manifest_payload' => json_encode(['q' => 2]),
            'manifest_hash' => hash('sha256', 'p2'),
            'signature' => 'sig_2',
            'key_id' => 'v1',
            'status' => 'issued',
            'expires_at' => now()->addDays(7),
            'created_at' => now(),
        ]);

        $this->assertEquals(2, DB::table('offline_quiz_authorizations')->where('guest_identity_id', $guestId)->count());
    }

    public function test_slot_number_bounds_check_constraint_rejects_out_of_bounds(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('CHECK constraints enforced on MySQL');
        }

        $guestId = $this->createGuest();

        // Slot 0 is out of bounds (allowed: 1..3)
        $this->expectException(QueryException::class);
        DB::table('offline_quiz_authorizations')->insert([
            'ticket_id' => (string) Str::uuid7(),
            'guest_identity_id' => $guestId,
            'slot_number' => 0,
            'generation' => 1,
            'manifest_payload' => json_encode(['q' => 0]),
            'manifest_hash' => hash('sha256', 'p0'),
            'signature' => 'sig_0',
            'key_id' => 'v1',
            'status' => 'issued',
            'expires_at' => now()->addDays(7),
            'created_at' => now(),
        ]);
    }

    public function test_deleting_guest_identity_with_authorizations_is_restricted(): void
    {
        $guestId = $this->createGuest();

        DB::table('offline_quiz_authorizations')->insert([
            'ticket_id' => (string) Str::uuid7(),
            'guest_identity_id' => $guestId,
            'slot_number' => 1,
            'generation' => 1,
            'manifest_payload' => json_encode(['q' => 1]),
            'manifest_hash' => hash('sha256', 'p1'),
            'signature' => 'sig_1',
            'key_id' => 'v1',
            'status' => 'issued',
            'expires_at' => now()->addDays(7),
            'created_at' => now(),
        ]);

        $this->expectException(QueryException::class);
        // ON DELETE RESTRICT must prevent deleting guest_identities
        DB::table('guest_identities')->where('id', $guestId)->delete();
    }

    // =========================================================================
    // 5. GUEST IDENTITY CLAIMS
    // =========================================================================

    public function test_guest_identity_claim_records_transfer_totals(): void
    {
        $guestId = $this->createGuest();
        $userId = $this->createUser();

        $claimId = DB::table('guest_identity_claims')->insertGetId([
            'user_id' => $userId,
            'guest_identity_id' => $guestId,
            'gross_xp_transferred' => 120,
            'quizzes_transferred' => 2,
            'total_absorbed_competitive_xp' => 100,
            'total_unabsorbed_competitive_xp' => 20,
            'claimed_at' => now(),
        ]);

        $this->assertDatabaseHas('guest_identity_claims', [
            'id' => $claimId,
            'user_id' => $userId,
            'guest_identity_id' => $guestId,
            'gross_xp_transferred' => 120,
            'total_absorbed_competitive_xp' => 100,
        ]);
    }

    public function test_guest_identity_can_only_be_claimed_once(): void
    {
        $guestId = $this->createGuest();
        $user1 = $this->createUser();
        $user2 = $this->createUser();

        DB::table('guest_identity_claims')->insert([
            'user_id' => $user1,
            'guest_identity_id' => $guestId,
            'gross_xp_transferred' => 100,
            'quizzes_transferred' => 2,
            'total_absorbed_competitive_xp' => 100,
            'total_unabsorbed_competitive_xp' => 0,
            'claimed_at' => now(),
        ]);

        $this->expectException(QueryException::class);
        // Attempting to claim the same guest identity a second time must fail unique constraint
        DB::table('guest_identity_claims')->insert([
            'user_id' => $user2,
            'guest_identity_id' => $guestId,
            'gross_xp_transferred' => 100,
            'quizzes_transferred' => 2,
            'total_absorbed_competitive_xp' => 100,
            'total_unabsorbed_competitive_xp' => 0,
            'claimed_at' => now(),
        ]);
    }

    public function test_deleting_claimed_guest_identity_or_user_is_restricted(): void
    {
        $guestId = $this->createGuest();
        $userId = $this->createUser();

        DB::table('guest_identity_claims')->insert([
            'user_id' => $userId,
            'guest_identity_id' => $guestId,
            'gross_xp_transferred' => 50,
            'quizzes_transferred' => 1,
            'total_absorbed_competitive_xp' => 50,
            'total_unabsorbed_competitive_xp' => 0,
            'claimed_at' => now(),
        ]);

        // Attempting to delete user must fail RESTRICT
        try {
            DB::table('users')->where('id', $userId)->delete();
            $this->fail('Expected QueryException when deleting claiming user');
        } catch (QueryException $e) {
            $this->assertTrue(true);
        }

        // Attempting to delete guest identity must fail RESTRICT
        $this->expectException(QueryException::class);
        DB::table('guest_identities')->where('id', $guestId)->delete();
    }

    // =========================================================================
    // 6. GUEST CLAIM DAILY ATTRIBUTIONS & ACCOUNTING INVARIANTS
    // =========================================================================

    public function test_initial_claim_and_subsequent_late_sync_events_on_same_date_both_succeed(): void
    {
        $guestId = $this->createGuest();
        $userId = $this->createUser();

        $claimId = DB::table('guest_identity_claims')->insertGetId([
            'user_id' => $userId,
            'guest_identity_id' => $guestId,
            'gross_xp_transferred' => 140,
            'quizzes_transferred' => 2,
            'total_absorbed_competitive_xp' => 140,
            'total_unabsorbed_competitive_xp' => 0,
            'claimed_at' => now(),
        ]);

        $activityDate = '2026-10-17';

        // Event 1: Initial claim attribution for 100 XP on 2026-10-17
        $eventId1 = (string) Str::uuid7();
        DB::table('guest_claim_daily_attributions')->insert([
            'claim_id' => $claimId,
            'guest_identity_id' => $guestId,
            'user_id' => $userId,
            'activity_date' => $activityDate,
            'source_id' => 'claim_init:' . $activityDate,
            'guest_competitive_xp' => 100,
            'user_prior_competitive_xp' => 0,
            'absorbed_competitive_xp' => 100,
            'unabsorbed_competitive_xp' => 0,
            'is_league_eligible' => true,
            'event_id' => $eventId1,
            'created_at' => now(),
        ]);

        // Event 2: Legitimate late-sync offline ticket worth 40 XP arriving on the same date 2026-10-17
        $ticketId = (string) Str::uuid7();
        $eventId2 = (string) Str::uuid7();
        DB::table('guest_claim_daily_attributions')->insert([
            'claim_id' => $claimId,
            'guest_identity_id' => $guestId,
            'user_id' => $userId,
            'activity_date' => $activityDate,
            'source_id' => 'ticket:' . $ticketId,
            'guest_competitive_xp' => 40,
            'user_prior_competitive_xp' => 100, // Headroom remaining: 400 - 100 = 300
            'absorbed_competitive_xp' => 40,
            'unabsorbed_competitive_xp' => 0,
            'is_league_eligible' => true,
            'event_id' => $eventId2,
            'created_at' => now(),
        ]);

        // Both rows must coexist on the same accounting date
        $rows = DB::table('guest_claim_daily_attributions')
            ->where('guest_identity_id', $guestId)
            ->where('activity_date', $activityDate)
            ->get();

        $this->assertCount(2, $rows);
        $this->assertEquals(140, $rows->sum('absorbed_competitive_xp'));
    }

    public function test_duplicate_replay_of_same_source_event_is_rejected_even_with_fresh_event_id(): void
    {
        $guestId = $this->createGuest();
        $userId = $this->createUser();

        $claimId = DB::table('guest_identity_claims')->insertGetId([
            'user_id' => $userId,
            'guest_identity_id' => $guestId,
            'gross_xp_transferred' => 100,
            'quizzes_transferred' => 1,
            'total_absorbed_competitive_xp' => 100,
            'total_unabsorbed_competitive_xp' => 0,
            'claimed_at' => now(),
        ]);

        $ticketSourceId = 'ticket:' . Str::uuid7();

        // First submission
        DB::table('guest_claim_daily_attributions')->insert([
            'claim_id' => $claimId,
            'guest_identity_id' => $guestId,
            'user_id' => $userId,
            'activity_date' => '2026-10-17',
            'source_id' => $ticketSourceId,
            'guest_competitive_xp' => 100,
            'user_prior_competitive_xp' => 0,
            'absorbed_competitive_xp' => 100,
            'unabsorbed_competitive_xp' => 0,
            'is_league_eligible' => true,
            'event_id' => (string) Str::uuid7(),
            'created_at' => now(),
        ]);

        // Retry attempt generates a fresh event_id, but presents the identical source_id
        $this->expectException(QueryException::class);
        DB::table('guest_claim_daily_attributions')->insert([
            'claim_id' => $claimId,
            'guest_identity_id' => $guestId,
            'user_id' => $userId,
            'activity_date' => '2026-10-17',
            'source_id' => $ticketSourceId, // Duplicate source_id for this guest!
            'guest_competitive_xp' => 100,
            'user_prior_competitive_xp' => 100,
            'absorbed_competitive_xp' => 100,
            'unabsorbed_competitive_xp' => 0,
            'is_league_eligible' => true,
            'event_id' => (string) Str::uuid7(), // Fresh event_id does NOT bypass idempotency!
            'created_at' => now(),
        ]);
    }

    public function test_duplicate_event_id_is_rejected_by_unique_constraint(): void
    {
        $guestId = $this->createGuest();
        $userId = $this->createUser();

        $claimId = DB::table('guest_identity_claims')->insertGetId([
            'user_id' => $userId,
            'guest_identity_id' => $guestId,
            'gross_xp_transferred' => 100,
            'quizzes_transferred' => 1,
            'total_absorbed_competitive_xp' => 100,
            'total_unabsorbed_competitive_xp' => 0,
            'claimed_at' => now(),
        ]);

        $eventId = (string) Str::uuid7();

        DB::table('guest_claim_daily_attributions')->insert([
            'claim_id' => $claimId,
            'guest_identity_id' => $guestId,
            'user_id' => $userId,
            'activity_date' => '2026-10-17',
            'source_id' => 'src_1',
            'guest_competitive_xp' => 50,
            'user_prior_competitive_xp' => 0,
            'absorbed_competitive_xp' => 50,
            'unabsorbed_competitive_xp' => 0,
            'is_league_eligible' => true,
            'event_id' => $eventId,
            'created_at' => now(),
        ]);

        $this->expectException(QueryException::class);
        // Duplicate event_id must fail unique key constraint
        DB::table('guest_claim_daily_attributions')->insert([
            'claim_id' => $claimId,
            'guest_identity_id' => $guestId,
            'user_id' => $userId,
            'activity_date' => '2026-10-17',
            'source_id' => 'src_2',
            'guest_competitive_xp' => 50,
            'user_prior_competitive_xp' => 50,
            'absorbed_competitive_xp' => 50,
            'unabsorbed_competitive_xp' => 0,
            'is_league_eligible' => true,
            'event_id' => $eventId,
            'created_at' => now(),
        ]);
    }

    public function test_two_events_competing_for_headroom_cap_at_400_and_preserve_lifetime_xp(): void
    {
        $guestId = $this->createGuest();
        $userId = $this->createUser();

        // 1. Create user profile for lifetime XP tracking
        DB::table('user_profiles')->insert([
            'user_id' => $userId,
            'pilgrim_name' => 'Pilgrim ' . $userId,
            'avatar_seed' => 'seed_' . $userId,
            'total_xp' => 0,
            'current_streak' => 1,
            'longest_streak' => 1,
            'is_leaderboard_public' => true,
            'show_church_affiliation' => false,
            'updated_at' => now(),
        ]);

        $claimId = DB::table('guest_identity_claims')->insertGetId([
            'user_id' => $userId,
            'guest_identity_id' => $guestId,
            'gross_xp_transferred' => 450, // 300 + 150 = 450 gross lifetime XP
            'quizzes_transferred' => 3,
            'total_absorbed_competitive_xp' => 400, // Capped at exactly 400
            'total_unabsorbed_competitive_xp' => 50, // 50 unabsorbed points
            'claimed_at' => now(),
        ]);

        $activityDate = '2026-10-17';

        // Event 1: Absorbs 300 XP (prior = 0, new total = 300, headroom = 100)
        DB::table('guest_claim_daily_attributions')->insert([
            'claim_id' => $claimId,
            'guest_identity_id' => $guestId,
            'user_id' => $userId,
            'activity_date' => $activityDate,
            'source_id' => 'ticket:' . Str::uuid7(),
            'guest_competitive_xp' => 300,
            'user_prior_competitive_xp' => 0,
            'absorbed_competitive_xp' => 300,
            'unabsorbed_competitive_xp' => 0,
            'is_league_eligible' => true,
            'event_id' => (string) Str::uuid7(),
            'created_at' => now(),
        ]);

        // Event 2: 150 guest competitive XP competes for the remaining 100 XP headroom
        // Result: 100 absorbed, 50 unabsorbed (300 prior + 100 absorbed = 400 total)
        DB::table('guest_claim_daily_attributions')->insert([
            'claim_id' => $claimId,
            'guest_identity_id' => $guestId,
            'user_id' => $userId,
            'activity_date' => $activityDate,
            'source_id' => 'ticket:' . Str::uuid7(),
            'guest_competitive_xp' => 150,
            'user_prior_competitive_xp' => 300,
            'absorbed_competitive_xp' => 100,
            'unabsorbed_competitive_xp' => 50,
            'is_league_eligible' => true,
            'event_id' => (string) Str::uuid7(),
            'created_at' => now(),
        ]);

        // Verify total absorbed competitive XP reaches exactly 400
        $totalAbsorbed = DB::table('guest_claim_daily_attributions')
            ->where('guest_identity_id', $guestId)
            ->where('activity_date', $activityDate)
            ->sum('absorbed_competitive_xp');
        $this->assertEquals(400, $totalAbsorbed);

        // Verify total unabsorbed competitive XP is exactly 50
        $totalUnabsorbed = DB::table('guest_claim_daily_attributions')
            ->where('guest_identity_id', $guestId)
            ->where('activity_date', $activityDate)
            ->sum('unabsorbed_competitive_xp');
        $this->assertEquals(50, $totalUnabsorbed);

        // Verify lifetime progression preserves 100% of validated XP (450 XP)
        DB::table('user_profiles')->where('user_id', $userId)->increment('total_xp', 450);
        $profile = DB::table('user_profiles')->where('user_id', $userId)->first();
        $this->assertEquals(450, $profile->total_xp);
    }

    public function test_daily_attribution_rejects_exceeding_daily_cap_on_mysql(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('CHECK constraints enforced on MySQL');
        }

        $guestId = $this->createGuest();
        $userId = $this->createUser();

        $claimId = DB::table('guest_identity_claims')->insertGetId([
            'user_id' => $userId,
            'guest_identity_id' => $guestId,
            'gross_xp_transferred' => 200,
            'quizzes_transferred' => 3,
            'total_absorbed_competitive_xp' => 200,
            'total_unabsorbed_competitive_xp' => 0,
            'claimed_at' => now(),
        ]);

        $this->expectException(QueryException::class);
        // Bound violation: absorbed (150) + prior (300) = 450 > 400!
        DB::table('guest_claim_daily_attributions')->insert([
            'claim_id' => $claimId,
            'guest_identity_id' => $guestId,
            'user_id' => $userId,
            'activity_date' => '2026-10-17',
            'source_id' => 'src_cap_test',
            'guest_competitive_xp' => 150,
            'user_prior_competitive_xp' => 300,
            'absorbed_competitive_xp' => 150,
            'unabsorbed_competitive_xp' => 0,
            'is_league_eligible' => true,
            'event_id' => (string) Str::uuid7(),
            'created_at' => now(),
        ]);
    }

    public function test_daily_attribution_rejects_math_inconsistency_on_mysql(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('CHECK constraints enforced on MySQL');
        }

        $guestId = $this->createGuest();
        $userId = $this->createUser();

        $claimId = DB::table('guest_identity_claims')->insertGetId([
            'user_id' => $userId,
            'guest_identity_id' => $guestId,
            'gross_xp_transferred' => 100,
            'quizzes_transferred' => 2,
            'total_absorbed_competitive_xp' => 100,
            'total_unabsorbed_competitive_xp' => 0,
            'claimed_at' => now(),
        ]);

        $this->expectException(QueryException::class);
        // Math inconsistency: guest_competitive_xp (100) != absorbed (50) + unabsorbed (20) -> sum is 70!
        DB::table('guest_claim_daily_attributions')->insert([
            'claim_id' => $claimId,
            'guest_identity_id' => $guestId,
            'user_id' => $userId,
            'activity_date' => '2026-10-17',
            'source_id' => 'src_math_test',
            'guest_competitive_xp' => 100,
            'user_prior_competitive_xp' => 0,
            'absorbed_competitive_xp' => 50,
            'unabsorbed_competitive_xp' => 20, // Sum = 70 != 100
            'is_league_eligible' => true,
            'event_id' => (string) Str::uuid7(),
            'created_at' => now(),
        ]);
    }

    // =========================================================================
    // 7. ROLLBACK & REAPPLICATION INVARIANTS
    // =========================================================================

    public function test_migration_13_can_rollback_and_reapply_cleanly(): void
    {
        DatabaseSafetyGuard::assertDestructiveOperationsAllowed();

        // Roll back Migration 13
        $exitCode = Artisan::call('migrate:rollback', ['--step' => 1]);
        $this->assertEquals(0, $exitCode);

        $this->assertFalse(DB::getSchemaBuilder()->hasTable('guest_claim_daily_attributions'));
        $this->assertFalse(DB::getSchemaBuilder()->hasTable('guest_identity_claims'));
        $this->assertFalse(DB::getSchemaBuilder()->hasTable('offline_quiz_authorizations'));
        $this->assertFalse(DB::getSchemaBuilder()->hasTable('contact_verification_tokens'));

        // Parent tables still exist
        $this->assertTrue(DB::getSchemaBuilder()->hasTable('guest_identities'));
        $this->assertTrue(DB::getSchemaBuilder()->hasTable('users'));

        // Reapply Migration 13
        $exitCodeReapply = Artisan::call('migrate');
        $this->assertEquals(0, $exitCodeReapply);

        $this->assertTrue(DB::getSchemaBuilder()->hasTable('contact_verification_tokens'));
        $this->assertTrue(DB::getSchemaBuilder()->hasTable('offline_quiz_authorizations'));
        $this->assertTrue(DB::getSchemaBuilder()->hasTable('guest_identity_claims'));
        $this->assertTrue(DB::getSchemaBuilder()->hasTable('guest_claim_daily_attributions'));
    }

    public function test_migration_12_rollback_safeguard_refuses_when_phone_only_user_exists(): void
    {
        DatabaseSafetyGuard::assertDestructiveOperationsAllowed();

        // Create a phone-only user
        $this->createUser([
            'email' => null,
            'phone_number' => '+2348055556666',
        ]);

        // Rollback Migration 13 first so Migration 12 is at the top
        Artisan::call('migrate:rollback', ['--step' => 1]);

        try {
            Artisan::call('migrate:rollback', ['--step' => 1]);
            $this->fail('Expected RuntimeException when rolling back Migration 12 with phone-only users');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Cannot rollback Migration 12: Populated user phone credentials or verification state exist.', $e->getMessage());
        } finally {
            Artisan::call('migrate');
        }
    }

    public function test_migration_12_rollback_safeguard_refuses_when_guest_contact_exists(): void
    {
        DatabaseSafetyGuard::assertDestructiveOperationsAllowed();

        // Create a guest with email contact
        $this->createGuest([
            'email' => 'persisted_guest@example.com',
        ]);

        Artisan::call('migrate:rollback', ['--step' => 1]);

        try {
            Artisan::call('migrate:rollback', ['--step' => 1]);
            $this->fail('Expected RuntimeException when rolling back Migration 12 with guest contact');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Cannot rollback Migration 12: Populated guest contact details, verification state, or trial progress exist.', $e->getMessage());
        } finally {
            Artisan::call('migrate');
        }
    }

    public function test_migration_12_rollback_safeguard_refuses_when_guest_trial_progress_exists(): void
    {
        DatabaseSafetyGuard::assertDestructiveOperationsAllowed();

        // Create a guest with trial progress
        $this->createGuest([
            'completed_quiz_count' => 2,
            'gross_lifetime_xp' => 100,
        ]);

        Artisan::call('migrate:rollback', ['--step' => 1]);

        try {
            Artisan::call('migrate:rollback', ['--step' => 1]);
            $this->fail('Expected RuntimeException when rolling back Migration 12 with guest trial progress');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Cannot rollback Migration 12: Populated guest contact details, verification state, or trial progress exist.', $e->getMessage());
        } finally {
            Artisan::call('migrate');
        }
    }

    public function test_migration_12_rollback_safeguard_refuses_when_guest_verification_exists(): void
    {
        DatabaseSafetyGuard::assertDestructiveOperationsAllowed();

        // Create a guest with phone verification
        $this->createGuest([
            'phone_verified_at' => now(),
        ]);

        Artisan::call('migrate:rollback', ['--step' => 1]);

        try {
            Artisan::call('migrate:rollback', ['--step' => 1]);
            $this->fail('Expected RuntimeException when rolling back Migration 12 with guest verification');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Cannot rollback Migration 12: Populated guest contact details, verification state, or trial progress exist.', $e->getMessage());
        } finally {
            Artisan::call('migrate');
        }
    }

    public function test_migration_12_rollback_succeeds_cleanly_when_database_is_unpopulated(): void
    {
        DatabaseSafetyGuard::assertDestructiveOperationsAllowed();

        // Database is clean (no phone users, no populated guest contact/progress)
        Artisan::call('migrate:rollback', ['--step' => 1]); // Rollback Migration 13
        $exitCode = Artisan::call('migrate:rollback', ['--step' => 1]); // Rollback Migration 12
        $this->assertEquals(0, $exitCode);

        // Verify evolved columns were removed from guest_identities
        $this->assertFalse(DB::getSchemaBuilder()->hasColumn('guest_identities', 'completed_quiz_count'));
        $this->assertFalse(DB::getSchemaBuilder()->hasColumn('guest_identities', 'gross_lifetime_xp'));
        $this->assertFalse(DB::getSchemaBuilder()->hasColumn('guest_identities', 'active_quiz_session_id'));
        $this->assertFalse(DB::getSchemaBuilder()->hasColumn('users', 'phone_number'));

        // Baseline tables still intact
        $this->assertTrue(DB::getSchemaBuilder()->hasTable('guest_identities'));
        $this->assertTrue(DB::getSchemaBuilder()->hasTable('users'));

        // Reapply all migrations
        $exitCodeReapply = Artisan::call('migrate');
        $this->assertEquals(0, $exitCodeReapply);

        $this->assertTrue(DB::getSchemaBuilder()->hasColumn('guest_identities', 'completed_quiz_count'));
        $this->assertTrue(DB::getSchemaBuilder()->hasColumn('users', 'phone_number'));
    }
}
