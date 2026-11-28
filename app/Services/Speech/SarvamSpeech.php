<?php

namespace App\Services\Speech;

use App\Contracts\SpeechProvider;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Sarvam, for Indian languages.
 *
 * Chosen over the handset's own engine for one reason: Android's recogniser
 * is trained on English and Hindi far more than on Tamil, and it has never
 * heard Tanglish. "Order enga iruku" is the normal way to ask here and the
 * on-device engine makes a mess of it.
 *
 * **Nothing here reaches the app.** The key is billed per second of audio
 * and a key inside an APK is a key anyone can pull out of it — which is why
 * this is a server call at all rather than the app talking to Sarvam
 * directly.
 *
 * Every failure returns null rather than throwing. The app falls back to the
 * handset, which is a worse transcription and not a broken microphone.
 */
class SarvamSpeech implements SpeechProvider
{
    public function configured(): bool
    {
        return ! empty(config('speech.sarvam.key'));
    }

    public function transcribe(UploadedFile $audio, ?string $language = null): ?array
    {
        if (! $this->configured()) {
            return null;
        }

        try {
            $response = $this->request()
                ->attach('file', file_get_contents($audio->getRealPath()), $audio->getClientOriginalName() ?: 'audio.wav')
                ->post('/speech-to-text', array_filter([
                    'model' => config('speech.sarvam.stt_model'),

                    /*
                     * A hint, not an instruction. A customer whose app is in
                     * English may still speak Tamil, and Sarvam detects it
                     * better than we can guess — so when we do not know, we
                     * say so rather than asserting English and being wrong
                     * in the one direction that matters here.
                     */
                    'language_code' => $this->code($language),
                ], fn ($value) => $value !== null));

            if (! $response->successful()) {
                Log::warning('Sarvam refused a transcription', [
                    'status' => $response->status(),
                    'error' => $response->json('error.message') ?? $response->json('message'),
                ]);

                return null;
            }

            $text = trim((string) ($response->json('transcript') ?? $response->json('text') ?? ''));

            if ($text === '') {
                return null;
            }

            return [
                'text' => $text,
                'language' => $this->locale($response->json('language_code')),
            ];
        } catch (Throwable $e) {
            Log::warning('Sarvam unreachable for transcription', ['message' => $e->getMessage()]);

            return null;
        }
    }

    public function speak(string $text, string $language): ?string
    {
        if (! $this->configured() || trim($text) === '') {
            return null;
        }

        try {
            $response = $this->request()->post('/text-to-speech', [
                'text' => $text,
                'target_language_code' => $this->code($language) ?? 'en-IN',
                'speaker' => config('speech.sarvam.speaker'),
                'model' => config('speech.sarvam.tts_model'),
            ]);

            if (! $response->successful()) {
                Log::warning('Sarvam refused to speak', [
                    'status' => $response->status(),
                    'error' => $response->json('error.message') ?? $response->json('message'),
                ]);

                return null;
            }

            /*
             * Sarvam answers with a list of base64 clips, one per chunk it
             * split the text into. Joined here rather than in the app: how
             * many pieces it chose is an implementation detail of theirs,
             * and an app that had to stitch them would break the day they
             * changed the chunking.
             */
            $audios = $response->json('audios');

            if (! is_array($audios) || $audios === []) {
                return null;
            }

            return implode('', array_map(fn ($clip) => (string) $clip, $audios));
        } catch (Throwable $e) {
            Log::warning('Sarvam unreachable for speech', ['message' => $e->getMessage()]);

            return null;
        }
    }

    private function request(): \Illuminate\Http\Client\PendingRequest
    {
        return Http::withHeaders(['api-subscription-key' => (string) config('speech.sarvam.key')])
            ->baseUrl(rtrim((string) config('speech.sarvam.base_url'), '/'))
            ->acceptJson()
            ->timeout((int) config('speech.timeout_seconds'));
    }

    /**
     * Our two-letter language, as Sarvam's locale.
     *
     * Null for anything we do not map, so the request leaves the field out
     * and Sarvam detects it — which is better than asserting a language and
     * being wrong.
     */
    private function code(?string $language): ?string
    {
        return match ($language) {
            'ta' => 'ta-IN',
            'hi' => 'hi-IN',
            'en' => 'en-IN',
            default => null,
        };
    }

    /** And back again, for whatever it says it heard. */
    private function locale(mixed $code): ?string
    {
        return match (is_string($code) ? substr($code, 0, 2) : null) {
            'ta' => 'ta',
            'hi' => 'hi',
            'en' => 'en',
            default => null,
        };
    }
}
