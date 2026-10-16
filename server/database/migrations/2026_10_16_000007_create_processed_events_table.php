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
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('processed_events');
    }
};
