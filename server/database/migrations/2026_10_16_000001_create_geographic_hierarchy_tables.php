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
        Schema::create('countries', function (Blueprint $table) {
            $table->char('code', 2)->primary();
            $table->string('name', 100);
            $table->char('currency_code', 3)->default('USD');
            $table->boolean('is_active')->default(true);
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::create('regions', function (Blueprint $table) {
            $table->increments('id');
            $table->char('country_code', 2);
            $table->string('name', 100);
            $table->string('code', 20)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->foreign('country_code')->references('code')->on('countries')->restrictOnDelete();
            $table->unique(['country_code', 'name']);
        });

        Schema::create('localities', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('region_id');
            $table->string('name', 100);
            $table->string('slug', 120);
            $table->timestamp('created_at')->useCurrent();

            $table->foreign('region_id')->references('id')->on('regions')->restrictOnDelete();
            $table->unique(['region_id', 'slug']);
            $table->index('name');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('localities');
        Schema::dropIfExists('regions');
        Schema::dropIfExists('countries');
    }
};
