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
        // 1. Enforce strict XOR ownership constraint on user_daily_competitive_counters
        if (DB::getDriverName() === 'mysql') {
            // Drop previous OR-based constraint: chk_daily_counter_owner
            DB::statement('ALTER TABLE user_daily_competitive_counters DROP CONSTRAINT chk_daily_counter_owner');

            // Enforce strict XOR: exactly one of user_id or guest_identity_id must be non-null
            DB::statement('ALTER TABLE user_daily_competitive_counters ADD CONSTRAINT chk_counter_ownership_xor CHECK ((user_id IS NOT NULL AND guest_identity_id IS NULL) OR (user_id IS NULL AND guest_identity_id IS NOT NULL))');
        }

        // 2. Create competitive_xp_events table for typed competitive audit logging
        Schema::create('competitive_xp_events', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('principal_key', 64);
            $table->date('award_date');
            $table->enum('event_type', [
                'quiz_completion',
                'daily_streak',
                'church_bonus',
                'guest_claim_transfer',
                'guest_claim_absorption',
                'late_sync_rollover',
                'reversal',
            ]);
            $table->smallInteger('xp_delta'); // Signed small integer for awards and reversals
            $table->unsignedSmallInteger('counter_before');
            $table->unsignedSmallInteger('counter_after');
            $table->string('reference_id', 100);
            $table->uuid('event_id')->unique('uq_competitive_xp_event_id');
            $table->timestamp('created_at')->useCurrent();

            $table->index(['principal_key', 'award_date'], 'idx_audit_principal_date');
        });

        // Add CHECK constraints for counter bounds in MySQL
        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE competitive_xp_events ADD CONSTRAINT chk_event_counter_bounds CHECK (counter_before <= 400 AND counter_after <= 400)');
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('competitive_xp_events');

        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE user_daily_competitive_counters DROP CONSTRAINT chk_counter_ownership_xor');
            DB::statement('ALTER TABLE user_daily_competitive_counters ADD CONSTRAINT chk_daily_counter_owner CHECK (user_id IS NOT NULL OR guest_identity_id IS NOT NULL)');
        }
    }
};
