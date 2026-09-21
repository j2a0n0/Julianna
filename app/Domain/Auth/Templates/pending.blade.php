@extends($layout)

@section('content')
<div class="pageheader">
    <div class="pagetitle"><h1>{{ __('headlines.account_pending') }}</h1></div>
</div>
<div class="regcontent">
    {!! $tpl->displayInlineNotification() !!}
    <p>{{ __('text.account_pending_approval') }}</p>
    <p><a href="{{ BASE_URL }}/auth/login">{{ __('links.back_to_login') }}</a></p>
</div>
@endsection
