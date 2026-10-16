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
            $table->unsignedInteger('competitive_xp_awarded')->default(0); // Separated competitive weekly XP
            $table->unsignedInteger('duration_seconds');
            $table->timestamp('completed_at');
            $table->timestamp('synced_at')->useCurrent();
            $table->enum('verification_status', ['verified', 'flagged_suspicious', 'rejected'])->default('verified');
            $table->string('rejection_reason', 255)->nullable();

            $table->index(['user_id', 'completed_at']);
            $table->index(['guest_identity_id', 'completed_at']);
            $table->index(['device_id', 'completed_at']);
            $table->index('verification_status');
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
            $table->foreignId('user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('guest_identity_id')->nullable()->constrained('guest_identities')->restrictOnDelete();
            $table->enum('reward_source', ['quiz_completion', 'daily_streak', 'church_bonus', 'guest_claim_transfer', 'late_sync_rollover', 'reversal']);
            $table->string('source_id', 64);
            $table->uuid('event_id');
            $table->integer('xp_amount');
            $table->timestamp('awarded_at')->useCurrent();

            // Generated principal identifier for unified exactly-once uniqueness
            $table->string('principal_key', 64)->nullable()->virtualAs(
                "CASE WHEN user_id IS NOT NULL THEN CONCAT('U:', user_id) WHEN guest_identity_id IS NOT NULL THEN CONCAT('G:', guest_identity_id) ELSE NULL END"
            );

            $table->unique(['principal_key', 'reward_source', 'source_id']);
            $table->index(['user_id', 'awarded_at']);
            $table->index(['guest_identity_id', 'awarded_at']);
            $table->index('event_id');
        });

        // Add check constraint for owner enforcement on MySQL
        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE user_xp_ledger ADD CONSTRAINT chk_ledger_owner CHECK (user_id IS NOT NULL OR guest_identity_id IS NOT NULL)');
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('user_xp_ledger');
        Schema::dropIfExists('quiz_session_items');
        Schema::dropIfExists('quiz_sessions');
    }
};
