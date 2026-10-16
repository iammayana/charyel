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
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('churches');
    }
};
