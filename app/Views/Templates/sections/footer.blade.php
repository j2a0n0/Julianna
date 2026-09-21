@dispatchEvent('beforeFooterOpen')

<footer class="footer" aria-label="{{ __('about.legal_navigation') }}" style="padding:18px 15px; color:var(--primary-font-color); opacity:.72;">
    @dispatchEvent('afterFooterOpen')
    <span>© {{ date('Y') }} Julianna · v{{ $version }}@if($commit !== '') · {{ substr($commit, 0, 8) }}@endif</span>
    <span aria-hidden="true"> · </span>
    <a href="{{ BASE_URL }}/help/about">{{ __('links.about') }}</a>
    <span aria-hidden="true"> · </span>
    <a href="{{ BASE_URL }}/help/about#license">{{ __('links.license') }}</a>
    <span aria-hidden="true"> · </span>
    <a href="{{ BASE_URL }}/help/about#notices">{{ __('links.notices') }}</a>
    <span aria-hidden="true"> · </span>
    <a href="{{ $sourceUrl !== '' ? $sourceUrl : BASE_URL.'/help/about#source' }}" @if($sourceUrl !== '') target="_blank" rel="noopener noreferrer" @endif>{{ __('links.source_code') }}</a>
    @dispatchEvent('beforeFooterClose')
</footer>

@dispatchEvent('afterFooter')
