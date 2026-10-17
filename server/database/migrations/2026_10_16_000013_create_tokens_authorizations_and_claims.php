<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // 1. Table: contact_verification_tokens (Contact OTP Challenges)
        Schema::create('contact_verification_tokens', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->uuid('challenge_id')->unique('uq_verification_challenge_id');
            $table->foreignId('guest_identity_id')->nullable()->constrained('guest_identities')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->cascadeOnDelete();
            $table->enum('contact_type', ['email', 'phone']);
            $table->string('contact_value', 255);
            $table->string('purpose', 50);
            $table->char('token_digest', 64);
            $table->string('key_id', 32);
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->unsignedTinyInteger('max_attempts')->default(5);
            $table->timestamp('expires_at');
            $table->timestamp('verified_at')->nullable();
            $table->timestamp('locked_at')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['contact_type', 'contact_value'], 'idx_tokens_contact');
            $table->index('expires_at', 'idx_tokens_expires_at');
        });

        // Enforce strict XOR ownership: challenge belongs to either a guest or a user
        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE contact_verification_tokens ADD CONSTRAINT chk_verification_owner CHECK ((guest_identity_id IS NOT NULL AND user_id IS NULL) OR (guest_identity_id IS NULL AND user_id IS NOT NULL))');
        }

        // 2. Table: offline_quiz_authorizations (Pre-authorized Offline Quiz Tickets)
        Schema::create('offline_quiz_authorizations', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->uuid('ticket_id')->unique('uq_offline_ticket_id');
            $table->foreignId('guest_identity_id')->constrained('guest_identities')->restrictOnDelete();
            $table->unsignedTinyInteger('slot_number');
            $table->unsignedInteger('generation')->default(1);
            $table->json('manifest_payload');
            $table->char('manifest_hash', 64);
            $table->string('signature', 128);
            $table->string('key_id', 32);
            $table->enum('status', ['issued', 'consumed', 'expired', 'revoked'])->default('issued');
            $table->uuid('consumed_session_id')->nullable();
            $table->timestamp('issued_at')->useCurrent();
            $table->timestamp('expires_at');
            $table->timestamp('consumed_at')->nullable();
            $table->timestamp('created_at')->useCurrent();

            // Generated column: active_slot evaluates to slot_number when status is 'issued', else NULL
            $table->unsignedTinyInteger('active_slot')
                ->nullable()
                ->storedAs("CASE WHEN status = 'issued' THEN slot_number ELSE NULL END");

            // Unique constraint on active slot: at most one 'issued' ticket per slot per guest
            $table->unique(['guest_identity_id', 'active_slot'], 'uq_guest_active_slot');

            // Unique constraint on generation: slot generations are monotonic and un-reused
            $table->unique(['guest_identity_id', 'slot_number', 'generation'], 'uq_guest_slot_gen');

            $table->index(['guest_identity_id', 'status'], 'idx_authorizations_guest_status');
            $table->index('expires_at', 'idx_authorizations_expires_at');
        });

        // Enforce valid slot number bounds (1, 2, or 3)
        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE offline_quiz_authorizations ADD CONSTRAINT chk_ticket_slot_bounds CHECK (slot_number >= 1 AND slot_number <= 3)');
        }

        // 3. Table: guest_identity_claims (Guest-to-Registered User Account Conversion)
        Schema::create('guest_identity_claims', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('guest_identity_id')->unique('uq_claims_guest_identity')->constrained('guest_identities')->restrictOnDelete();
            $table->unsignedInteger('gross_xp_transferred')->default(0);
            $table->unsignedSmallInteger('quizzes_transferred')->default(0);
            $table->unsignedInteger('total_absorbed_competitive_xp')->default(0);
            $table->unsignedInteger('total_unabsorbed_competitive_xp')->default(0);
            $table->timestamp('claimed_at')->useCurrent();

            $table->index('user_id', 'idx_claims_user_id');
        });

        // 4. Table: guest_claim_daily_attributions (Per-Day Competitive XP Attribution Records)
        Schema::create('guest_claim_daily_attributions', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('claim_id')->constrained('guest_identity_claims')->restrictOnDelete();
            $table->foreignId('guest_identity_id')->constrained('guest_identities')->restrictOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->date('activity_date');
            $table->string('source_id', 100);
            $table->unsignedSmallInteger('guest_competitive_xp');
            $table->unsignedSmallInteger('user_prior_competitive_xp');
            $table->unsignedSmallInteger('absorbed_competitive_xp');
            $table->unsignedSmallInteger('unabsorbed_competitive_xp');
            $table->boolean('is_league_eligible')->default(true);
            $table->uuid('event_id')->unique('uq_attribution_event_id');
            $table->timestamp('created_at')->useCurrent();

            // Stable source event uniqueness: guarantees exactly-once processing per source scoring event
            $table->unique(['guest_identity_id', 'source_id'], 'uq_attr_guest_source');

            // Non-unique composite index allows multiple independent events on the same activity date
            $table->index(['guest_identity_id', 'activity_date'], 'idx_attr_guest_date');
            $table->index(['claim_id', 'activity_date'], 'idx_attr_claim_date');
            $table->index(['user_id', 'activity_date'], 'idx_attr_user_date');
        });

        // Enforce accounting invariants on daily attributions:
        // - User total competitive XP for that day cannot exceed 400
        // - Guest competitive XP equals sum of absorbed and unabsorbed points
        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE guest_claim_daily_attributions ADD CONSTRAINT chk_absorbed_bounds CHECK (absorbed_competitive_xp + user_prior_competitive_xp <= 400)');
            DB::statement('ALTER TABLE guest_claim_daily_attributions ADD CONSTRAINT chk_attribution_math CHECK (guest_competitive_xp = absorbed_competitive_xp + unabsorbed_competitive_xp)');
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Drop in reverse dependency order
        Schema::dropIfExists('guest_claim_daily_attributions');
        Schema::dropIfExists('guest_identity_claims');
        Schema::dropIfExists('offline_quiz_authorizations');
        Schema::dropIfExists('contact_verification_tokens');
    }
};
