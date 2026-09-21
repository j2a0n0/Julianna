@extends($layout)

@section('content')
<div class="pageheader">
    <div class="pagetitle"><h1>{{ __('headlines.create_julianna_account') }}</h1></div>
</div>

<div class="regcontent">
    {!! $tpl->displayInlineNotification() !!}

    @if ($registrationAvailable)
        <form action="{{ BASE_URL }}/auth/register" method="post" autocomplete="on">
            @csrf
            <div aria-hidden="true" style="position:absolute; left:-10000px; width:1px; height:1px; overflow:hidden;">
                <label for="website">Website</label>
                <input type="text" id="website" name="website" value="" tabindex="-1" autocomplete="off" />
            </div>

            <div>
                <label for="name">{{ __('label.name') }}</label>
                <x-global::forms.text-input name="name" id="name" autocomplete="name" value="{{ $values['name'] ?? '' }}" />
            </div>
            <div>
                <label for="email">{{ __('label.email') }}</label>
                <x-global::forms.text-input type="email" name="email" id="email" autocomplete="email" value="{{ $values['email'] ?? '' }}" />
            </div>
            <div>
                <label for="password">{{ __('label.password') }}</label>
                <x-global::forms.text-input type="password" name="password" id="password" autocomplete="new-password" value="" />
            </div>
            <div>
                <label for="password_confirmation">{{ __('label.confirm_password') }}</label>
                <x-global::forms.text-input type="password" name="password_confirmation" id="password_confirmation" autocomplete="new-password" value="" />
                <small>{{ __('text.julianna_password_requirements') }}</small>
            </div>

            <div style="margin-top:20px;">
                <x-global::forms.button tag="input" inputType="submit" contentRole="primary" :labelText="__('buttons.create_account')" />
            </div>
        </form>
    @else
        <p>{{ __('text.registration_unavailable') }}</p>
    @endif

    <p style="margin-top:20px;"><a href="{{ BASE_URL }}/auth/login">{{ __('links.back_to_login') }}</a></p>
</div>
@endsection
