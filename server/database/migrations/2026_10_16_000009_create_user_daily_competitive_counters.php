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
        Schema::create('user_daily_competitive_counters', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('principal_key', 64);
            $table->foreignId('user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('guest_identity_id')->nullable()->constrained('guest_identities')->restrictOnDelete();
            $table->date('award_date');
            $table->unsignedSmallInteger('competitive_xp_total')->default(0);
            $table->uuid('last_event_id')->nullable();
            $table->timestamps();

            // Strict exactly-one-row per principal per UTC calendar date
            $table->unique(['principal_key', 'award_date'], 'uq_principal_award_date');
            $table->index(['user_id', 'award_date'], 'idx_daily_counter_user_date');
            $table->index(['guest_identity_id', 'award_date'], 'idx_daily_counter_guest_date');
        });

        // Add CHECK constraints for engine-level enforcement in MySQL
        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE user_daily_competitive_counters ADD CONSTRAINT chk_daily_counter_owner CHECK (user_id IS NOT NULL OR guest_identity_id IS NOT NULL)');
            DB::statement('ALTER TABLE user_daily_competitive_counters ADD CONSTRAINT chk_daily_cap_bound CHECK (competitive_xp_total <= 400)');
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('user_daily_competitive_counters');
    }
};
