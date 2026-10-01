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
        Schema::create('post_retry_attempts', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('post_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('attempted_by_user_id')->constrained('users')->cascadeOnDelete();
            $table->unsignedInteger('attempted_legs');
            $table->timestamp('attempted_at');
            $table->timestamps();

            $table->index(['post_id', 'attempted_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('post_retry_attempts');
    }
};
