<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Re-key post_metrics from (post_id, platform) to the concrete post_target.
     *
     * A post can legitimately carry several targets on the same platform (two
     * Facebook accounts for one post), so the platform key collapsed those
     * accounts into one row: the second target was silently skipped by the
     * guard, or collided on the unique key under concurrency. Snapshots are
     * inherently per-account, so uniqueness now lives on
     * (post_target_id, snapshot_date). Existing rows are backfilled only when
     * they map to a single target of that platform; ambiguous rows (a post
     * with multiple same-platform targets) were already conflating two
     * accounts and cannot be re-attributed, so they are dropped.
     */
    public function up(): void
    {
        Schema::table('post_metrics', function (Blueprint $table) {
            $table->foreignUlid('post_target_id')->nullable()->after('post_id');
        });

        DB::statement(
            'UPDATE post_metrics pm
             JOIN post_targets pt ON pt.post_id = pm.post_id
             JOIN social_accounts sa ON sa.id = pt.social_account_id
             SET pm.post_target_id = pt.id
             WHERE pm.post_target_id IS NULL
               AND sa.platform = pm.platform
               AND (SELECT COUNT(*) FROM post_targets pt2
                    JOIN social_accounts sa2 ON sa2.id = pt2.social_account_id
                    WHERE pt2.post_id = pm.post_id
                      AND sa2.platform = pm.platform) = 1',
        );

        DB::table('post_metrics')->whereNull('post_target_id')->delete();

        Schema::table('post_metrics', function (Blueprint $table) {
            // MySQL keeps the post_id foreign key satisfied by an index on
            // post_id, so one must exist before the old unique is dropped.
            $table->index('post_id');
            $table->dropUnique(['post_id', 'platform', 'snapshot_date']);
            $table->char('post_target_id', 36)->nullable(false)->change();
            $table->foreign('post_target_id')->references('id')->on('post_targets')->cascadeOnDelete();
            $table->unique(['post_target_id', 'snapshot_date']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('post_metrics', function (Blueprint $table) {
            $table->dropUnique(['post_target_id', 'snapshot_date']);
            $table->dropForeign(['post_target_id']);
            $table->dropColumn('post_target_id');
            $table->dropIndex(['post_id']);
            $table->unique(['post_id', 'platform', 'snapshot_date']);
        });
    }
};
