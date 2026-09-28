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
        Schema::table('post_targets', function (Blueprint $table) {
            $table->string('platform_upload_id')->nullable();
            $table->string('title')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('post_targets', function (Blueprint $table) {
            $table->dropColumn(['platform_upload_id', 'title']);
        });
    }
};
