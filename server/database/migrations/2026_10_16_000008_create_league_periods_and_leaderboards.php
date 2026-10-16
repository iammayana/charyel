<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
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
            $table->unique(['year_week', 'scope', 'scope_id', 'user_id'], 'uq_weekly_rollups_user');
            $table->index(['year_week', 'scope', 'scope_id', 'rank_position'], 'idx_weekly_rollups_rank');
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
            $table->unique(['year_week', 'scope', 'scope_id', 'rank_position'], 'uq_finalized_podiums_rank');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('leaderboard_finalized_podiums');
        Schema::dropIfExists('leaderboard_weekly_rollups');
        Schema::dropIfExists('league_periods');
    }
};
