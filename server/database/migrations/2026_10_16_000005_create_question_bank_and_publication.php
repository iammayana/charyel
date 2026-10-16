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
        Schema::create('publication_sequences', function (Blueprint $table) {
            $table->unsignedInteger('id')->default(1)->primary();
            $table->unsignedBigInteger('current_sequence')->default(0);
            $table->timestamp('last_published_at')->useCurrent()->useCurrentOnUpdate();
        });

        // Controlled idempotent initialization of singleton publication counter
        DB::table('publication_sequences')->updateOrInsert(
            ['id' => 1],
            ['current_sequence' => 0, 'last_published_at' => now()]
        );

        Schema::create('questions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->enum('testament', ['OT', 'NT', 'BOTH']);
            $table->string('book', 50);
            $table->unsignedSmallInteger('chapter')->nullable();
            $table->unsignedSmallInteger('verse')->nullable();
            $table->string('scripture_reference', 100);
            $table->string('scripture_version', 20)->default('KJV');
            $table->string('category', 50);
            $table->enum('difficulty', ['easy', 'medium', 'hard'])->default('medium');
            $table->unsignedInteger('version')->default(1);
            $table->unsignedBigInteger('current_revision_id')->nullable();
            $table->boolean('is_published')->default(true);
            $table->boolean('is_withdrawn')->default(false);
            $table->softDeletes();
            $table->timestamps();

            $table->index(['difficulty', 'category']);
            $table->index(['testament', 'book']);
        });

        Schema::create('question_revisions', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->uuid('question_id');
            $table->unsignedInteger('version');
            $table->text('question_text');
            $table->json('options');
            $table->string('correct_option_id', 10);
            $table->text('explanation')->nullable();
            $table->string('change_summary', 255)->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->foreign('question_id')->references('id')->on('questions')->restrictOnDelete();
            $table->unique(['question_id', 'version']);
        });

        Schema::create('question_changes', function (Blueprint $table) {
            $table->unsignedBigInteger('sequence_id')->primary();
            $table->uuid('question_id');
            $table->unsignedBigInteger('revision_id');
            $table->enum('operation', ['created', 'updated', 'withdrawn', 'restored']);
            $table->timestamp('published_at')->useCurrent();
            $table->foreignId('published_by_user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->foreign('question_id')->references('id')->on('questions')->restrictOnDelete();
            $table->foreign('revision_id')->references('id')->on('question_revisions')->restrictOnDelete();
            $table->index(['sequence_id', 'published_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('question_changes');
        Schema::dropIfExists('question_revisions');
        Schema::dropIfExists('questions');
        Schema::dropIfExists('publication_sequences');
    }
};
