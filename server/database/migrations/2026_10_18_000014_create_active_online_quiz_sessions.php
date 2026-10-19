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
        Schema::create('active_online_quiz_sessions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('guest_identity_id')->constrained('guest_identities')->restrictOnDelete();
            $table->enum('quiz_mode', ['standard', 'blitz', 'church_clash', 'marathon'])->default('standard');
            $table->string('category', 50)->nullable();
            $table->enum('difficulty', ['easy', 'medium', 'hard', 'mixed'])->default('mixed');
            $table->string('device_id', 64);
            $table->json('manifest_payload');
            $table->char('manifest_hash', 64);
            $table->enum('status', ['active', 'completed', 'abandoned', 'expired'])->default('active');
            $table->timestamp('started_at')->useCurrent();
            $table->timestamp('expires_at');
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('abandoned_at')->nullable();
            $table->timestamp('expired_at')->nullable();
            $table->timestamp('created_at')->useCurrent();

            // Generated column: active_presence evaluates to 1 when status is 'active', else NULL
            $table->unsignedTinyInteger('active_presence')
                ->nullable()
                ->storedAs("CASE WHEN status = 'active' THEN 1 ELSE NULL END");

            // Database-enforced invariant: at most ONE active online session per guest
            $table->unique(['guest_identity_id', 'active_presence'], 'uq_guest_active_online_session');

            $table->index(['guest_identity_id', 'status'], 'idx_online_guest_status');
            $table->index('expires_at', 'idx_online_expires_at');
            $table->index('started_at', 'idx_online_started_at');
        });

        // Add MySQL engine-level CHECK constraints for terminal timestamp and status integrity
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE active_online_quiz_sessions ADD CONSTRAINT chk_active_terminal_times CHECK (
                expires_at > started_at AND (
                    (status = 'active' AND completed_at IS NULL AND abandoned_at IS NULL AND expired_at IS NULL) OR
                    (status = 'completed' AND completed_at IS NOT NULL AND abandoned_at IS NULL AND expired_at IS NULL) OR
                    (status = 'abandoned' AND abandoned_at IS NOT NULL AND completed_at IS NULL AND expired_at IS NULL) OR
                    (status = 'expired' AND expired_at IS NOT NULL AND completed_at IS NULL AND abandoned_at IS NULL)
                )
            )");
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('active_online_quiz_sessions');
    }
};
