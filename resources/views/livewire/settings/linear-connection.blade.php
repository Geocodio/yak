<section class="w-full">
    @include('partials.settings-heading')

    <x-settings.layout :heading="__('Linear')" :subheading="__('Connect Yak to your Linear workspace so comments and issue updates post as the Yak app.')">
        @if (session('linear_oauth_success'))
            <flux:callout variant="success" icon="check-circle" class="mb-4" heading="{{ session('linear_oauth_success') }}" />
        @endif

        @if (session('linear_oauth_error'))
            <flux:callout variant="danger" icon="exclamation-triangle" class="mb-4" heading="{{ session('linear_oauth_error') }}" />
        @endif

        @if (! $this->oauthConfigured)
            <flux:callout variant="warning" icon="exclamation-triangle" heading="OAuth is not configured">
                Set <code>YAK_LINEAR_OAUTH_CLIENT_ID</code>, <code>YAK_LINEAR_OAUTH_CLIENT_SECRET</code>, and
                <code>YAK_LINEAR_OAUTH_REDIRECT_URI</code> in the container's environment, then refresh this page.
            </flux:callout>
        @elseif ($this->isConnected)
            @php($conn = $this->connection())
            <div class="rounded-xl border border-yak-tan/40 bg-white p-5">
                <div class="flex items-start gap-4">
                    <div class="mt-1 flex size-10 shrink-0 items-center justify-center rounded-full bg-yak-green/15 text-yak-green">
                        <flux:icon.check-circle class="size-5" />
                    </div>
                    <div class="flex-1">
                        <div class="text-base font-medium text-yak-slate">Connected to {{ $conn->workspace_name }}</div>
                        <dl class="mt-2 grid grid-cols-1 gap-y-1 text-sm text-yak-blue sm:grid-cols-2">
                            <dt class="font-medium text-yak-slate">Workspace ID</dt>
                            <dd class="font-mono text-xs">{{ $conn->workspace_id }}</dd>

                            <dt class="font-medium text-yak-slate">Actor</dt>
                            <dd>{{ $conn->actor }}</dd>

                            <dt class="font-medium text-yak-slate">Scopes</dt>
                            <dd class="truncate">{{ is_array($conn->scopes) ? implode(', ', $conn->scopes) : '—' }}</dd>

                            <dt class="font-medium text-yak-slate">Access token expires</dt>
                            <dd>{{ $conn->expires_at?->diffForHumans() }}</dd>
                        </dl>
                    </div>
                </div>
                <div class="mt-4 flex items-center gap-3">
                    <flux:button variant="filled" href="{{ route('auth.linear.redirect') }}">
                        {{ __('Reconnect') }}
                    </flux:button>
                    <flux:button
                        variant="ghost"
                        wire:click="disconnect"
                        wire:confirm="Disconnect Linear? You'll need to reauthorize to post comments again."
                    >
                        {{ __('Disconnect') }}
                    </flux:button>
                </div>
            </div>

            @if ($conn->disconnected_at)
                <flux:callout variant="danger" icon="exclamation-triangle" class="mt-4" heading="Connection invalidated">
                    Linear rejected a token refresh on {{ $conn->disconnected_at->diffForHumans() }}. Reconnect above to resume posting comments.
                </flux:callout>
            @endif
        @else
            <div class="rounded-xl border border-yak-tan/40 bg-white p-5">
                <p class="text-sm text-yak-slate">
                    Linear is not connected yet. Click below to authorize Yak — comments and state updates will post as the Yak app rather than the connecting user.
                </p>
                <div class="mt-4">
                    <flux:button variant="primary" href="{{ route('auth.linear.redirect') }}">
                        {{ __('Connect Linear') }}
                    </flux:button>
                </div>
            </div>
        @endif
    </x-settings.layout>
</section>
