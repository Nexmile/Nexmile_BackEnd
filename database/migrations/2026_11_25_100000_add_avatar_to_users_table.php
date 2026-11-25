<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A profile picture, which the app has been asking for and we never had.
 *
 * A storage path rather than a URL: the object lives on a private disk and is
 * handed out as a signed, expiring link, so a URL written into a column would
 * be dead within the day. The same reasoning as every other image we keep.
 *
 * Deliberately not added to the model's $fillable. Every other column on this
 * table is a value a user may type; this one is a key into a bucket, and mass
 * assigning it from request input would let somebody point their avatar at
 * another object in the same bucket — which is where the KYC documents are.
 * It is written with forceFill from one place that just wrote the file.
 *
 * Nothing about riders changes here. A rider's `profile_photo` is a KYC
 * document sitting beside their Aadhaar, collected to verify who they are,
 * and `rider.photo_url` stays null on purpose — see docs/REVIEWS.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('avatar_path')->nullable()->after('promotional_notifications');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('avatar_path');
        });
    }
};
