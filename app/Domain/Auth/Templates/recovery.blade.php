@extends($layout)

@section('content')
<div class="pageheader">
    <div class="pagetitle"><h1>{{ __('headlines.recovery_code') }}</h1></div>
</div>
<div class="regcontent">
    {!! $tpl->displayInlineNotification() !!}
    <p>{{ __('text.recovery_code_instructions') }}</p>
    <form action="{{ BASE_URL }}/auth/recovery" method="post" autocomplete="off">
        @csrf
        <label for="code">{{ __('label.recovery_code') }}</label>
        <x-global::forms.text-input name="code" id="code" autocomplete="one-time-code" value="" autofocus />
        <x-global::forms.button tag="input" inputType="submit" contentRole="primary" :labelText="__('buttons.verify')" />
    </form>
    <p><a href="{{ BASE_URL }}/auth/mfa">{{ __('links.use_authenticator_code') }}</a></p>
</div>
@endsection
