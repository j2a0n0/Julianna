@extends($layout)

@section('content')
<div class="pageheader">
    <div class="pagetitle"><h1>{{ $enrollment ? __('headlines.mfa_setup') : __('headlines.mfa_challenge') }}</h1></div>
</div>
<div class="regcontent">
    {!! $tpl->displayInlineNotification() !!}

    @if ($enrollment)
        <p>{{ __('text.mfa_setup_instructions') }}</p>
        @if (!empty($qrData))
            <p style="text-align:center;"><img src="{{ $qrData }}" alt="{{ __('label.mfa_qr_code') }}" width="220" height="220" /></p>
        @endif
        <p><strong>{{ __('label.mfa_manual_key') }}:</strong> <code>{{ $secret }}</code></p>
    @else
        <p>{{ __('text.mfa_challenge_instructions') }}</p>
    @endif

    <form action="{{ BASE_URL }}/auth/mfa" method="post" autocomplete="off">
        @csrf
        <input type="hidden" name="mode" value="{{ $enrollment ? 'enroll' : 'challenge' }}" />
        <input type="hidden" name="redirectUrl" value="{{ $redirectUrl }}" />
        <div>
            <label for="code">{{ __('label.twoFACode') }}</label>
            <x-global::forms.text-input name="code" id="code" inputmode="numeric" autocomplete="one-time-code" value="" autofocus />
        </div>
        <x-global::forms.button tag="input" inputType="submit" contentRole="primary" :labelText="__('buttons.verify')" />
    </form>

    @if (!$enrollment)
        <p style="margin-top:16px;"><a href="{{ BASE_URL }}/auth/recovery">{{ __('links.use_recovery_code') }}</a></p>
    @endif
    <p><a href="{{ BASE_URL }}/auth/logout">{{ __('menu.sign_out') }}</a></p>
</div>
@endsection
