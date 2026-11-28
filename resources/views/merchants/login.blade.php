@extends('layouts.site')

@section('title', __('portal.login.title'))

@section('content')

@php
    $input = 'w-full rounded-lg bg-white/[0.03] border border-white/15 px-3 py-2.5 text-sm text-white
              placeholder-gray-600 focus:border-brand-green focus:ring-1 focus:ring-brand-green outline-none';
    $label = 'block text-sm font-medium mb-1.5 text-gray-300';
@endphp

<section class="max-w-md mx-auto px-4 sm:px-6 py-16">
    <h1 class="text-3xl font-extrabold tracking-tight text-white">{{ __('portal.login.title') }}</h1>
    <p class="mt-2 text-gray-400">{{ __('portal.login.intro') }}</p>

    {{-- Where a page that sat open too long explains itself. Without this the
         redirect that replaced the blank 419 screen lands on a form that says
         nothing, which is the same dead end wearing a friendlier URL. --}}
    @if (session('status'))
        <p class="mt-6 rounded-lg bg-brand-orange/10 border border-brand-orange/30 text-brand-orange px-4 py-3 text-sm">
            {{ session('status') }}
        </p>
    @endif

    <form method="POST" action="{{ route('merchants.login.submit') }}"
          class="mt-8 rounded-2xl border border-white/10 bg-white/[0.02] p-6 sm:p-8 space-y-5">
        @csrf

        <div>
            <label for="identifier" class="{{ $label }}">{{ __('portal.fields.identifier') }}</label>
            <input id="identifier" name="identifier" value="{{ old('identifier') }}" required autofocus class="{{ $input }}">
            @error('identifier') <p class="mt-1 text-xs text-red-400">{{ $message }}</p> @enderror
        </div>

        <div>
            <label for="password" class="{{ $label }}">{{ __('portal.fields.password') }}</label>
            <input id="password" name="password" type="password" required class="{{ $input }}">
            @error('password') <p class="mt-1 text-xs text-red-400">{{ $message }}</p> @enderror
        </div>

        <label class="flex items-center gap-2 text-sm text-gray-400">
            <input type="checkbox" name="remember" value="1" class="rounded border-white/20 bg-transparent text-brand-green focus:ring-brand-green">
            {{ __('portal.login.remember') }}
        </label>

        <button type="submit" class="w-full py-3 rounded-lg bg-brand-green text-black font-bold hover:bg-lime-400 transition">
            {{ __('portal.login.submit') }}
        </button>

        {{--
            What to do when you cannot get in.

            Not a "forgot password" link, because a reset link needs mail and
            a reset code needs SMS, and neither is configured. A link that
            silently sent nothing would leave a restaurant waiting on an
            email instead of ringing us, and they would be shut for the
            evening before they worked out it was never coming.

            So it says the true thing: call, and somebody will set a
            temporary one. The number falls back to the contact page rather
            than rendering an empty sentence if SUPPORT_PHONE is unset.
        --}}
        @php
            $supportPhone = config('site.support.phone');
            $canReset = \App\Http\Controllers\Web\MerchantPasswordResetController::available();
        @endphp

        {{--
            A link when a code can actually be sent, and the phone number
            when it cannot.

            MAIL_MAILER defaults to `log`, which writes the message to a file
            on the server and reports success — so a link offered then would
            have a restaurant waiting all evening for a code that was never
            posted, instead of picking up the phone. The link appears the
            moment mail is configured and needs no release to do it.
        --}}
        @if ($canReset)
            <p class="text-sm text-center">
                <a href="{{ route('merchants.password.forgot') }}" class="text-brand-green font-semibold hover:underline">
                    {{ __('portal.login.reset_title') }}
                </a>
            </p>
        @else
            <p class="text-sm text-center text-gray-500">
                @if ($supportPhone)
                    {{ __('portal.login.forgot', ['phone' => $supportPhone]) }}
                @else
                    {{ __('portal.login.forgot_no_phone') }}
                @endif
            </p>
        @endif

        <p class="text-sm text-center text-gray-400">
            {{ __('portal.login.no_account') }}
            <a href="{{ route('merchants.register') }}" class="text-brand-green font-semibold hover:underline">{{ __('portal.register.title') }}</a>
        </p>
    </form>
</section>

@endsection
