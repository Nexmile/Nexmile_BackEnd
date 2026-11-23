<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tags on a review, and somewhere to say something about the rider.
 *
 * A star is a score and nothing else. "3" tells a kitchen that something was
 * wrong and not which thing, and most people will not type a sentence — so the
 * one question they will answer is a tap. Tags are what turn a bare number
 * into something a restaurant or a rider can act on.
 *
 * Stored as codes in JSON rather than rows in a join table. The vocabulary is
 * fixed and lives in config/reviews.php, there are a handful per review, and
 * they are read back with the review itself every single time — a table would
 * buy referential integrity over a list that never changes and cost a join on
 * every read.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reviews', function (Blueprint $table) {
            // Said about the food. Public: these appear on the restaurant's
            // review list beside the comment.
            $table->json('tags')->nullable()->after('comment');

            /*
             * Said about the delivery.
             *
             * Never public. A tag like `rude` names no one on a restaurant
             * page, but on a rider's it is an accusation against one worker,
             * and the rider is the person here with the least ability to
             * answer back. Ops read these; customers do not.
             */
            $table->json('rider_tags')->nullable()->after('tags');

            /*
             * And the sentence behind them, for ops and the rider's record.
             *
             * Short on purpose — see reviews.rider_comment_max. Deliberately
             * not merged into `comment`: that column is published, and a
             * customer writing about the person who came to their door has not
             * agreed to have it read by strangers.
             */
            $table->string('rider_comment', 300)->nullable()->after('rider_tags');
        });
    }

    public function down(): void
    {
        Schema::table('reviews', function (Blueprint $table) {
            $table->dropColumn(['tags', 'rider_tags', 'rider_comment']);
        });
    }
};
