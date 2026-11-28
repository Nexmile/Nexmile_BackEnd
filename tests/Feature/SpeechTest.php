<?php

namespace Tests\Feature;

use App\Contracts\SpeechProvider;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\User;
use App\Services\Speech\NullSpeech;
use App\Services\Speech\SarvamSpeech;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Speaking to the assistant, and hearing it answer.
 *
 * These endpoints exist on the server rather than in the app for the reason
 * the brief gives itself: the speech key is billed per second of audio, and
 * a key inside an APK is a key anyone can pull out of it.
 *
 * Neither of them talks to the assistant. Audio becomes words; the app sends
 * those words as an ordinary message. That keeps voice and text one
 * conversation with one history, rather than a second assistant that happens
 * to listen.
 */
class SpeechTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'speech.provider' => 'sarvam',
            'speech.sarvam.key' => 'test-sarvam-key',
            'speech.sarvam.base_url' => 'https://sarvam.test',
        ]);

        $this->app->forgetInstance(SpeechProvider::class);
        Http::preventStrayRequests();
    }

    private function customer(): User
    {
        return User::create([
            'name' => 'Lakshmi', 'phone' => '9780000601', 'email' => 'speech@example.in',
            'password' => 'secret', 'role' => UserRole::Customer, 'status' => UserStatus::Active,
        ]);
    }

    private function clip(int $kilobytes = 20): UploadedFile
    {
        return UploadedFile::fake()->create('question.wav', $kilobytes, 'audio/wav');
    }

    /* ------------------------------------------------ listening ---- */

    public function test_a_recording_comes_back_as_words(): void
    {
        Http::fake(['sarvam.test/*' => Http::response([
            'transcript' => 'order enga iruku', 'language_code' => 'ta-IN',
        ])]);

        Sanctum::actingAs($this->customer());

        $this->postJson('/api/v1/speech/transcribe', ['audio' => $this->clip(), 'language' => 'en'])
            ->assertOk()
            ->assertJsonPath('data.text', 'order enga iruku')
            // Heard, not assumed. A customer whose app is in English often
            // speaks Tamil, and that is the case worth getting right here.
            ->assertJsonPath('data.language', 'ta');
    }

    public function test_the_key_never_leaves_the_server(): void
    {
        /*
         * The whole reason these endpoints exist. A key in an APK is a key
         * anyone can extract, and this one is billed by the second.
         */
        Http::fake(['sarvam.test/*' => Http::response(['transcript' => 'hello'])]);

        Sanctum::actingAs($this->customer());

        $body = $this->postJson('/api/v1/speech/transcribe', ['audio' => $this->clip()])
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('test-sarvam-key', (string) $body);

        Http::assertSent(fn ($request) => $request->header('api-subscription-key')[0] === 'test-sarvam-key'
            && ! str_contains($request->url(), 'test-sarvam-key'));
    }

    public function test_nothing_recognised_is_an_answer_rather_than_an_error(): void
    {
        /*
         * A phone in a noisy street hears nothing half the time. A 500 would
         * put a crash dialog in front of somebody who simply needs to speak
         * again.
         */
        Http::fake(['sarvam.test/*' => Http::response(['transcript' => ''])]);

        Sanctum::actingAs($this->customer());

        $this->postJson('/api/v1/speech/transcribe', ['audio' => $this->clip()])
            ->assertOk()
            ->assertJsonPath('data.text', null);
    }

    public function test_a_long_recording_is_refused_before_it_is_billed(): void
    {
        // Billed by the second, and the app holds a microphone button that
        // can be leaned on.
        Sanctum::actingAs($this->customer());

        $this->postJson('/api/v1/speech/transcribe', [
            'audio' => $this->clip((int) config('speech.max_audio_kb') + 256),
        ])->assertStatus(422)->assertJsonValidationErrors('audio');

        Http::assertNothingSent();
    }

    /* -------------------------------------------------- speaking --- */

    public function test_a_reply_comes_back_as_audio(): void
    {
        Http::fake(['sarvam.test/*' => Http::response(['audios' => ['UklGRg==', 'AAAA']])]);

        Sanctum::actingAs($this->customer());

        $this->postJson('/api/v1/speech/speak', [
            'text' => 'Your kishore food order is on its way to you.', 'language' => 'ta',
        ])
            ->assertOk()
            // Joined here, because how many pieces Sarvam split it into is a
            // detail of theirs and an app that stitched them would break the
            // day they changed it.
            ->assertJsonPath('data.audio', 'UklGRg==AAAA');
    }

    public function test_an_over_long_reply_is_refused(): void
    {
        /*
         * A spoken reply is two or three sentences by design. Anything
         * longer is a bug upstream, and sending it to an API billed per
         * character turns that bug into a bill.
         */
        Sanctum::actingAs($this->customer());

        $this->postJson('/api/v1/speech/speak', [
            'text' => str_repeat('a', (int) config('speech.max_speak_characters') + 50),
            'language' => 'en',
        ])->assertStatus(422)->assertJsonValidationErrors('text');

        Http::assertNothingSent();
    }

    /* ------------------------------------------- when it is off ---- */

    public function test_with_no_provider_the_app_is_told_to_use_the_handset(): void
    {
        /*
         * Not a failure. The app recognises and speaks on the device
         * already, so it falls back to what ships today and loses only the
         * better Tamil.
         */
        config(['speech.provider' => null, 'speech.sarvam.key' => null]);
        $this->app->forgetInstance(SpeechProvider::class);

        Sanctum::actingAs($this->customer());

        $this->postJson('/api/v1/speech/transcribe', ['audio' => $this->clip()])
            ->assertStatus(503)
            ->assertJsonPath('error.code', 'SPEECH_UNAVAILABLE');

        $this->postJson('/api/v1/speech/speak', ['text' => 'Hello.', 'language' => 'en'])
            ->assertStatus(503);
    }

    public function test_an_unknown_provider_falls_back_rather_than_failing(): void
    {
        // A typo in an env file should not take voice down to lose a feature
        // the phone can do itself.
        config(['speech.provider' => 'sarvem']);
        $this->app->forgetInstance(SpeechProvider::class);

        $this->assertInstanceOf(NullSpeech::class, app(SpeechProvider::class));
    }

    public function test_the_named_provider_is_the_one_bound(): void
    {
        $this->assertInstanceOf(SarvamSpeech::class, app(SpeechProvider::class));
    }

    /* ---------------------------------------------- who may speak -- */

    public function test_speech_needs_a_signed_in_customer(): void
    {
        // Otherwise the microphone is an open, metered API.
        $this->postJson('/api/v1/speech/transcribe', ['audio' => $this->clip()])->assertUnauthorized();
        $this->postJson('/api/v1/speech/speak', ['text' => 'Hello.', 'language' => 'en'])->assertUnauthorized();

        Http::assertNothingSent();
    }

    public function test_a_refusal_from_sarvam_is_not_a_crash(): void
    {
        Http::fake(['sarvam.test/*' => Http::response(['message' => 'quota exceeded'], 429)]);

        Sanctum::actingAs($this->customer());

        $this->postJson('/api/v1/speech/transcribe', ['audio' => $this->clip()])
            ->assertOk()
            ->assertJsonPath('data.text', null);

        $this->postJson('/api/v1/speech/speak', ['text' => 'Hello.', 'language' => 'en'])
            ->assertStatus(503);
    }
}
