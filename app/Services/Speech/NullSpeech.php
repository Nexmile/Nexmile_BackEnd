<?php

namespace App\Services\Speech;

use App\Contracts\SpeechProvider;
use Illuminate\Http\UploadedFile;

/**
 * No speech provider configured.
 *
 * Not a stub to be embarrassed about: the customer app recognises and speaks
 * on the handset already, so this is the product working as it ships. The
 * endpoints answer 503, the app uses Android's own engine, and the only
 * thing lost is the better Tamil.
 */
class NullSpeech implements SpeechProvider
{
    public function configured(): bool
    {
        return false;
    }

    public function transcribe(UploadedFile $audio, ?string $language = null): ?array
    {
        return null;
    }

    public function speak(string $text, string $language): ?string
    {
        return null;
    }
}
