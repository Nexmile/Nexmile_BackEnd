<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A third reason to send somebody a code.
 *
 * The same table, the same throttles and the same delivery as signing in —
 * because a password reset code is a one-time code sent to an identifier the
 * person controls, which is exactly what this table already models. A second
 * table would be a second place to get the expiry, the attempt ceiling and
 * the burn-the-previous-code rule wrong.
 *
 * Written through the schema builder rather than as `ALTER TABLE … MODIFY`.
 * The raw form is MySQL's, and the test suite runs on SQLite, where an enum
 * is a text column with a check constraint and the statement is a syntax
 * error — so a migration that worked on the server failed everywhere it
 * would have been caught.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('otp_codes', function (Blueprint $table) {
            $table->enum('purpose', ['login', 'phone_verification', 'password_reset'])
                ->default('login')
                ->change();
        });
    }

    public function down(): void
    {
        Schema::table('otp_codes', function (Blueprint $table) {
            $table->enum('purpose', ['login', 'phone_verification'])
                ->default('login')
                ->change();
        });
    }
};
