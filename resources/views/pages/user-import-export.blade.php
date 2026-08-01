<x-filament-panels::page>
    <div x-data="{ activeTab: $wire.entangle('activeTab').live }">
        <x-filament::tabs label="User transfer actions" class="uie-tabs">
            <x-filament::tabs.item
                alpine-active="activeTab === 'export'"
                aria-controls="user-export-panel"
                id="user-export-tab"
                x-bind:aria-selected="activeTab === 'export'"
                x-on:click="activeTab = 'export'"
                icon="heroicon-o-arrow-down-tray"
            >
                Export users
            </x-filament::tabs.item>
            <x-filament::tabs.item
                alpine-active="activeTab === 'import'"
                aria-controls="user-import-panel"
                id="user-import-tab"
                x-bind:aria-selected="activeTab === 'import'"
                x-on:click="activeTab = 'import'"
                icon="heroicon-o-arrow-up-tray"
            >
                Import users
            </x-filament::tabs.item>
        </x-filament::tabs>

        <div id="user-export-panel" role="tabpanel" aria-labelledby="user-export-tab" x-show="activeTab === 'export'" x-cloak>
            <p class="uie-export-help">Select users with the checkboxes to export only them, or use Export all users to include everyone.</p>
            {{ $this->table }}
        </div>

        <div id="user-import-panel" role="tabpanel" aria-labelledby="user-import-tab" x-show="activeTab === 'import'" x-cloak class="uie-import-content">
            <x-filament::section heading="Import user snapshot">
                <form wire:submit="previewImport" class="uie-import-form">
                    <p class="uie-copy">
                        Upload a UTF-8 YAML export from another scheduled conference. New users are created without email, while existing profiles are only filled when target values are blank.
                    </p>
                    <p class="uie-target">Import target: <strong>{{ app()->getCurrentScheduledConference()?->title }}</strong></p>

                    {{ $this->getForm('importForm') }}

                    <div class="uie-actions">
                        <x-filament::button type="submit" wire:loading.attr="disabled" wire:target="importData.file,previewImport" icon="heroicon-o-eye">
                            Preview import
                        </x-filament::button>
                        <x-filament::button type="button" wire:click="downloadTemplate" color="gray" outlined icon="heroicon-o-document-arrow-down">
                            View YAML example
                        </x-filament::button>
                    </div>
                </form>
            </x-filament::section>

            @if ($summary)
                <x-filament::section heading="Latest import">
                    <div class="uie-summary uie-summary--latest">
                        <div class="uie-summary-group"><p class="uie-summary-title">Users</p><p class="uie-summary-detail">{{ $summary['created'] }} created · {{ $summary['existing'] }} existing</p></div>
                        <div class="uie-summary-group"><p class="uie-summary-title">Applied changes</p><p class="uie-summary-detail">{{ $summary['profile_fields_filled'] }} profile fields · {{ $summary['roles_added'] }} roles</p></div>
                        <div class="uie-summary-group"><p class="uie-summary-title">Skipped</p><p class="uie-summary-detail">{{ $summary['already_assigned'] }} roles already assigned</p></div>
                    </div>
                </x-filament::section>
            @endif

            @if ($preview)
                @php
                    $readyUsers = collect($preview['rows'])->where('status', 'ready')->count();
                @endphp
                <x-filament::section heading="Import preview">
                    @if ($preview['errors'] > 0)
                        <div class="uie-import-status uie-import-status--error">
                            <p>Import blocked</p>
                            <span>Fix {{ $preview['errors'] }} validation {{ $preview['errors'] === 1 ? 'error' : 'errors' }} before importing.</span>
                        </div>
                    @elseif ($readyUsers === 0)
                        <div class="uie-import-status uie-import-status--neutral">
                            <p>No changes to import</p>
                            <span>All users in this YAML file are already up to date in the target scheduled conference.</span>
                        </div>
                    @else
                        <div class="uie-import-status uie-import-status--ready">
                            <p>{{ $readyUsers }} {{ $readyUsers === 1 ? 'user is' : 'users are' }} ready to import</p>
                            <span>Review the planned changes below before confirming.</span>
                        </div>
                    @endif

                    <div class="uie-summary">
                        <div class="uie-summary-group"><p class="uie-summary-title">Users</p><p class="uie-summary-detail">{{ $preview['new_users'] }} new · {{ $preview['existing_users'] }} existing</p></div>
                        <div class="uie-summary-group"><p class="uie-summary-title">Changes to apply</p><p class="uie-summary-detail">{{ $preview['profile_fields'] }} profile fields · {{ $preview['ready_roles'] }} roles</p></div>
                        <div class="uie-summary-group"><p class="uie-summary-title">Already up to date</p><p class="uie-summary-detail">{{ $preview['already_assigned'] }} roles already assigned</p></div>
                        <div class="uie-summary-group"><p class="uie-summary-title">Validation</p><p @class(['uie-summary-detail', 'uie-summary-detail--error' => $preview['errors'] > 0])>{{ $preview['errors'] }} errors</p></div>
                    </div>

                    <div class="uie-preview-table-wrap">
                        <table class="uie-preview-table">
                            <thead>
                                <tr>
                                    <th>User</th>
                                    <th>Changes</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($preview['rows'] as $row)
                                    @php
                                        $statusColor = match ($row['status']) {
                                            'ready' => 'primary',
                                            'error' => 'danger',
                                            default => 'gray',
                                        };
                                    @endphp
                                    <tr>
                                        <td>
                                            @if (filled($row['email']))
                                                <p class="uie-user-email">{{ $row['email'] }}</p>
                                            @endif
                                            <x-filament::badge :color="$row['user_status'] === 'new' ? 'success' : 'gray'" size="sm" class="uie-badge">
                                                {{ $row['user_status'] === 'new' ? 'New user' : ($row['user_status'] === 'existing' ? 'Existing user' : 'File error') }}
                                            </x-filament::badge>
                                        </td>
                                        <td class="uie-copy">
                                            @if ($row['profile_fields'] !== [] || $row['metadata_keys'] !== [])
                                                <p class="uie-change"><span>Fill profile:</span> {{ implode(', ', [...$row['profile_fields'], ...$row['metadata_keys']]) }}</p>
                                            @endif
                                            @if ($row['ready_roles'] !== [])
                                                <p @class(['uie-change', 'uie-change--spaced' => $row['profile_fields'] !== [] || $row['metadata_keys'] !== []])><span>Add roles:</span> {{ implode(', ', $row['ready_roles']) }}</p>
                                            @endif
                                            @if ($row['already_assigned_roles'] !== [])
                                                <p @class(['uie-change', 'uie-change--spaced' => $row['profile_fields'] !== [] || $row['metadata_keys'] !== [] || $row['ready_roles'] !== []])><span>Already assigned:</span> {{ implode(', ', $row['already_assigned_roles']) }}</p>
                                            @endif
                                            @if ($row['profile_fields'] === [] && $row['metadata_keys'] === [] && $row['ready_roles'] === [] && $row['already_assigned_roles'] === [] && $row['status'] !== 'error')
                                                <span>Nothing to change.</span>
                                            @endif
                                        </td>
                                        <td>
                                            <x-filament::badge :color="$statusColor" size="sm" class="uie-badge">
                                                {{ $row['status'] === 'unchanged' ? 'Up to date' : ucfirst($row['status']) }}
                                            </x-filament::badge>
                                            <p class="uie-status-message">{{ $row['message'] }}</p>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>

                    @if ($preview['errors'] === 0 && $readyUsers > 0)
                        <div class="uie-confirm-action">
                            <x-filament::button wire:click="confirmImport" wire:loading.attr="disabled" wire:target="confirmImport" color="success" icon="heroicon-o-check">
                                Import {{ $readyUsers }} {{ $readyUsers === 1 ? 'user' : 'users' }}
                            </x-filament::button>
                        </div>
                    @endif
                </x-filament::section>
            @endif
        </div>
    </div>
</x-filament-panels::page>
