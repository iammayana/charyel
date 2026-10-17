<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class SchemaAndAccountingInvariantTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        \Tests\Support\DatabaseSafetyGuard::assertDestructiveOperationsAllowed();
    }

    /**
     * Test 1: All tables exist and publication_sequences is seeded idempotently.
     */
    public function test_schema_tables_exist_and_publication_counter_is_seeded(): void
    {
        $this->assertTrue(DB::getSchemaBuilder()->hasTable('countries'));
        $this->assertTrue(DB::getSchemaBuilder()->hasTable('regions'));
        $this->assertTrue(DB::getSchemaBuilder()->hasTable('localities'));
        $this->assertTrue(DB::getSchemaBuilder()->hasTable('churches'));
        $this->assertTrue(DB::getSchemaBuilder()->hasTable('guest_identities'));
        $this->assertTrue(DB::getSchemaBuilder()->hasTable('user_profiles'));
        $this->assertTrue(DB::getSchemaBuilder()->hasTable('church_memberships'));
        $this->assertTrue(DB::getSchemaBuilder()->hasTable('publication_sequences'));
        $this->assertTrue(DB::getSchemaBuilder()->hasTable('questions'));
        $this->assertTrue(DB::getSchemaBuilder()->hasTable('question_revisions'));
        $this->assertTrue(DB::getSchemaBuilder()->hasTable('question_changes'));
        $this->assertTrue(DB::getSchemaBuilder()->hasTable('quiz_sessions'));
        $this->assertTrue(DB::getSchemaBuilder()->hasTable('quiz_session_items'));
        $this->assertTrue(DB::getSchemaBuilder()->hasTable('user_xp_ledger'));
        $this->assertTrue(DB::getSchemaBuilder()->hasTable('processed_events'));
        $this->assertTrue(DB::getSchemaBuilder()->hasTable('league_periods'));
        $this->assertTrue(DB::getSchemaBuilder()->hasTable('leaderboard_weekly_rollups'));
        $this->assertTrue(DB::getSchemaBuilder()->hasTable('leaderboard_finalized_podiums'));
        $this->assertTrue(DB::getSchemaBuilder()->hasTable('user_daily_competitive_counters'));

        // Verify singleton publication counter was seeded to 0
        $seq = DB::table('publication_sequences')->where('id', 1)->first();
        $this->assertNotNull($seq);
        $this->assertEquals(0, $seq->current_sequence);
    }

    /**
     * Test 2: Foreign key insertion order for new questions succeeds without constraint violations.
     */
    public function test_question_publication_foreign_key_order_succeeds(): void
    {
        $questionId = (string) Str::uuid7();

        // 1. Create parent question first
        DB::table('questions')->insert([
            'id' => $questionId,
            'testament' => 'NT',
            'book' => 'Acts',
            'chapter' => 17,
            'verse' => 11,
            'scripture_reference' => 'Acts 17:11',
            'scripture_version' => 'KJV',
            'category' => 'Acts & Early Church',
            'difficulty' => 'medium',
            'version' => 1,
            'current_revision_id' => null,
            'is_published' => true,
            'is_withdrawn' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // 2. Insert revision (FK fk_revisions_question succeeds)
        $revId = DB::table('question_revisions')->insertGetId([
            'question_id' => $questionId,
            'version' => 1,
            'question_text' => 'What did the Bereans examine daily?',
            'options' => json_encode([['id' => 'opt_a', 'text' => 'Scriptures']]),
            'correct_option_id' => 'opt_a',
            'explanation' => 'Acts 17:11 notes they searched the scriptures daily.',
            'created_at' => now(),
        ]);

        // 3. Update current revision on parent question
        DB::table('questions')->where('id', $questionId)->update(['current_revision_id' => $revId]);

        // 4. Record change
        DB::table('question_changes')->insert([
            'sequence_id' => 1,
            'question_id' => $questionId,
            'revision_id' => $revId,
            'operation' => 'created',
            'published_at' => now(),
        ]);

        $this->assertDatabaseHas('questions', ['id' => $questionId, 'current_revision_id' => $revId]);
        $this->assertDatabaseHas('question_changes', ['sequence_id' => 1, 'question_id' => $questionId]);
    }

    /**
     * Test 3: Guest identities can be created and earn XP in the ledger with principal_key.
     */
    public function test_guest_identity_and_xp_ledger_principal_key(): void
    {
        $guestUuid = (string) Str::uuid7();
        $guestId = DB::table('guest_identities')->insertGetId([
            'guest_uuid' => $guestUuid,
            'secret_hash' => password_hash('secret_token_123', PASSWORD_BCRYPT),
            'created_at' => now(),
        ]);

        $eventId = (string) Str::uuid7();

        // Guest earns XP
        DB::table('user_xp_ledger')->insert([
            'user_id' => null,
            'guest_identity_id' => $guestId,
            'reward_source' => 'quiz_completion',
            'source_id' => 'session_guest_01',
            'event_id' => $eventId,
            'xp_amount' => 100,
            'awarded_at' => now(),
        ]);

        $this->assertDatabaseHas('user_xp_ledger', [
            'guest_identity_id' => $guestId,
            'principal_key' => 'G:' . $guestId,
            'xp_amount' => 100,
        ]);

        // Attempt duplicate award for same guest, reward source and source_id -> must fail
        $this->expectException(\Illuminate\Database\QueryException::class);
        DB::table('user_xp_ledger')->insert([
            'user_id' => null,
            'guest_identity_id' => $guestId,
            'reward_source' => 'quiz_completion',
            'source_id' => 'session_guest_01', // Duplicate source_id!
            'event_id' => (string) Str::uuid7(),
            'xp_amount' => 100,
            'awarded_at' => now(),
        ]);
    }

    /**
     * Test 4: Active primary church conditional uniqueness prevents multiple active primary churches.
     */
    public function test_active_primary_church_conditional_uniqueness(): void
    {
        // Create country, region, locality, church
        DB::table('countries')->insert(['code' => 'NG', 'name' => 'Nigeria', 'currency_code' => 'NGN']);
        $regId = DB::table('regions')->insertGetId(['country_code' => 'NG', 'name' => 'Kaduna State']);
        $locId = DB::table('localities')->insertGetId(['region_id' => $regId, 'name' => 'Sabon Tasha', 'slug' => 'sabon-tasha']);
        $church1 = DB::table('churches')->insertGetId(['locality_id' => $locId, 'name' => 'ECWA Goodnews', 'slug' => 'ecwa-goodnews']);
        $church2 = DB::table('churches')->insertGetId(['locality_id' => $locId, 'name' => 'Baptist Church', 'slug' => 'baptist-church']);

        // Create user
        $userId = DB::table('users')->insertGetId([
            'name' => 'Pilgrim User',
            'email' => 'pilgrim@charyel.com',
            'password' => 'secret',
        ]);

        // First active primary membership succeeds
        DB::table('church_memberships')->insert([
            'user_id' => $userId,
            'church_id' => $church1,
            'is_primary' => true,
            'status' => 'active',
        ]);

        // Non-primary membership for church 2 succeeds (unlimited secondary memberships)
        DB::table('church_memberships')->insert([
            'user_id' => $userId,
            'church_id' => $church2,
            'is_primary' => false,
            'status' => 'active',
        ]);

        $this->assertDatabaseCount('church_memberships', 2);

        // Attempting to add a second ACTIVE PRIMARY membership must fail unique constraint
        $church3 = DB::table('churches')->insertGetId(['locality_id' => $locId, 'name' => 'Methodist Church', 'slug' => 'methodist']);

        $this->expectException(\Illuminate\Database\QueryException::class);
        DB::table('church_memberships')->insert([
            'user_id' => $userId,
            'church_id' => $church3,
            'is_primary' => true,
            'status' => 'active',
        ]);
    }

    /**
     * Test 5: Rebuilding user XP ledger matches profile balance.
     */
    public function test_rebuilding_user_xp_matches_profile_balance(): void
    {
        $userId = DB::table('users')->insertGetId([
            'name' => 'Audit Pilgrim',
            'email' => 'audit@charyel.com',
            'password' => 'secret',
        ]);

        DB::table('user_profiles')->insert([
            'user_id' => $userId,
            'pilgrim_name' => 'AuditSeeker',
            'total_xp' => 350,
        ]);

        DB::table('user_xp_ledger')->insert([
            ['user_id' => $userId, 'guest_identity_id' => null, 'reward_source' => 'quiz_completion', 'source_id' => 's1', 'event_id' => (string) Str::uuid7(), 'xp_amount' => 150, 'awarded_at' => now()],
            ['user_id' => $userId, 'guest_identity_id' => null, 'reward_source' => 'quiz_completion', 'source_id' => 's2', 'event_id' => (string) Str::uuid7(), 'xp_amount' => 200, 'awarded_at' => now()],
        ]);

        $calculatedTotal = (int) DB::table('user_xp_ledger')->where('user_id', $userId)->sum('xp_amount');
        $storedTotal = (int) DB::table('user_profiles')->where('user_id', $userId)->value('total_xp');

        $this->assertEquals(350, $calculatedTotal);
        $this->assertEquals($calculatedTotal, $storedTotal);
    }

    /**
     * Test 6: All 8 application migrations can rollback cleanly without constraint errors.
     */
    public function test_all_migrations_can_rollback_cleanly(): void
    {
        \Tests\Support\DatabaseSafetyGuard::assertDestructiveOperationsAllowed();
        $exitCode = \Illuminate\Support\Facades\Artisan::call('migrate:rollback', ['--step' => 10]);
        $this->assertEquals(0, $exitCode);

        // Verify tables are dropped
        $this->assertFalse(DB::getSchemaBuilder()->hasTable('personal_access_tokens'));
        $this->assertFalse(DB::getSchemaBuilder()->hasTable('user_daily_competitive_counters'));
        $this->assertFalse(DB::getSchemaBuilder()->hasTable('leaderboard_finalized_podiums'));
        $this->assertFalse(DB::getSchemaBuilder()->hasTable('leaderboard_weekly_rollups'));
        $this->assertFalse(DB::getSchemaBuilder()->hasTable('league_periods'));
        $this->assertFalse(DB::getSchemaBuilder()->hasTable('processed_events'));
        $this->assertFalse(DB::getSchemaBuilder()->hasTable('user_xp_ledger'));
        $this->assertFalse(DB::getSchemaBuilder()->hasTable('quiz_session_items'));
        $this->assertFalse(DB::getSchemaBuilder()->hasTable('quiz_sessions'));
        $this->assertFalse(DB::getSchemaBuilder()->hasTable('question_changes'));
        $this->assertFalse(DB::getSchemaBuilder()->hasTable('question_revisions'));
        $this->assertFalse(DB::getSchemaBuilder()->hasTable('questions'));
        $this->assertFalse(DB::getSchemaBuilder()->hasTable('publication_sequences'));
        $this->assertFalse(DB::getSchemaBuilder()->hasTable('church_memberships'));
        $this->assertFalse(DB::getSchemaBuilder()->hasTable('user_profiles'));
        $this->assertFalse(DB::getSchemaBuilder()->hasTable('guest_identities'));
        $this->assertFalse(DB::getSchemaBuilder()->hasTable('churches'));
        $this->assertFalse(DB::getSchemaBuilder()->hasTable('localities'));
        $this->assertFalse(DB::getSchemaBuilder()->hasTable('regions'));
        $this->assertFalse(DB::getSchemaBuilder()->hasTable('countries'));

        // Baseline tables still intact
        $this->assertTrue(DB::getSchemaBuilder()->hasTable('users'));
    }
}
