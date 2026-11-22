<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Machines that may call Nexmile on their own behalf.
 *
 * Until now every token in this platform belonged to a person who had passed
 * a one-time password. The BPO platform is not a person: it calls as itself,
 * on behalf of an agent who is helping a customer, and it has to be told apart
 * from that customer in the logs and in what it may read.
 *
 * The credential itself is a Sanctum personal access token, because
 * `personal_access_tokens` is already polymorphic and already supports
 * abilities — the scopes this needs. Nothing new is invented for storage; the
 * only new idea is that a token may belong to a service rather than a user.
 *
 * Scopes live on the token so one client can hold several with different
 * reach. The row carries what is true of the client whatever token it is
 * holding: whether it is switched on at all, and who to ring when it
 * misbehaves.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('service_clients', function (Blueprint $table) {
            $table->id();

            $table->string('name');
            $table->string('slug')->unique();

            /*
             * A kill switch that does not require finding every token. An
             * integration behaving badly at 2am is suspended by one row, and
             * the middleware refuses it on the next request.
             */
            $table->boolean('is_active')->default(true);

            // Who to contact. A credential nobody owns is a credential nobody
            // rotates.
            $table->string('owner_name')->nullable();
            $table->string('owner_email')->nullable();

            $table->text('notes')->nullable();

            /*
             * Requests a minute, enforced per client. A runaway retry loop on
             * the caller's side should degrade their service, not ours.
             */
            $table->unsignedSmallInteger('rate_limit_per_minute')->default(120);

            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_clients');
    }
};
