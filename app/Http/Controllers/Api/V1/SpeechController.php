<?php

namespace App\Http\Controllers\Api\V1;

use App\Contracts\SpeechProvider;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Speaking to the assistant, and hearing it answer.
 *
 * Two endpoints, and the reason they exist on the server rather than in the
 * app is the one the brief gives itself: the key is billed per second of
 * audio, and a key inside an APK is a key anyone can pull out of it.
 *
 * **Neither of these talks to the assistant.** Audio comes in and words go
 * back; the app then sends those words to `/v1/support/conversations/{id}/messages`
 * exactly as if they had been typed, and reads the reply back through the
 * second endpoint. Keeping them apart means voice and text are the same
 * conversation, with the same history, the same order lookups and the same
 * handover — rather than a second assistant that happens to listen.
 *
 * Both answer **503 when no provider is configured**, which is not a failure
 * state: the app already recognises and speaks on the handset, so it falls
 * back to what ships today and loses only the better Tamil.
 */
class SpeechController extends Controller
{
    public function __construct(private readonly SpeechProvider $speech) {}

    /**
     * Turn a recording into words
     *
     * Send the clip as `audio`, with the thread's language as a hint. The
     * answer carries the language actually heard, which may not be the hint:
     * a customer whose app is in English often speaks Tamil, and that is the
     * case worth getting right here.
     */
    public function transcribe(Request $request): JsonResponse
    {
        if (! $this->speech->configured()) {
            return $this->unavailable();
        }

        $data = $request->validate([
            'audio' => ['required', 'file', 'max:'.(int) config('speech.max_audio_kb')],
            'language' => ['sometimes', 'nullable', 'in:en,ta,hi'],
        ], [
            'audio.max' => 'That recording is too long. Keep it to a short question.',
        ]);

        $heard = $this->speech->transcribe($request->file('audio'), $data['language'] ?? null);

        /*
         * Nothing recognised is an ordinary answer, not an error. A phone in
         * a noisy street hears nothing half the time, and a 500 would put a
         * crash dialog in front of somebody who simply needs to try again.
         */
        if ($heard === null) {
            return response()->json([
                'data' => ['text' => null, 'language' => null],
                'message' => 'Could not make that out. Try again, or type it.',
            ]);
        }

        return response()->json(['data' => $heard]);
    }

    /**
     * Read a reply aloud
     *
     * Send the assistant's `spoken` text — the version written to be heard,
     * which leaves out order numbers a speech engine would spell letter by
     * letter. Returns base64 audio the app plays once.
     */
    public function speak(Request $request): JsonResponse
    {
        if (! $this->speech->configured()) {
            return $this->unavailable();
        }

        $data = $request->validate([
            'text' => ['required', 'string', 'max:'.(int) config('speech.max_speak_characters')],
            'language' => ['required', 'in:en,ta,hi'],
        ], [
            /*
             * A spoken reply is two or three sentences by design. Anything
             * longer is a bug upstream, and sending it to an API billed per
             * character turns that bug into a bill.
             */
            'text.max' => 'That is too long to read aloud.',
        ]);

        $audio = $this->speech->speak($data['text'], $data['language']);

        if ($audio === null) {
            return $this->unavailable();
        }

        return response()->json(['data' => [
            'audio' => $audio,
            'encoding' => 'base64',
            'format' => 'wav',
        ]]);
    }

    private function unavailable(): JsonResponse
    {
        return response()->json([
            'error' => [
                'code' => 'SPEECH_UNAVAILABLE',
                // Said so the app knows to use the handset rather than to
                // show the customer an error about something it can do.
                'message' => 'Speech is not available on the server. Use the device instead.',
            ],
        ], 503);
    }
}
