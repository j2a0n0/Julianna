@extends($layout)

@section('content')
<div class="pageheader">
    <div class="pagetitle"><h1>{{ __('headlines.signup_approvals') }}</h1></div>
</div>

<div class="maincontent">
    {!! $tpl->displayInlineNotification() !!}

    @forelse ($accounts as $account)
        <section class="widget" style="margin-bottom:20px; padding:20px;">
            <h3>{{ $account['name'] }}</h3>
            <p>{{ $account['email'] }} · {{ $account['email_verified_at'] }}</p>

            @if (!empty($account['audit']))
                <details style="margin-bottom:16px;">
                    <summary>{{ __('label.audit_history') }}</summary>
                    <ul>
                        @foreach ($account['audit'] as $event)
                            <li><code>{{ $event['created_at'] }}</code> — {{ $event['event'] }}</li>
                        @endforeach
                    </ul>
                </details>
            @endif

            <form action="{{ BASE_URL }}/users/approvals" method="post">
                @csrf
                <input type="hidden" name="account_id" value="{{ $account['id'] }}" />
                <label for="role-{{ $account['id'] }}">{{ __('label.role') }}</label>
                <select id="role-{{ $account['id'] }}" name="role" required>
                    <option value="">{{ __('label.select_role') }}</option>
                    @foreach ($roles as $roleId => $roleName)
                        <option value="{{ $roleId }}">{{ ucfirst($roleName) }}</option>
                    @endforeach
                </select>

                @if (!empty($projects))
                    <label for="projects-{{ $account['id'] }}">{{ __('label.projects') }}</label>
                    <select id="projects-{{ $account['id'] }}" name="projects[]" multiple>
                        @foreach ($projects as $project)
                            <option value="{{ $project['id'] }}">{{ $project['name'] }}</option>
                        @endforeach
                    </select>
                @endif

                <div style="margin-top:16px;">
                    <x-global::forms.button tag="button" inputType="submit" name="action" value="approve" contentRole="primary" :labelText="__('buttons.approve')" />
                    <x-global::forms.button tag="button" inputType="submit" name="action" value="reject" state="danger" :labelText="__('buttons.reject')" />
                </div>
            </form>
        </section>
    @empty
        <p>{{ __('text.no_pending_signups') }}</p>
    @endforelse
</div>
@endsection
