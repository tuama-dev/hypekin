<?php

use App\Enums\WorkspaceRole;
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
        Schema::create('workspace_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('role');
            $table->timestamps();
            $table->unique(['workspace_id', 'user_id']);
        });

        DB::table('workspace_user')->insert(
            DB::table('workspaces')
                ->whereNotNull('user_id')
                ->get(['id', 'user_id'])
                ->map(fn (object $workspace) => [
                    'workspace_id' => $workspace->id,
                    'user_id' => $workspace->user_id,
                    'role' => WorkspaceRole::Owner->value,
                    'created_at' => now(),
                    'updated_at' => now(),
                ])
                ->all()
        );

        Schema::table('workspaces', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
            $table->dropColumn('user_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('workspaces', function (Blueprint $table) {
            $table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete();
        });

        DB::table('workspace_user')
            ->join('workspaces', 'workspaces.id', '=', 'workspace_user.workspace_id')
            ->where('workspace_user.role', WorkspaceRole::Owner->value)
            ->update(['workspaces.user_id' => DB::raw('workspace_user.user_id')]);

        Schema::dropIfExists('workspace_user');
    }
};
