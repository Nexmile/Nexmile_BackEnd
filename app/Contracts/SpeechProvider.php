<?php

namespace App\Contracts;

use Illuminate\Http\UploadedFile;

/**
 * Speech in, speech out — on the server, never on the handset.
 *
 * The customer app already recognises and speaks on the device, and that
 * keeps working. This exists because Android's recogniser is trained on
 * English and Hindi far more than on Tamil, and it has never heard
 * Tanglish — a customer saying "order enga iruku" is the normal case here
 * and the on-device engine makes a mess of it.
 *
 * It is a server interface rather than an app one for a reason the brief
 * states itself: the key is billed per second of audio, and a key inside an
 * APK is a key anyone can pull out of it.
 */
interface SpeechProvider
{
    /**
     * Is there anything behind this?
     *
     * False means the endpoints answer 503 and the app falls back to the
     * handset. That is a worse transcription, not a broken feature.
     */
    public function configured(): bool;

    /**
     * Audio to words, or null when it cannot be done.
     *
     * `$language` is a hint, not an instruction: a customer whose app is in
     * English may still speak Tamil, so a provider that can detect it should
     * and the answer says which it heard.
     *
     * @return array{text: string, language: string|null}|null
     */
    public function transcribe(UploadedFile $audio, ?string $language = null): ?array;

    /**
     * Words to audio, base64 encoded, or null.
     *
     * Base64 rather than a stream, because the app plays it once and the
     * replies are two or three sentences. A streaming endpoint would be the
     * right answer for something long enough to listen to while it arrives,
     * and nothing here is.
     */
    public function speak(string $text, string $language): ?string;
}
