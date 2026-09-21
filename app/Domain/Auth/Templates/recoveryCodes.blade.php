@extends($layout)

@section('content')
<div class="pageheader">
    <div class="pagetitle"><h1>{{ __('headlines.recovery_codes') }}</h1></div>
</div>
<div class="regcontent">
    <p>{{ __('text.recovery_codes_once') }}</p>
    <ul id="recovery-codes" style="columns:2; font-family:monospace; font-size:1.1em;">
        @foreach ($recoveryCodes as $code)
            <li>{{ $code }}</li>
        @endforeach
    </ul>
    <form action="{{ BASE_URL }}/auth/recoveryCodes" method="post">
        @csrf
        <label><input type="checkbox" name="saved" value="1" required /> {{ __('label.recovery_codes_saved') }}</label>
        <div style="margin-top:20px;">
            <x-global::forms.button tag="input" inputType="submit" contentRole="primary" :labelText="__('buttons.continue')" />
        </div>
    </form>
</div>
@endsection
