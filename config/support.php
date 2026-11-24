<?php

return [

    /*
     * The BPO platform's service API.
     *
     * The second half of a boundary that already existed in one direction.
     * The BPO reads orders from `/api/internal/v1` here; this is Nexmile
     * calling the other way, to run a customer's support conversation.
     *
     * The customer app never calls it. It holds a Nexmile token and talks to
     * `/v1/support`, and this backend proxies — which is what keeps a
     * platform holding every client's transcripts off the public internet
     * and out of a phone's reach.
     */
    'base_url' => env('BPO_API_URL', 'http://127.0.0.1:8001/api/service/v1'),

    'token' => env('BPO_SERVICE_TOKEN'),

    /*
     * Short. Every one of these runs inside a request a customer is waiting
     * on, and the one call that could be slow — generating an answer — is
     * not made here: it is queued on the BPO side and polled for.
     */
    'timeout_seconds' => env('BPO_API_TIMEOUT', 5),

    /*
     * Messages a customer may send per minute.
     *
     * Each one may cost a model call, so this is a bill as much as a load
     * limit. Twenty is far more than a person types and far less than a
     * loop in a broken client build.
     */
    'messages_per_minute' => env('SUPPORT_RATE_LIMIT', 20),

];
