@extends('layouts.site')

@section('title', __('portal.login.reset_title'))

@section('content')
<section class="max-w-md mx-auto px-4 sm:px-6 py-16">

    <h1 class="text-3xl font-extrabold tracking-tight text-white">{{ __('portal.login.reset_code_title') }}</h1>
    <p class="mt-2 text-gray-400">{{ __('portal.login.reset_code_intro') }}</p>

    @if (session('status'))
        <p class="mt-6 rounded-lg bg-brand-green/10 border border-brand-green/30 px-4 py-3 text-sm text-brand-green">
            {{ session('status') }}
        </p>
    @endif

    @if ($errors->any())
        <div class="mt-6 rounded-lg bg-red-500/10 border border-red-500/30 text-red-300 px-4 py-3 text-sm space-y-1">
            @foreach ($errors->all() as $error)
                <div>{{ $error }}</div>
            @endforeach
        </div>
    @endif

    <form method="POST" action="{{ route('merchants.password.update') }}" class="mt-8 space-y-4">
        @csrf

        <div>
            <label for="email" class="block text-sm text-gray-400">{{ __('portal.login.reset_email') }}</label>
            <input id="email" name="email" type="email" required value="{{ old('email', $email) }}"
                   class="mt-1 w-full rounded-lg bg-black/40 border border-white/10 px-3 py-2.5 text-white">
        </div>

        {{-- inputmode numeric, so a phone shows digits rather than letters for
             a six digit code somebody is copying from another app. --}}
        <div>
            <label for="code" class="block text-sm text-gray-400">{{ __('portal.login.reset_code') }}</label>
            <input id="code" name="code" required autofocus inputmode="numeric" autocomplete="one-time-code"
                   maxlength="10"
                   class="mt-1 w-full rounded-lg bg-black/40 border border-white/10 px-3 py-2.5 text-white tracking-[0.3em] font-mono">
        </div>

        <div>
            <label for="password" class="block text-sm text-gray-400">{{ __('portal.profile.password_new') }}</label>
            <input id="password" name="password" type="password" required autocomplete="new-password"
                   class="mt-1 w-full rounded-lg bg-black/40 border border-white/10 px-3 py-2.5 text-white">
        </div>

        <div>
            <label for="password_confirmation" class="block text-sm text-gray-400">{{ __('portal.profile.password_repeat') }}</label>
            <input id="password_confirmation" name="password_confirmation" type="password" required autocomplete="new-password"
                   class="mt-1 w-full rounded-lg bg-black/40 border border-white/10 px-3 py-2.5 text-white">
        </div>

        <button type="submit" class="w-full py-3 rounded-lg bg-brand-green text-black font-bold hover:bg-lime-400 transition">
            {{ __('portal.login.reset_submit') }}
        </button>

        <p class="text-sm text-center">
            <a href="{{ route('merchants.password.forgot') }}" class="text-gray-400 hover:text-white">
                {{ __('portal.login.reset_resend') }}
            </a>
        </p>
    </form>
</section>
@endsection
