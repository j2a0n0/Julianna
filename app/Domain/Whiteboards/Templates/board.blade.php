@extends($layout)

@section('content')
@php
    $fr = str_starts_with((string) (session('usersettings.language') ?: session('companysettings.language') ?: 'en-US'), 'fr');
    $boardId = (int) $board['id'];
    $projectId = (int) $board['project_id'];
@endphp
<div class="pageheader">
    <div class="pageicon"><span class="fa fa-pen-ruler" aria-hidden="true"></span></div>
    <div class="pagetitle">
        <h5><a href="{{ BASE_URL }}/whiteboards/projects/{{ $projectId }}">{{ $fr ? 'Tableaux blancs' : 'Whiteboards' }}</a></h5>
        <h1>{{ $board['title'] }}</h1>
    </div>
</div>
<div class="maincontent julianna-whiteboard-page">
    <div class="maincontentinner">
        <div class="whiteboard-toolbar">
            <span id="whiteboard-save-status" role="status" aria-live="polite">{{ $fr ? 'Chargement…' : 'Loading…' }}</span>
            <span id="whiteboard-revision">{{ $fr ? 'Révision' : 'Revision' }} {{ (int) $board['revision'] }}</span>
            <button id="whiteboard-save" type="button" class="btn btn-primary" @unless($canEditBoard) disabled @endunless>{{ $fr ? 'Enregistrer' : 'Save' }}</button>
            <label for="whiteboard-history" class="sr-only">{{ $fr ? 'Historique des révisions' : 'Revision history' }}</label>
            <select id="whiteboard-history" @unless($canEditBoard) disabled @endunless>
                <option value="">{{ $fr ? 'Restaurer une révision…' : 'Restore a revision…' }}</option>
                @foreach ($revisions as $revision)
                    <option value="{{ (int) $revision['revision'] }}">{{ $fr ? 'Révision' : 'Revision' }} {{ (int) $revision['revision'] }} · {{ $revision['created_at'] }}</option>
                @endforeach
            </select>
            <button id="whiteboard-restore" type="button" class="btn btn-default" @unless($canEditBoard) disabled @endunless>{{ $fr ? 'Restaurer' : 'Restore' }}</button>
        </div>
        <div id="julianna-whiteboard-editor"
             data-board-id="{{ $boardId }}"
             data-board-url="{{ BASE_URL }}/whiteboards/{{ $boardId }}/scene"
             data-revisions-url="{{ BASE_URL }}/whiteboards/{{ $boardId }}/revisions"
             data-read-only="{{ $canEditBoard ? '0' : '1' }}"
             data-locale="{{ $fr ? 'fr-FR' : 'en' }}"
             data-label-saved="{{ $fr ? 'Enregistré' : 'Saved' }}"
             data-label-saving="{{ $fr ? 'Enregistrement…' : 'Saving…' }}"
             data-label-unsaved="{{ $fr ? 'Modifications non enregistrées' : 'Unsaved changes' }}"
             data-label-conflict="{{ $fr ? 'Ce tableau a changé ailleurs. Rechargez la page avant de modifier.' : 'This board changed elsewhere. Reload before editing.' }}"
             data-label-failed="{{ $fr ? 'Enregistrement impossible. Réessayez.' : 'Could not save. Please retry.' }}"
             data-label-revision="{{ $fr ? 'Révision' : 'Revision' }}"
             data-label-restore-confirm="{{ $fr ? 'Restaurer cette révision ? Les modifications actuelles resteront dans l’historique.' : 'Restore this revision? The current version will remain in history.' }}"
             data-label-readonly="{{ $fr ? 'Lecture seule' : 'Read only' }}"></div>
    </div>
</div>
@push('styles')
<link rel="stylesheet" href="{{ BASE_URL }}/dist/excalidraw/index.css">
<style>
.julianna-whiteboard-page .maincontentinner { max-width: none; padding: 0 16px 20px; }
.whiteboard-toolbar { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; margin: 6px 0 12px; }
.whiteboard-toolbar #whiteboard-save-status { margin-right: auto; }
.whiteboard-toolbar select { padding: 7px; background: var(--secondary-background); color: var(--primary-font-color); border: 1px solid var(--main-border-color); border-radius: 6px; }
#julianna-whiteboard-editor { width: 100%; height: min(75vh, 850px); min-height: 480px; border: 1px solid var(--main-border-color); border-radius: 10px; overflow: hidden; background: #fff; }
@media(max-width: 720px) { #julianna-whiteboard-editor { min-height: 440px; height: 68vh; } }
</style>
@endpush
@push('scripts')
<script>
window.EXCALIDRAW_ASSET_PATH = @json(rtrim(BASE_URL, '/').'/dist/excalidraw/');
window.JULIANNA_WHITEBOARD_ASSET_BASE = @json(rtrim(BASE_URL, '/').'/dist/');
</script>
<script defer src="{{ BASE_URL }}/dist/js/compiled-whiteboard.{{ config('version') }}.min.js"></script>
@endpush
@endsection
