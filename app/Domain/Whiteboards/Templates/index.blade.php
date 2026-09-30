@extends($layout)

@section('content')
@php
    $fr = str_starts_with((string) (session('usersettings.language') ?: session('companysettings.language') ?: 'en-US'), 'fr');
    $projectId = (int) ($project['id'] ?? 0);
@endphp
<div class="pageheader">
    <div class="pageicon"><span class="fa fa-pen-ruler" aria-hidden="true"></span></div>
    <div class="pagetitle"><h5>{{ $project['name'] ?? '' }}</h5><h1>{{ $fr ? 'Tableaux blancs' : 'Whiteboards' }}</h1></div>
</div>
<div class="maincontent julianna-whiteboards-index" data-whiteboards-index data-project-id="{{ $projectId }}">
    <div class="maincontentinner">
        <p>{{ $fr ? 'Des espaces visuels partagés avec votre équipe, enregistrés dans Julianna.' : 'Shared visual spaces for your team, saved in Julianna.' }}</p>
        @if ($canCreateBoard)
            <form id="whiteboard-create-form" class="whiteboard-create" action="{{ BASE_URL }}/whiteboards/projects/{{ $projectId }}" method="post">
                @csrf
                <label for="whiteboard-title">{{ $fr ? 'Nom du tableau' : 'Board name' }}</label>
                <input id="whiteboard-title" name="title" type="text" required maxlength="160" placeholder="{{ $fr ? 'Nouveau tableau' : 'New whiteboard' }}">
                <button class="btn btn-primary" type="submit">{{ $fr ? 'Créer un tableau' : 'Create whiteboard' }}</button>
            </form>
        @endif
        <p id="whiteboard-create-error" role="alert" hidden></p>
        @if (count($boards) === 0)
            <div class="whiteboard-empty">{{ $fr ? 'Aucun tableau pour ce projet pour le moment.' : 'No whiteboards in this project yet.' }}</div>
        @else
            <div class="whiteboard-grid">
                @foreach ($boards as $board)
                    <a class="whiteboard-card" href="{{ BASE_URL }}/whiteboards/{{ (int) $board['id'] }}">
                        <span class="fa fa-pen-ruler" aria-hidden="true"></span>
                        <strong>{{ $board['title'] }}</strong>
                        <small>{{ $fr ? 'Révision' : 'Revision' }} {{ (int) $board['revision'] }}</small>
                    </a>
                @endforeach
            </div>
        @endif
    </div>
</div>
@push('styles')
<style>
.julianna-whiteboards-index .maincontentinner { max-width: 1180px; margin: 0 auto; }
.whiteboard-create { display: flex; align-items: end; flex-wrap: wrap; gap: 10px; margin: 22px 0; }
.whiteboard-create label { width: 100%; font-weight: 600; }
.whiteboard-create input { min-width: min(360px,100%); padding: 9px 12px; border: 1px solid var(--main-border-color); border-radius: 8px; color: var(--primary-font-color); background: var(--secondary-background); }
.whiteboard-grid { display: grid; grid-template-columns: repeat(auto-fill,minmax(230px,1fr)); gap: 15px; margin-top: 18px; }
.whiteboard-card, .whiteboard-empty { padding: 24px; border: 1px solid var(--main-border-color); border-radius: 12px; background: var(--secondary-background); }
.whiteboard-card { display: grid; gap: 12px; color: var(--primary-font-color); text-decoration: none; }
.whiteboard-card:hover { border-color: var(--accent1); text-decoration: none; }
.whiteboard-card .fa { color: var(--accent1); font-size: 22px; }
.whiteboard-card small { opacity: .7; }
</style>
@endpush
@push('scripts')
<script>
(() => {
    const form = document.querySelector('#whiteboard-create-form');
    if (!form) return;
    form.addEventListener('submit', async (event) => {
        event.preventDefault();
        const error = document.querySelector('#whiteboard-create-error');
        const button = form.querySelector('button');
        button.disabled = true;
        error.hidden = true;
        try {
            const response = await fetch(form.action, {method: 'POST', credentials: 'same-origin', headers: {'Accept': 'application/json', 'X-CSRF-TOKEN': form.querySelector('[name="_token"]').value, 'Content-Type': 'application/json'}, body: JSON.stringify({title: form.querySelector('[name="title"]').value})});
            const result = await response.json();
            if (!response.ok) throw new Error(@json($fr ? 'Impossible de créer le tableau.' : 'Could not create the whiteboard.'));
            window.location.assign('{{ BASE_URL }}/whiteboards/' + result.id);
        } catch (failure) {
            error.textContent = failure.message || @json($fr ? 'Impossible de créer le tableau.' : 'Could not create the whiteboard.');
            error.hidden = false;
            button.disabled = false;
        }
    });
})();
</script>
@endpush
@endsection
