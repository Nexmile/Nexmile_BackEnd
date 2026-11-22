<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Every call an outside system makes into Nexmile, and what it read.
 *
 * The admin panel has never had an access log, which was tolerable while the
 * only people in it were two colleagues. It stops being tolerable the moment
 * a separate system can ask "show me this customer's orders": the question
 * "who looked at this person's data, and when" has to have an answer, and the
 * answer cannot live in the system being asked about.
 *
 * Append-only. Nothing updates a row here, which is why there is no
 * `updated_at` — a log that can be edited is not evidence.
 *
 * `subject_type` and `subject_id` record *what was read*, not just which URL
 * was hit, so the question can be asked from the other end: everything any
 * service has ever read about order 4821.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('service_api_logs', function (Blueprint $table) {
            $table->id();

            // nullOnDelete: removing a retired integration must not erase the
            // record of what it did while it existed.
            $table->foreignId('service_client_id')->nullable()->constrained()->nullOnDelete();

            $table->string('method', 8);
            $table->string('path');
            $table->string('scope', 64)->nullable();

            // What was read or written, where the endpoint names one thing.
            $table->string('subject_type', 32)->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();

            $table->unsignedSmallInteger('status_code');

            /*
             * Hashed, not stored. The address identifies the caller's host for
             * an investigation; keeping it in the clear turns an access log
             * into a second pile of personal data.
             */
            $table->string('ip_hash', 64)->nullable();

            $table->unsignedInteger('duration_ms')->nullable();

            $table->timestamp('created_at')->useCurrent();

            $table->index(['service_client_id', 'created_at']);
            $table->index(['subject_type', 'subject_id']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_api_logs');
    }
};
