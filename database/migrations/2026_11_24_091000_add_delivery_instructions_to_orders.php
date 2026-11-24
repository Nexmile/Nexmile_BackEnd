<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Two things the customer asks for at checkout that were travelling inside
 * `customer_note`.
 *
 * The note is one field read by two audiences. "Less spicy" is the kitchen's
 * business and "leave at the door" is the rider's, and with both in one string
 * each side reads instructions it cannot act on — while `no cutlery`, which is
 * the kitchen's, sat in a field the rider also sees. Separating them is what
 * lets each resource send only its own half.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            /*
             * Codes, validated against config/checkout.php. JSON rather than a
             * pivot table: it is a closed list of five, read whole, never
             * queried across orders and never joined.
             */
            $table->json('delivery_instructions')->nullable()->after('customer_note');

            /*
             * Defaults to false rather than null. "The customer did not ask
             * for cutlery to be left out" and "we do not know" are the same
             * thing for a kitchen packing a bag, and a nullable boolean would
             * invite a third state nothing reads.
             */
            $table->boolean('no_cutlery')->default(false)->after('delivery_instructions');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['delivery_instructions', 'no_cutlery']);
        });
    }
};
