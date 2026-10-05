<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The columns were declared string(255) and stored provider access tokens in
 * plaintext. Platform tokens routinely exceed 255 characters, so under MySQL
 * strict mode saving one failed outright, and a database dump handed over every
 * connected social account to whoever read it.
 *
 * The columns become text because an encrypted payload is always longer than
 * the value it wraps, then existing rows are re-encrypted in place. Reads go
 * through the query builder on purpose: the model casts must not be in play
 * yet or Laravel would try to decrypt the plaintext it is about to replace.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('user_oauth_providers', function (Blueprint $table) {
            $table->text('token')->nullable()->change();
            $table->text('refresh_token')->nullable()->change();
        });

        $this->rewriteTokens(
            fn (string $value): string => $this->encryptIfPlaintext($value),
        );
    }

    /**
     * Reverse the migrations.
     *
     * Values are decrypted again, but the columns are deliberately left as
     * text. Narrowing them back to string(255) would truncate any token that is
     * legitimately longer than that — the very data this migration exists to
     * support — so the width change is treated as one-way.
     */
    public function down(): void
    {
        $this->rewriteTokens(
            fn (string $value): string => $this->decryptIfEncrypted($value),
        );
    }

    /**
     * Apply $rewrite to every stored token, in place.
     */
    private function rewriteTokens(Closure $rewrite): void
    {
        DB::table('user_oauth_providers')
            ->select(['id', 'token', 'refresh_token'])
            ->orderBy('id')
            ->each(function (object $row) use ($rewrite): void {
                $updates = [];

                foreach (['token', 'refresh_token'] as $column) {
                    $value = $row->{$column};

                    if ($value === null || $value === '') {
                        continue;
                    }

                    $updates[$column] = $rewrite($value);
                }

                if ($updates !== []) {
                    DB::table('user_oauth_providers')->where('id', $row->id)->update($updates);
                }
            });
    }

    /**
     * Leave already-encrypted values alone so the migration stays re-runnable.
     */
    private function encryptIfPlaintext(string $value): string
    {
        try {
            Crypt::decryptString($value);

            return $value;
        } catch (Throwable) {
            return Crypt::encryptString($value);
        }
    }

    private function decryptIfEncrypted(string $value): string
    {
        try {
            return Crypt::decryptString($value);
        } catch (Throwable) {
            return $value;
        }
    }
};
