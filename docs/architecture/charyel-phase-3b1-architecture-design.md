# Charyel â€” Phase 3B.1: Database Schema & Offline Synchronization Architecture

**Author:** Antigravity Engineering
**Date:** 2026-10-16
**Version:** 3.1.0-CONSISTENT
**Target Environment:** Oracle MySQL 9.7.2 LTS, Laravel 13 (PHP 8.5), React 19 PWA (IndexedDB / SQLite)
**Status:** Under Architectural Review (Design only â€” no DB migrations or live code changes executed)

---

## 1. Revision 3.1 Consistency Patch Summary

This revision provides surgical corrections to the database schema, accounting rules, and transaction sequences, resolving all three blockers identified in the Revision 3 audit:

| Blocker | Defect in Rev 3 | Correction in Rev 3.1 |
|---|---|---|
| **A. Question Publication FK Order** | `question_revisions` inserted before parent `questions` row, causing FK constraint violation on new questions. | Corrected transaction order: parent `questions` row is created/locked first, next version derived server-side, revision inserted, `current_revision_id` linked, and publication sequence committed. |
| **B. Guest XP Accounting & Uniqueness** | `user_xp_ledger.user_id` was `NOT NULL` (impossible for anonymous guests), and guest claims mutated historical rows. | Introduced a **Unified Scoring Principal** with generated stored column `principal_key` (`U:<user_id>` or `G:<guest_id>`) for strict exactly-once reward uniqueness. Guest claiming uses an immutable `guest_claim_transfer` transaction. |
| **C. XP Balance Clamping vs Rebuild** | `GREATEST(0, total_xp + adjustment)` produced inconsistent totals if an overdrawn reversal occurred, breaking deterministic ledger rebuilds. | Established strict accounting invariant: **Overdrawn reversals are rejected at transaction validation time**. Stored balance `user_profiles.total_xp` is strictly, mathematically equal to `SUM(user_xp_ledger.xp_amount)`. |
| **Clarification 1** | Primary church uniqueness didn't account for inactive memberships; default `is_primary = TRUE` broke multiple joins. | Generated column refined to: `active_primary_user_id = CASE WHEN is_primary = TRUE AND status = 'active' THEN user_id ELSE NULL END`. Default `is_primary` changed to `FALSE`. |
| **Clarification 2** | Weekly leaderboard hoarding risk from delayed offline sync. | Established **Fair Play Daily Cap (400 XP/day)** for offline submissions contributing to weekly competitive leaderboards; excess offline XP credits 100% to Lifetime XP. Uncapped competitive clashes require live server-issued challenge tokens. |

---

## 2. Corrected Transaction Sequences

### 2.1 Blocker A: Serialized Question Publication Transaction
To satisfy foreign key constraints and guarantee gap-free publication sequences:

```php
DB::transaction(function () use ($questionId, $questionData, $operation) {
    // Step 1: Acquire exclusive lock on the publication sequence counter
    $seqRow = DB::table('publication_sequences')->where('id', 1)->lockForUpdate()->first();
    $nextSeq = $seqRow->current_sequence + 1;

    // Step 2: Create or lock parent question row to establish FK parent & derive version
    $existing = DB::table('questions')->where('id', $questionId)->lockForUpdate()->first();

    if (!$existing) {
        $nextVersion = 1;
        DB::table('questions')->insert([
            'id' => $questionId,
            'testament' => $questionData['testament'],
            'book' => $questionData['book'],
            'chapter' => $questionData['chapter'] ?? null,
            'verse' => $questionData['verse'] ?? null,
            'scripture_reference' => $questionData['scripture_reference'],
            'scripture_version' => $questionData['scripture_version'] ?? 'KJV',
            'category' => $questionData['category'],
            'difficulty' => $questionData['difficulty'],
            'version' => $nextVersion,
            'current_revision_id' => null, // Temporarily null, updated in Step 4
            'is_published' => ($operation !== 'withdrawn'),
            'is_withdrawn' => ($operation === 'withdrawn'),
            'deleted_at' => ($operation === 'withdrawn') ? now() : null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    } else {
        $nextVersion = $existing->version + 1;
    }

    // Step 3: Insert revision (Parent question is guaranteed to exist!)
    $revisionId = DB::table('question_revisions')->insertGetId([
        'question_id' => $questionId,
        'version' => $nextVersion,
        'question_text' => $questionData['question_text'],
        'options' => json_encode($questionData['options']),
        'correct_option_id' => $questionData['correct_option_id'],
        'explanation' => $questionData['explanation'] ?? null,
        'change_summary' => $questionData['change_summary'] ?? null,
        'created_by_user_id' => auth()->id(),
        'created_at' => now(),
    ]);

    // Step 4: Update canonical parent question with revision link
    DB::table('questions')->where('id', $questionId)->update([
        'version' => $nextVersion,
        'current_revision_id' => $revisionId,
        'is_published' => ($operation !== 'withdrawn'),
        'is_withdrawn' => ($operation === 'withdrawn'),
        'deleted_at' => ($operation === 'withdrawn') ? now() : null,
        'updated_at' => now(),
    ]);

    // Step 5: Append to question_changes with contiguous sequence
    DB::table('question_changes')->insert([
        'sequence_id' => $nextSeq,
        'question_id' => $questionId,
        'revision_id' => $revisionId,
        'operation' => $operation,
        'published_at' => now(),
        'published_by_user_id' => auth()->id(),
    ]);

    // Step 6: Advance sequence counter
    DB::table('publication_sequences')->where('id', 1)->update([
        'current_sequence' => $nextSeq,
        'last_published_at' => now(),
    ]);
});
```

---

### 2.2 Blocker B: Unified Scoring Principal & Immutable Guest Claiming
To enable anonymous guests to earn XP while keeping the ledger 100% immutable:

```sql
-- Generated principal identifier for unified exactly-once uniqueness:
principal_key VARCHAR(64) GENERATED ALWAYS AS (
    CASE
        WHEN user_id IS NOT NULL THEN CONCAT('U:', user_id)
        WHEN guest_identity_id IS NOT NULL THEN CONCAT('G:', guest_identity_id)
        ELSE NULL
    END
) STORED,
UNIQUE KEY uq_principal_source (principal_key, reward_source, source_id)
```

#### Replay-Resistant, Immutable Guest Claiming Sequence (`POST /api/v1/auth/claim-guest`):
```php
DB::transaction(function () use ($guestUuid, $guestSecret, $registeredUserId) {
    // 1. Lock guest identity and verify proof of possession
    $guest = DB::table('guest_identities')
        ->where('guest_uuid', $guestUuid)
        ->lockForUpdate()
        ->first();

    if (!$guest) {
        throw new ModelNotFoundException('Guest identity not found.');
    }

    if ($guest->claimed_at !== null) {
        throw new ConflictException('Guest identity has already been claimed.');
    }

    if (!Hash::check($guestSecret, $guest->secret_hash)) {
        throw new AuthenticationException('Invalid guest secret proof of possession.');
    }

    // 2. Calculate total XP earned by guest
    $guestXp = (int) DB::table('user_xp_ledger')
        ->where('guest_identity_id', $guest->id)
        ->sum('xp_amount');

    // 3. Mark guest identity as claimed
    DB::table('guest_identities')->where('id', $guest->id)->update([
        'claimed_by_user_id' => $registeredUserId,
        'claimed_at' => now(),
    ]);

    // 4. Link historical sessions to the registered user
    DB::table('quiz_sessions')
        ->where('guest_identity_id', $guest->id)
        ->whereNull('user_id')
        ->update(['user_id' => $registeredUserId]);

    // 5. Append an immutable transfer record to user_xp_ledger (Zero mutation of past rows!)
    if ($guestXp > 0) {
        DB::table('user_xp_ledger')->insert([
            'user_id' => $registeredUserId,
            'guest_identity_id' => $guest->id,
            'reward_source' => 'guest_claim_transfer',
            'source_id' => 'claim:' . $guestUuid,
            'event_id' => (string) Str::uuid7(),
            'xp_amount' => $guestXp,
            'awarded_at' => now(),
        ]);

        // 6. Atomically increment registered user's profile balance
        DB::table('user_profiles')->where('user_id', $registeredUserId)->increment('total_xp', $guestXp);
    }
});
```

---

### 2.3 Blocker C: XP Reconciliation & Underflow Invariant
To ensure `user_profiles.total_xp` is strictly identical to `SUM(user_xp_ledger.xp_amount)` under all circumstances:

```php
public function applyXpAdjustment(int $userId, int $amount, string $source, string $sourceId, string $eventId): void
{
    DB::transaction(function () use ($userId, $amount, $source, $sourceId, $eventId) {
        // 1. Lock user profile row to check balance
        $profile = DB::table('user_profiles')->where('user_id', $userId)->lockForUpdate()->first();

        if (!$profile) {
            throw new ModelNotFoundException('User profile not found.');
        }

        $currentBalance = (int) $profile->total_xp;

        // Strict Invariant: Reversals cannot overdraw balance below zero
        if ($amount < 0 && ($currentBalance + $amount) < 0) {
            throw new InsufficientXpBalanceException(
                "Cannot apply reversal of {$amount} XP: current balance is {$currentBalance} XP."
            );
        }

        // 2. Append immutable record to ledger
        DB::table('user_xp_ledger')->insert([
            'user_id' => $userId,
            'guest_identity_id' => null,
            'reward_source' => $source,
            'source_id' => $sourceId,
            'event_id' => $eventId,
            'xp_amount' => $amount,
            'awarded_at' => now(),
        ]);

        // 3. Update projected balance
        DB::table('user_profiles')->where('user_id', $userId)->update([
            'total_xp' => $currentBalance + $amount,
            'updated_at' => now(),
        ]);
    });
}
```

---

## 3. Corrected MySQL 9.7.2 Database Schema Specification

All tables use `ENGINE=InnoDB`, `DEFAULT CHARSET=utf8mb4`, `COLLATE=utf8mb4_0900_ai_ci`.

```sql
-- =============================================================================
-- 1. GEOGRAPHY & COMMUNITY HIERARCHY
-- =============================================================================

CREATE TABLE countries (
    code CHAR(2) NOT NULL PRIMARY KEY,            -- ISO 3166-1 alpha-2 ('NG', 'US')
    name VARCHAR(100) NOT NULL,
    currency_code CHAR(3) DEFAULT 'USD',
    is_active BOOLEAN NOT NULL DEFAULT TRUE,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE regions (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    country_code CHAR(2) NOT NULL,
    name VARCHAR(100) NOT NULL,
    code VARCHAR(20) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_regions_country FOREIGN KEY (country_code) REFERENCES countries(code) ON DELETE RESTRICT,
    UNIQUE KEY uq_region_country_name (country_code, name)
) ENGINE=InnoDB;

CREATE TABLE localities (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    region_id INT UNSIGNED NOT NULL,
    name VARCHAR(100) NOT NULL,
    slug VARCHAR(120) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_localities_region FOREIGN KEY (region_id) REFERENCES regions(id) ON DELETE RESTRICT,
    UNIQUE KEY uq_locality_region_slug (region_id, slug),
    INDEX idx_locality_name (name)
) ENGINE=InnoDB;

CREATE TABLE churches (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    locality_id INT UNSIGNED NOT NULL,
    name VARCHAR(150) NOT NULL,
    slug VARCHAR(170) NOT NULL,
    denomination VARCHAR(100) NULL,
    is_verified BOOLEAN NOT NULL DEFAULT FALSE,
    created_by_user_id BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_churches_locality FOREIGN KEY (locality_id) REFERENCES localities(id) ON DELETE RESTRICT,
    CONSTRAINT fk_churches_creator FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON DELETE SET NULL,
    UNIQUE KEY uq_churches_locality_slug (locality_id, slug),
    INDEX idx_churches_verified (is_verified)
) ENGINE=InnoDB;

-- =============================================================================
-- 2. GUEST IDENTITIES, PROFILES & CHURCH MEMBERSHIPS
-- =============================================================================

CREATE TABLE guest_identities (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    guest_uuid VARCHAR(36) NOT NULL UNIQUE,       -- Client UUIDv7
    secret_hash VARCHAR(255) NOT NULL,            -- bcrypt/Argon2id of 256-bit secret
    claimed_by_user_id BIGINT UNSIGNED NULL,
    claimed_at TIMESTAMP NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_guest_claimed_user FOREIGN KEY (claimed_by_user_id) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_guest_claim_status (claimed_at)
) ENGINE=InnoDB;

CREATE TABLE user_profiles (
    user_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
    locality_id INT UNSIGNED NULL,
    primary_church_id BIGINT UNSIGNED NULL,
    pilgrim_name VARCHAR(50) NOT NULL,
    avatar_seed VARCHAR(64) NOT NULL DEFAULT 'default',
    total_xp BIGINT UNSIGNED NOT NULL DEFAULT 0,
    current_streak INT UNSIGNED NOT NULL DEFAULT 0,
    longest_streak INT UNSIGNED NOT NULL DEFAULT 0,
    is_leaderboard_public BOOLEAN NOT NULL DEFAULT FALSE, -- Opt-in by default
    show_church_affiliation BOOLEAN NOT NULL DEFAULT FALSE, -- Opt-in by default
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_profiles_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_profiles_locality FOREIGN KEY (locality_id) REFERENCES localities(id) ON DELETE SET NULL,
    CONSTRAINT fk_profiles_church FOREIGN KEY (primary_church_id) REFERENCES churches(id) ON DELETE SET NULL,
    INDEX idx_profiles_locality_xp (locality_id, total_xp DESC),
    INDEX idx_profiles_church_xp (primary_church_id, total_xp DESC)
) ENGINE=InnoDB;

CREATE TABLE church_memberships (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    church_id BIGINT UNSIGNED NOT NULL,
    role ENUM('pilgrim', 'youth_leader', 'coordinator', 'pastor') NOT NULL DEFAULT 'pilgrim',
    is_primary BOOLEAN NOT NULL DEFAULT FALSE,    -- Default false for secondary joins
    status ENUM('active', 'pending', 'inactive') NOT NULL DEFAULT 'active',
    joined_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

    -- Virtual generated column enforcing at most one ACTIVE primary church membership
    active_primary_user_id BIGINT UNSIGNED GENERATED ALWAYS AS (
        CASE WHEN is_primary = TRUE AND status = 'active' THEN user_id ELSE NULL END
    ) STORED,

    CONSTRAINT fk_memberships_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_memberships_church FOREIGN KEY (church_id) REFERENCES churches(id) ON DELETE CASCADE,
    UNIQUE KEY uq_membership_user_church (user_id, church_id),
    UNIQUE KEY uq_user_single_active_primary (active_primary_user_id)
) ENGINE=InnoDB;

-- =============================================================================
-- 3. BIBLE QUESTION BANK, VERSIONING & SERIALIZED PUBLICATION
-- =============================================================================

CREATE TABLE publication_sequences (
    id INT UNSIGNED NOT NULL PRIMARY KEY DEFAULT 1,
    current_sequence BIGINT UNSIGNED NOT NULL DEFAULT 0,
    last_published_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE questions (
    id VARCHAR(36) NOT NULL PRIMARY KEY,          -- UUIDv7
    testament ENUM('OT', 'NT', 'BOTH') NOT NULL,
    book VARCHAR(50) NOT NULL,
    chapter SMALLINT UNSIGNED NULL,
    verse SMALLINT UNSIGNED NULL,
    scripture_reference VARCHAR(100) NOT NULL,
    scripture_version VARCHAR(20) NOT NULL DEFAULT 'KJV', -- KJV, WEB
    category VARCHAR(50) NOT NULL,
    difficulty ENUM('easy', 'medium', 'hard') NOT NULL DEFAULT 'medium',
    version INT UNSIGNED NOT NULL DEFAULT 1,
    current_revision_id BIGINT UNSIGNED NULL,
    is_published BOOLEAN NOT NULL DEFAULT TRUE,
    is_withdrawn BOOLEAN NOT NULL DEFAULT FALSE,
    deleted_at TIMESTAMP NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_questions_diff_cat (difficulty, category),
    INDEX idx_questions_testament (testament, book)
) ENGINE=InnoDB;

CREATE TABLE question_revisions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    question_id VARCHAR(36) NOT NULL,
    version INT UNSIGNED NOT NULL,
    question_text TEXT NOT NULL,
    options JSON NOT NULL,                        -- Array of options [{"id": "opt_a", "text": "..."}]
    correct_option_id VARCHAR(10) NOT NULL,       -- Authoritative answer key
    explanation TEXT NULL,
    change_summary VARCHAR(255) NULL,
    created_by_user_id BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_revisions_question FOREIGN KEY (question_id) REFERENCES questions(id) ON DELETE RESTRICT,
    CONSTRAINT fk_revisions_author FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON DELETE SET NULL,
    UNIQUE KEY uq_question_version (question_id, version)
) ENGINE=InnoDB;

CREATE TABLE question_changes (
    sequence_id BIGINT UNSIGNED NOT NULL PRIMARY KEY, -- Strictly contiguous sequence
    question_id VARCHAR(36) NOT NULL,
    revision_id BIGINT UNSIGNED NOT NULL,
    operation ENUM('created', 'updated', 'withdrawn', 'restored') NOT NULL,
    published_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    published_by_user_id BIGINT UNSIGNED NULL,
    CONSTRAINT fk_changes_question FOREIGN KEY (question_id) REFERENCES questions(id) ON DELETE RESTRICT,
    CONSTRAINT fk_changes_revision FOREIGN KEY (revision_id) REFERENCES question_revisions(id) ON DELETE RESTRICT,
    INDEX idx_changes_sequence (sequence_id, published_at)
) ENGINE=InnoDB;

-- =============================================================================
-- 4. QUIZ SESSIONS, AUDIT ITEMS & UNIFIED XP LEDGER
-- =============================================================================

CREATE TABLE quiz_sessions (
    id VARCHAR(36) NOT NULL PRIMARY KEY,          -- Client UUIDv7
    user_id BIGINT UNSIGNED NULL,                 -- Nullable for guest sessions
    guest_identity_id BIGINT UNSIGNED NULL,
    church_id BIGINT UNSIGNED NULL,               -- Historical snapshot of primary church
    device_id VARCHAR(64) NOT NULL,
    quiz_mode ENUM('standard', 'blitz', 'church_clash', 'marathon') NOT NULL DEFAULT 'standard',
    category VARCHAR(50) NULL,
    difficulty ENUM('easy', 'medium', 'hard', 'mixed') NOT NULL DEFAULT 'mixed',
    total_questions SMALLINT UNSIGNED NOT NULL,
    correct_answers SMALLINT UNSIGNED NOT NULL,
    claimed_score INT UNSIGNED NOT NULL,
    verified_score INT UNSIGNED NOT NULL,
    xp_earned INT UNSIGNED NOT NULL,
    duration_seconds INT UNSIGNED NOT NULL,
    completed_at TIMESTAMP NOT NULL,              -- Client-reported completion time
    synced_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    verification_status ENUM('verified', 'flagged_suspicious', 'rejected') NOT NULL DEFAULT 'verified',
    rejection_reason VARCHAR(255) NULL,
    CONSTRAINT fk_sessions_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_sessions_guest FOREIGN KEY (guest_identity_id) REFERENCES guest_identities(id) ON DELETE SET NULL,
    CONSTRAINT fk_sessions_church FOREIGN KEY (church_id) REFERENCES churches(id) ON DELETE SET NULL,
    INDEX idx_sessions_user_completed (user_id, completed_at DESC),
    INDEX idx_sessions_guest_completed (guest_identity_id, completed_at DESC),
    INDEX idx_sessions_device (device_id, completed_at DESC),
    INDEX idx_sessions_status (verification_status)
) ENGINE=InnoDB;

CREATE TABLE quiz_session_items (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    session_id VARCHAR(36) NOT NULL,
    question_id VARCHAR(36) NOT NULL,
    question_version INT UNSIGNED NOT NULL DEFAULT 1,
    selected_option_id VARCHAR(10) NOT NULL,
    is_correct BOOLEAN NOT NULL,
    time_taken_ms INT UNSIGNED NOT NULL,
    CONSTRAINT fk_items_session FOREIGN KEY (session_id) REFERENCES quiz_sessions(id) ON DELETE CASCADE,
    CONSTRAINT fk_items_question FOREIGN KEY (question_id) REFERENCES questions(id) ON DELETE RESTRICT,
    INDEX idx_items_session (session_id)
) ENGINE=InnoDB;

CREATE TABLE user_xp_ledger (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NULL,
    guest_identity_id BIGINT UNSIGNED NULL,
    reward_source ENUM('quiz_completion', 'daily_streak', 'church_bonus', 'guest_claim_transfer', 'late_sync_rollover', 'reversal') NOT NULL,
    source_id VARCHAR(64) NOT NULL,               -- e.g. session_id, 'streak:YYYY-MM-DD', 'claim:uuid'
    event_id VARCHAR(36) NOT NULL,                -- Client UUIDv7
    xp_amount INT NOT NULL,                       -- Signed integer (awards & reversals)
    awarded_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

    -- Unified principal identifier for exactly-once constraint
    principal_key VARCHAR(64) GENERATED ALWAYS AS (
        CASE
            WHEN user_id IS NOT NULL THEN CONCAT('U:', user_id)
            WHEN guest_identity_id IS NOT NULL THEN CONCAT('G:', guest_identity_id)
            ELSE NULL
        END
    ) STORED,

    CONSTRAINT fk_ledger_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_ledger_guest FOREIGN KEY (guest_identity_id) REFERENCES guest_identities(id) ON DELETE SET NULL,
    CONSTRAINT chk_ledger_owner CHECK (user_id IS NOT NULL OR guest_identity_id IS NOT NULL),
    UNIQUE KEY uq_principal_source (principal_key, reward_source, source_id),
    INDEX idx_ledger_user_date (user_id, awarded_at DESC),
    INDEX idx_ledger_guest_date (guest_identity_id, awarded_at DESC),
    INDEX idx_ledger_event (event_id)
) ENGINE=InnoDB;

-- =============================================================================
-- 5. IDEMPOTENT EVENT RECONCILIATION
-- =============================================================================

CREATE TABLE processed_events (
    event_id VARCHAR(36) NOT NULL PRIMARY KEY,    -- Client UUIDv7
    session_id VARCHAR(36) NULL,                  -- 1:1 binding with quiz_sessions
    device_id VARCHAR(64) NOT NULL,
    user_id BIGINT UNSIGNED NULL,
    guest_identity_id BIGINT UNSIGNED NULL,
    event_type VARCHAR(50) NOT NULL,
    payload_hash CHAR(64) NOT NULL,               -- SHA-256 of canonical payload
    status ENUM('accepted', 'rejected') NOT NULL,
    http_status_code SMALLINT UNSIGNED NOT NULL DEFAULT 200,
    response_payload JSON NOT NULL,               -- Immutable original acknowledgment response
    processed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_event_session (session_id),
    CONSTRAINT fk_events_guest FOREIGN KEY (guest_identity_id) REFERENCES guest_identities(id) ON DELETE SET NULL,
    INDEX idx_events_device (device_id, processed_at),
    INDEX idx_events_user (user_id, processed_at)
) ENGINE=InnoDB;

-- =============================================================================
-- 6. LEAGUE PERIODS, ROLL-UPS & FINALIZED PODIUMS
-- =============================================================================

CREATE TABLE league_periods (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    year_week INT UNSIGNED NOT NULL UNIQUE KEY,   -- e.g. 202642
    starts_at TIMESTAMP NOT NULL,                 -- Monday 00:00:00 UTC
    ends_at TIMESTAMP NOT NULL,                   -- Next Monday 00:00:00 UTC
    grace_ends_at TIMESTAMP NOT NULL,             -- Wednesday 00:00:00 UTC (48h grace)
    status ENUM('active', 'grace_period', 'finalized') NOT NULL DEFAULT 'active',
    INDEX idx_periods_status (status)
) ENGINE=InnoDB;

CREATE TABLE leaderboard_weekly_rollups (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    year_week INT UNSIGNED NOT NULL,
    scope ENUM('global', 'country', 'region', 'locality', 'church') NOT NULL,
    scope_id VARCHAR(64) NOT NULL DEFAULT 'global',
    user_id BIGINT UNSIGNED NOT NULL,
    pilgrim_name VARCHAR(50) NOT NULL,
    church_name VARCHAR(150) NULL,
    avatar_seed VARCHAR(64) NOT NULL,
    weekly_xp INT UNSIGNED NOT NULL DEFAULT 0,
    rank_position INT UNSIGNED NOT NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_rollups_period FOREIGN KEY (year_week) REFERENCES league_periods(year_week) ON DELETE CASCADE,
    UNIQUE KEY uq_rollup_entry (year_week, scope, scope_id, user_id),
    INDEX idx_rollup_lookup (year_week, scope, scope_id, rank_position)
) ENGINE=InnoDB;

CREATE TABLE leaderboard_finalized_podiums (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    year_week INT UNSIGNED NOT NULL,
    scope ENUM('global', 'country', 'region', 'locality', 'church') NOT NULL,
    scope_id VARCHAR(64) NOT NULL,
    rank_position INT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    church_id BIGINT UNSIGNED NULL,
    pilgrim_name VARCHAR(50) NOT NULL,
    church_name VARCHAR(150) NULL,
    final_xp INT UNSIGNED NOT NULL,
    podium_tier ENUM('gold', 'silver', 'bronze', 'top_10', 'participant') NOT NULL,
    finalized_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_podiums_period FOREIGN KEY (year_week) REFERENCES league_periods(year_week) ON DELETE CASCADE,
    CONSTRAINT fk_podiums_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE KEY uq_podium_rank (year_week, scope, scope_id, rank_position)
) ENGINE=InnoDB;
```

---

## 4. Reconciled Laravel 13 Migration Blueprint

The complete migration sequence comprises the 3 existing Laravel baseline migrations plus **8 application domain migrations** (11 migrations total):

```php
// 1. 2026_10_16_000001_create_geographic_hierarchy_tables.php
Schema::create('countries', function (Blueprint $table) {
    $table->char('code', 2)->primary();
    $table->string('name', 100);
    $table->char('currency_code', 3)->default('USD');
    $table->boolean('is_active')->default(true);
    $table->timestamp('created_at')->useCurrent();
});

Schema::create('regions', function (Blueprint $table) {
    $table->increments('id');
    $table->char('country_code', 2);
    $table->string('name', 100);
    $table->string('code', 20)->nullable();
    $table->timestamp('created_at')->useCurrent();
    $table->foreign('country_code')->references('code')->on('countries')->restrictOnDelete();
    $table->unique(['country_code', 'name']);
});

Schema::create('localities', function (Blueprint $table) {
    $table->increments('id');
    $table->unsignedInteger('region_id');
    $table->string('name', 100);
    $table->string('slug', 120);
    $table->timestamp('created_at')->useCurrent();
    $table->foreign('region_id')->references('id')->on('regions')->restrictOnDelete();
    $table->unique(['region_id', 'slug']);
    $table->index('name');
});

// 2. 2026_10_16_000002_create_churches_table.php
Schema::create('churches', function (Blueprint $table) {
    $table->bigIncrements('id');
    $table->unsignedInteger('locality_id');
    $table->string('name', 150);
    $table->string('slug', 170);
    $table->string('denomination', 100)->nullable();
    $table->boolean('is_verified')->default(false);
    $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
    $table->timestamps();
    $table->foreign('locality_id')->references('id')->on('localities')->restrictOnDelete();
    $table->unique(['locality_id', 'slug']);
    $table->index('is_verified');
});

// 3. 2026_10_16_000003_create_guest_identities_table.php
Schema::create('guest_identities', function (Blueprint $table) {
    $table->bigIncrements('id');
    $table->uuid('guest_uuid')->unique();
    $table->string('secret_hash', 255);
    $table->foreignId('claimed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
    $table->timestamp('claimed_at')->nullable();
    $table->timestamp('created_at')->useCurrent();
    $table->index('claimed_at');
});

// 4. 2026_10_16_000004_create_user_profiles_and_memberships.php
Schema::create('user_profiles', function (Blueprint $table) {
    $table->foreignId('user_id')->primary()->constrained('users')->cascadeOnDelete();
    $table->unsignedInteger('locality_id')->nullable();
    $table->foreignId('primary_church_id')->nullable()->constrained('churches')->nullOnDelete();
    $table->string('pilgrim_name', 50);
    $table->string('avatar_seed', 64)->default('default');
    $table->unsignedBigInteger('total_xp')->default(0);
    $table->unsignedInteger('current_streak')->default(0);
    $table->unsignedInteger('longest_streak')->default(0);
    $table->boolean('is_leaderboard_public')->default(false); // Opt-in
    $table->boolean('show_church_affiliation')->default(false); // Opt-in
    $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate();
    $table->foreign('locality_id')->references('id')->on('localities')->nullOnDelete();
    $table->index(['locality_id', 'total_xp']);
    $table->index(['primary_church_id', 'total_xp']);
});

Schema::create('church_memberships', function (Blueprint $table) {
    $table->bigIncrements('id');
    $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
    $table->foreignId('church_id')->constrained('churches')->cascadeOnDelete();
    $table->enum('role', ['pilgrim', 'youth_leader', 'coordinator', 'pastor'])->default('pilgrim');
    $table->boolean('is_primary')->default(false);
    $table->enum('status', ['active', 'pending', 'inactive'])->default('active');
    $table->timestamp('joined_at')->useCurrent();

    // MySQL 9.7 stored virtual column for active primary conditional uniqueness
    $table->unsignedBigInteger('active_primary_user_id')
        ->storedAs("CASE WHEN is_primary = TRUE AND status = 'active' THEN user_id ELSE NULL END");

    $table->unique(['user_id', 'church_id']);
    $table->unique('active_primary_user_id');
});

// 5. 2026_10_16_000005_create_question_bank_and_publication.php
Schema::create('publication_sequences', function (Blueprint $table) {
    $table->unsignedInteger('id')->default(1)->primary();
    $table->unsignedBigInteger('current_sequence')->default(0);
    $table->timestamp('last_published_at')->useCurrent()->useCurrentOnUpdate();
});

Schema::create('questions', function (Blueprint $table) {
    $table->uuid('id')->primary();
    $table->enum('testament', ['OT', 'NT', 'BOTH']);
    $table->string('book', 50);
    $table->unsignedSmallInteger('chapter')->nullable();
    $table->unsignedSmallInteger('verse')->nullable();
    $table->string('scripture_reference', 100);
    $table->string('scripture_version', 20)->default('KJV');
    $table->string('category', 50);
    $table->enum('difficulty', ['easy', 'medium', 'hard'])->default('medium');
    $table->unsignedInteger('version')->default(1);
    $table->unsignedBigInteger('current_revision_id')->nullable();
    $table->boolean('is_published')->default(true);
    $table->boolean('is_withdrawn')->default(false);
    $table->softDeletes();
    $table->timestamps();
    $table->index(['difficulty', 'category']);
    $table->index(['testament', 'book']);
});

Schema::create('question_revisions', function (Blueprint $table) {
    $table->bigIncrements('id');
    $table->uuid('question_id');
    $table->unsignedInteger('version');
    $table->text('question_text');
    $table->json('options');
    $table->string('correct_option_id', 10);
    $table->text('explanation')->nullable();
    $table->string('change_summary', 255)->nullable();
    $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
    $table->timestamp('created_at')->useCurrent();
    $table->foreign('question_id')->references('id')->on('questions')->restrictOnDelete();
    $table->unique(['question_id', 'version']);
});

Schema::create('question_changes', function (Blueprint $table) {
    $table->unsignedBigInteger('sequence_id')->primary();
    $table->uuid('question_id');
    $table->unsignedBigInteger('revision_id');
    $table->enum('operation', ['created', 'updated', 'withdrawn', 'restored']);
    $table->timestamp('published_at')->useCurrent();
    $table->foreignId('published_by_user_id')->nullable()->constrained('users')->nullOnDelete();
    $table->foreign('question_id')->references('id')->on('questions')->restrictOnDelete();
    $table->foreign('revision_id')->references('id')->on('question_revisions')->restrictOnDelete();
    $table->index(['sequence_id', 'published_at']);
});

// 6. 2026_10_16_000006_create_quiz_sessions_and_ledger.php
Schema::create('quiz_sessions', function (Blueprint $table) {
    $table->uuid('id')->primary();
    $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
    $table->foreignId('guest_identity_id')->nullable()->constrained('guest_identities')->nullOnDelete();
    $table->foreignId('church_id')->nullable()->constrained('churches')->nullOnDelete();
    $table->string('device_id', 64);
    $table->enum('quiz_mode', ['standard', 'blitz', 'church_clash', 'marathon'])->default('standard');
    $table->string('category', 50)->nullable();
    $table->enum('difficulty', ['easy', 'medium', 'hard', 'mixed'])->default('mixed');
    $table->unsignedSmallInteger('total_questions');
    $table->unsignedSmallInteger('correct_answers');
    $table->unsignedInteger('claimed_score');
    $table->unsignedInteger('verified_score');
    $table->unsignedInteger('xp_earned');
    $table->unsignedInteger('duration_seconds');
    $table->timestamp('completed_at');
    $table->timestamp('synced_at')->useCurrent();
    $table->enum('verification_status', ['verified', 'flagged_suspicious', 'rejected'])->default('verified');
    $table->string('rejection_reason', 255)->nullable();
    $table->index(['user_id', 'completed_at']);
    $table->index(['guest_identity_id', 'completed_at']);
    $table->index(['device_id', 'completed_at']);
});

Schema::create('quiz_session_items', function (Blueprint $table) {
    $table->bigIncrements('id');
    $table->uuid('session_id');
    $table->uuid('question_id');
    $table->unsignedInteger('question_version')->default(1);
    $table->string('selected_option_id', 10);
    $table->boolean('is_correct');
    $table->unsignedInteger('time_taken_ms');
    $table->foreign('session_id')->references('id')->on('quiz_sessions')->cascadeOnDelete();
    $table->foreign('question_id')->references('id')->on('questions')->restrictOnDelete();
    $table->index('session_id');
});

Schema::create('user_xp_ledger', function (Blueprint $table) {
    $table->bigIncrements('id');
    $table->foreignId('user_id')->nullable()->constrained('users')->cascadeOnDelete();
    $table->foreignId('guest_identity_id')->nullable()->constrained('guest_identities')->nullOnDelete();
    $table->enum('reward_source', ['quiz_completion', 'daily_streak', 'church_bonus', 'guest_claim_transfer', 'late_sync_rollover', 'reversal']);
    $table->string('source_id', 64);
    $table->uuid('event_id');
    $table->integer('xp_amount');
    $table->timestamp('awarded_at')->useCurrent();

    $table->string('principal_key', 64)->storedAs(
        "CASE WHEN user_id IS NOT NULL THEN CONCAT('U:', user_id) WHEN guest_identity_id IS NOT NULL THEN CONCAT('G:', guest_identity_id) ELSE NULL END"
    );

    $table->unique(['principal_key', 'reward_source', 'source_id']);
    $table->index(['user_id', 'awarded_at']);
    $table->index(['guest_identity_id', 'awarded_at']);
    $table->index('event_id');
});

// 7. 2026_10_16_000007_create_processed_events_table.php
Schema::create('processed_events', function (Blueprint $table) {
    $table->uuid('event_id')->primary();
    $table->uuid('session_id')->nullable()->unique();
    $table->string('device_id', 64);
    $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
    $table->foreignId('guest_identity_id')->nullable()->constrained('guest_identities')->nullOnDelete();
    $table->string('event_type', 50);
    $table->char('payload_hash', 64);
    $table->enum('status', ['accepted', 'rejected']);
    $table->unsignedSmallInteger('http_status_code')->default(200);
    $table->json('response_payload');
    $table->timestamp('processed_at')->useCurrent();
    $table->index(['device_id', 'processed_at']);
    $table->index(['user_id', 'processed_at']);
});

// 8. 2026_10_16_000008_create_league_periods_and_leaderboards.php
Schema::create('league_periods', function (Blueprint $table) {
    $table->increments('id');
    $table->unsignedInteger('year_week')->unique();
    $table->timestamp('starts_at');
    $table->timestamp('ends_at');
    $table->timestamp('grace_ends_at');
    $table->enum('status', ['active', 'grace_period', 'finalized'])->default('active');
});

Schema::create('leaderboard_weekly_rollups', function (Blueprint $table) {
    $table->bigIncrements('id');
    $table->unsignedInteger('year_week');
    $table->enum('scope', ['global', 'country', 'region', 'locality', 'church']);
    $table->string('scope_id', 64)->default('global');
    $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
    $table->string('pilgrim_name', 50);
    $table->string('church_name', 150)->nullable();
    $table->string('avatar_seed', 64);
    $table->unsignedInteger('weekly_xp')->default(0);
    $table->unsignedInteger('rank_position');
    $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate();
    $table->foreign('year_week')->references('year_week')->on('league_periods')->cascadeOnDelete();
    $table->unique(['year_week', 'scope', 'scope_id', 'user_id']);
    $table->index(['year_week', 'scope', 'scope_id', 'rank_position']);
});

Schema::create('leaderboard_finalized_podiums', function (Blueprint $table) {
    $table->bigIncrements('id');
    $table->unsignedInteger('year_week');
    $table->enum('scope', ['global', 'country', 'region', 'locality', 'church']);
    $table->string('scope_id', 64);
    $table->unsignedInteger('rank_position');
    $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
    $table->unsignedBigInteger('church_id')->nullable();
    $table->string('pilgrim_name', 50);
    $table->string('church_name', 150)->nullable();
    $table->unsignedInteger('final_xp');
    $table->enum('podium_tier', ['gold', 'silver', 'bronze', 'top_10', 'participant']);
    $table->timestamp('finalized_at')->useCurrent();
    $table->foreign('year_week')->references('year_week')->on('league_periods')->cascadeOnDelete();
    $table->unique(['year_week', 'scope', 'scope_id', 'rank_position']);
});
```

---

## 5. Automated Concurrency & Consistency Test Scenarios

| Test Scenario | Implementation Check | Expected Assertion |
|---|---|---|
| **Blocker A: New Question FK Order** | Run `publishQuestion()` on a brand new UUID question ID. | Parent `questions` exists before revision insert. Migration FK `fk_revisions_question` succeeds without constraint error. Sequence increments contiguously. |
| **Blocker B: Anonymous Guest XP** | Submit quiz session with guest Sanctum token (`user_id = NULL`). | Row successfully inserted into `user_xp_ledger` with `principal_key = 'G:<guest_id>'`. Duplicate submission throws 1062 on `uq_principal_source`. |
| **Blocker B: Guest Claim Transfer** | User registers, calls `POST /api/v1/auth/claim-guest`. | Past guest rows remain unchanged. Exactly one `guest_claim_transfer` row inserted for registered user. Re-claiming same secret returns 409 Conflict. |
| **Blocker C: Overdrawn Reversal** | User balance is 50 XP; attempt reversal of -80 XP. | Throws `InsufficientXpBalanceException`. Transaction rolls back. Balance remains 50 XP. `rebuildXpLedger()` produces exactly 50 XP. |
| **Clarification 1: Active Primary Church** | User with active primary church is marked `status = 'inactive'`. User joins another church with `is_primary = TRUE`. | Second primary membership insert succeeds because generated column evaluates to `NULL` for the inactive row. |
| **Clarification 2: Fair Play Weekly Cap** | User syncs 5 offline quizzes worth 1,000 XP. | All 1,000 XP awards to Lifetime XP. Exactly 400 XP awards to Weekly Competitive Leaderboard; 600 XP logged as capped overflow. |
