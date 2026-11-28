<?php

return [

    /*
     * Who turns speech into text and back again.
     *
     * `null` means neither, and the endpoints answer 503 — which is not a
     * failure state. The customer app already does both on the handset with
     * Android's own recogniser and voice, so a server with no speech
     * provider degrades to what ships today rather than to silence.
     *
     * Set to `sarvam` once a key exists.
     */
    'provider' => env('SPEECH_PROVIDER'),

    'sarvam' => [
        /*
         * Lives here and only here. The whole reason these endpoints exist
         * is that a key inside an APK is a key anyone can extract, and a
         * speech API billed per second of audio is a bill anyone can run up.
         */
        'key' => env('SARVAM_API_KEY'),

        'base_url' => env('SARVAM_API_URL', 'https://api.sarvam.ai'),

        /*
         * Sarvam's own model names. Kept in configuration rather than in
         * code because they version them faster than we deploy, and a model
         * rename should not need a release.
         */
        'stt_model' => env('SARVAM_STT_MODEL', 'saarika:v2.5'),
        'tts_model' => env('SARVAM_TTS_MODEL', 'bulbul:v2'),

        // A voice. Changing it changes what every customer hears, so it is
        // one setting rather than a per-request choice.
        'speaker' => env('SARVAM_TTS_SPEAKER', 'anushka'),
    ],

    /*
     * Fifteen seconds of audio, roughly. A question about an order is one
     * sentence; anything longer is a phone held in a pocket, and it is
     * billed by the second.
     */
    'max_audio_kb' => env('SPEECH_MAX_AUDIO_KB', 512),

    /*
     * What a customer may say before we stop listening, per minute. Speech
     * is the most expensive thing this platform does per request, and the
     * app holds a microphone button that a child can lean on.
     */
    'rate_limit_per_minute' => env('SPEECH_RATE_LIMIT', 20),

    /*
     * Eight seconds. Somebody is holding a phone to their ear waiting for
     * an answer; past this they have already given up and tapped again.
     */
    'timeout_seconds' => env('SPEECH_TIMEOUT', 8),

    /*
     * The longest reply we will read aloud. A spoken answer is two or three
     * sentences by design — anything longer is a bug upstream, and sending
     * it to a per-character API turns that bug into a bill.
     */
    'max_speak_characters' => 600,

];
