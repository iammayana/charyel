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
        Schema::create('user_profiles', function (Blueprint $table) {
            $table->foreignId('user_id')->primary()->constrained('users')->cascadeOnDelete();
            $table->unsignedInteger('locality_id')->nullable();
            $table->foreignId('primary_church_id')->nullable()->constrained('churches')->nullOnDelete();
            $table->string('pilgrim_name', 50);
            $table->string('avatar_seed', 64)->default('default');
            $table->unsignedBigInteger('total_xp')->default(0);
            $table->unsignedInteger('current_streak')->default(0);
            $table->unsignedInteger('longest_streak')->default(0);
            $table->boolean('is_leaderboard_public')->default(false);
            $table->boolean('show_church_affiliation')->default(false);
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

            $table->unsignedBigInteger('active_primary_user_id')->nullable()
                ->virtualAs("CASE WHEN is_primary = 1 AND status = 'active' THEN user_id ELSE NULL END");

            $table->unique(['user_id', 'church_id']);
            $table->unique('active_primary_user_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('church_memberships');
        Schema::dropIfExists('user_profiles');
    }
};
