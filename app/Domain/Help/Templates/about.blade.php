@extends($layout)

@section('content')
<div class="pageheader">
    <div class="pageicon"><span class="fa-solid fa-circle-info"></span></div>
    <div class="pagetitle">
        <h5>{{ __('about.product') }}</h5>
        <h1>{{ __('about.title') }}</h1>
    </div>
</div>

<div class="maincontent">
    <div class="maincontentinner">
        <div class="row">
            <div class="col-md-8">
                <div class="well">
                    <img src="{{ BASE_URL }}/dist/images/logo_blue.svg" alt="Julianna" style="width:260px; max-width:100%; margin-bottom:24px;">
                    <p>{{ __('about.summary') }}</p>

                    <dl style="margin-top:24px;">
                        <dt>{{ __('about.version') }}</dt>
                        <dd>v{{ $version }}</dd>
                        <dt>{{ __('about.commit') }}</dt>
                        <dd><code>{{ $commit !== '' ? $commit : __('about.not_available') }}</code></dd>
                    </dl>

                    <h2 id="source">{{ __('about.source_title') }}</h2>
                    <p>{{ __('about.source_body') }}</p>
                    @if ($sourceUrl !== '')
                        <p><a href="{{ $sourceUrl }}" target="_blank" rel="noopener noreferrer">{{ __('links.source_code') }} <span aria-hidden="true">↗</span></a></p>
                    @else
                        <p><strong>{{ __('about.source_not_configured') }}</strong></p>
                    @endif

                    <h2 id="license">{{ __('about.license_title') }}</h2>
                    <p>{{ __('about.license_body') }}</p>

                    <h2 id="notices">{{ __('about.notices_title') }}</h2>
                    <p>{{ __('about.notices_body') }}</p>
                    <p>{{ __('about.independence') }}</p>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
