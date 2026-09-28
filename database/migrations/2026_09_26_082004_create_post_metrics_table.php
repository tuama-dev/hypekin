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
        Schema::create('post_metrics', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('post_id')->constrained()->cascadeOnDelete();
            $table->string('platform');
            $table->string('snapshot_type')->default('snapshot');
            $table->date('snapshot_date');
            $table->json('data');
            $table->timestamps();

            $table->unique(['post_id', 'platform', 'snapshot_date']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('post_metrics');
    }
};
