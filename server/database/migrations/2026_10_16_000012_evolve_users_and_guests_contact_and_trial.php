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
        // 1. Evolve users table to support phone-only registration alongside email
        Schema::table('users', function (Blueprint $table) {
            $table->string('email')->nullable()->change();
            $table->string('phone_number', 32)->nullable()->unique()->after('email');
            $table->timestamp('phone_verified_at')->nullable()->after('email_verified_at');
        });

        // Enforce that a user must possess at least one verified contact method (email or phone)
        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE users ADD CONSTRAINT chk_user_contact_present CHECK (email IS NOT NULL OR phone_number IS NOT NULL)');
        }

        // 2. Evolve guest_identities table for Approach A contact capture and trial accounting
        Schema::table('guest_identities', function (Blueprint $table) {
            $table->unsignedTinyInteger('is_legacy')->default(0)->after('id');
            $table->string('email', 255)->nullable()->after('secret_hash');
            $table->string('phone_number', 32)->nullable()->after('email');
            $table->timestamp('email_verified_at')->nullable()->after('phone_number');
            $table->timestamp('phone_verified_at')->nullable()->after('email_verified_at');

            $table->unsignedSmallInteger('completed_quiz_count')->default(0)->after('phone_verified_at');
            $table->unsignedInteger('gross_lifetime_xp')->default(0)->after('completed_quiz_count');
            $table->uuid('active_quiz_session_id')->nullable()->after('gross_lifetime_xp');
            $table->timestamp('trial_limit_reached_at')->nullable()->after('active_quiz_session_id');

            $table->index('email', 'idx_guest_email');
            $table->index('phone_number', 'idx_guest_phone');
            $table->index('active_quiz_session_id', 'idx_guest_active_session');
            $table->index('trial_limit_reached_at', 'idx_guest_trial_boundary');
        });

        // 3. Mark pre-existing contactless guest records as legacy
        DB::table('guest_identities')
            ->whereNull('email')
            ->whereNull('phone_number')
            ->update(['is_legacy' => 1]);

        // NOTE: chk_guest_contact_present (CHECK (is_legacy = 1 OR email IS NOT NULL OR phone_number IS NOT NULL))
        // is deliberately deferred to Phase 3C.2B.5 per Option 2 (Two-Phase Constraint Deployment).
        // This ensures the current guest authentication endpoint remains fully compatible during schema rollout.
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // 1. Fail-closed safeguard: refuse rollback if users have phone-only accounts or phone credentials
        $hasPopulatedUserPhone = DB::table('users')
            ->whereNull('email')
            ->orWhereNotNull('phone_number')
            ->orWhereNotNull('phone_verified_at')
            ->exists();

        if ($hasPopulatedUserPhone) {
            throw new \RuntimeException('Cannot rollback Migration 12: Populated user phone credentials or verification state exist.');
        }

        // 2. Fail-closed safeguard: refuse rollback if guest identities hold populated contact, verification, or trial data
        $hasPopulatedGuestData = DB::table('guest_identities')
            ->where(function ($query) {
                $query->whereNotNull('email')
                    ->orWhereNotNull('phone_number')
                    ->orWhereNotNull('email_verified_at')
                    ->orWhereNotNull('phone_verified_at')
                    ->orWhere('completed_quiz_count', '>', 0)
                    ->orWhere('gross_lifetime_xp', '>', 0)
                    ->orWhereNotNull('active_quiz_session_id')
                    ->orWhereNotNull('trial_limit_reached_at');
            })
            ->exists();

        if ($hasPopulatedGuestData) {
            throw new \RuntimeException('Cannot rollback Migration 12: Populated guest contact details, verification state, or trial progress exist.');
        }

        // 2. Drop user contact check constraint in MySQL
        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE users DROP CONSTRAINT chk_user_contact_present');
        }

        // 3. Drop guest_identities evolution columns and indexes
        Schema::table('guest_identities', function (Blueprint $table) {
            $table->dropIndex('idx_guest_email');
            $table->dropIndex('idx_guest_phone');
            $table->dropIndex('idx_guest_active_session');
            $table->dropIndex('idx_guest_trial_boundary');

            $table->dropColumn([
                'is_legacy',
                'email',
                'phone_number',
                'email_verified_at',
                'phone_verified_at',
                'completed_quiz_count',
                'gross_lifetime_xp',
                'active_quiz_session_id',
                'trial_limit_reached_at',
            ]);
        });

        // 4. Revert users table evolution
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['phone_number']);
            $table->dropColumn(['phone_number', 'phone_verified_at']);
            $table->string('email')->nullable(false)->change();
        });
    }
};
