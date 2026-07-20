<?php

namespace LeconfePlugins\UserImportExport\Pages;

use App\Models\Enums\UserRole;
use App\Models\Role;
use App\Models\User;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Tables\Actions\Action;
use Filament\Tables\Actions\BulkAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\App;
use LeconfePlugins\UserImportExport\Services\TeamYamlExport;
use LeconfePlugins\UserImportExport\Services\TeamYamlImport;
use Symfony\Component\Yaml\Yaml;

class UserImportExportPage extends Page implements HasForms, HasTable
{
    use InteractsWithForms;
    use InteractsWithTable;

    protected static string $view = 'UserImportExport::pages.user-import-export';

    protected static ?string $navigationIcon = 'heroicon-o-arrow-path-rounded-square';

    protected static ?int $navigationSort = 90;

    /** @var array<string, mixed> */
    public array $importData = [];

    public string $activeTab = 'export';

    /** @var array<string, mixed> */
    public array $preview = [];

    /** @var array<string, int>|null */
    public ?array $summary = null;

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);

        $this->importForm->fill();
    }

    public static function getNavigationLabel(): string
    {
        return 'User Import / Export';
    }

    public static function getNavigationGroup(): string
    {
        return 'Settings';
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->hasRole(UserRole::Admin->value) ?? false;
    }

    public static function shouldRegisterNavigation(): bool
    {
        return false;
    }

    protected function getForms(): array
    {
        return [
            'importForm',
        ];
    }

    public function importForm(Form $form): Form
    {
        return $form
            ->schema([
                FileUpload::make('file')
                    ->label('YAML file')
                    ->acceptedFileTypes([
                        'application/x-yaml',
                        'application/yaml',
                        'text/x-yaml',
                        'text/yaml',
                        'text/plain',
                        'application/octet-stream',
                    ])
                    ->helperText('Upload a UTF-8 YAML file.')
                    ->maxSize(config('media-library.max_file_size') / 1024)
                    ->rules(['mimes:yaml,yml'])
                    ->storeFiles(false)
                    ->required(),
            ])
            ->statePath('importData');
    }

    public function updatedImportData(): void
    {
        $this->preview = [];
        $this->summary = null;
    }

    public function table(Table $table): Table
    {
        return $table
            ->query($this->exportUsersQuery())
            ->columns([
                TextColumn::make('full_name')
                    ->label('User')
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query
                        ->where('given_name', 'like', "%{$search}%")
                        ->orWhere('family_name', 'like', "%{$search}%")),
                TextColumn::make('email')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('roles.name')
                    ->label('Roles')
                    ->badge(),
            ])
            ->filters([
                SelectFilter::make('role')
                    ->label('Role')
                    ->multiple()
                    ->options($this->roleOptions())
                    ->query(function (Builder $query, array $data): Builder {
                        $roleIds = $data['values'] ?? [];

                        if ($roleIds === []) {
                            return $query;
                        }

                        return $query->whereHas('roles', fn ($roles) => $this->scheduledConferenceRoles($roles)
                            ->whereIn('roles.id', $roleIds));
                    }),
            ])
            ->headerActions([
                Action::make('exportAll')
                    ->label('Export all users')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->authorize(fn (): bool => static::canAccess())
                    ->action(fn () => $this->exportAllUsers()),
            ])
            ->bulkActions([
                BulkAction::make('exportSelected')
                    ->label('Export selected users')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->authorize(fn (): bool => static::canAccess())
                    ->action(fn (Collection $records) => $this->exportSelectedUsers($records)),
            ])
            ->checkIfRecordIsSelectableUsing(fn (User $record): bool => filled($record->email));
    }

    public function downloadTemplate()
    {
        $this->ensureAdmin();

        return response()->streamDownload(function (): void {
            echo Yaml::dump([
                'version' => 1,
                'users' => [[
                    'email' => 'ani@example.com',
                    'given_name' => 'Ani',
                    'family_name' => 'Pratama',
                    'public_name' => 'Ani Pratama',
                    'meta' => [
                        'public_name' => 'Ani Pratama',
                    ],
                    'roles' => ['Reviewer'],
                ]],
            ], 8, 2);
        }, 'user-import-template.yaml', ['Content-Type' => 'application/x-yaml; charset=UTF-8']);
    }

    public function exportAllUsers()
    {
        $this->ensureAdmin();

        return $this->downloadExport();
    }

    public function exportSelectedUsers(Collection $records)
    {
        $this->ensureAdmin();

        return $this->downloadExport($records->modelKeys());
    }

    public function previewImport(): void
    {
        $this->ensureAdmin();
        $data = $this->importForm->getState();

        $this->preview = app(TeamYamlImport::class)->preview(
            $data['file']->getRealPath(),
            App::getCurrentScheduledConference(),
        );
        $this->summary = null;
    }

    public function confirmImport(): void
    {
        $this->ensureAdmin();
        $data = $this->importForm->getState();

        $this->summary = app(TeamYamlImport::class)->import(
            $data['file']->getRealPath(),
            App::getCurrentScheduledConference(),
        );
        $this->preview = [];
        $this->importForm->fill();

        Notification::make()
            ->success()
            ->title('Users imported.')
            ->body("{$this->summary['created']} users created and {$this->summary['roles_added']} role assignments added.")
            ->send();
    }

    private function downloadExport(?array $userIds = null)
    {
        $scheduledConference = App::getCurrentScheduledConference();
        $export = app(TeamYamlExport::class)->for($scheduledConference, $userIds);
        $filename = 'user-export-'.$scheduledConference->path.'.yaml';

        return response()->streamDownload(function () use ($export): void {
            echo Yaml::dump($export['document'], 8, 2);
        }, $filename, ['Content-Type' => 'application/x-yaml; charset=UTF-8']);
    }

    private function exportUsersQuery(): Builder
    {
        return User::query()
            ->with(['roles' => fn ($roles) => $this->scheduledConferenceRoles($roles)])
            ->whereHas('roles', fn ($roles) => $this->scheduledConferenceRoles($roles));
    }

    /**
     * @return array<int, string>
     */
    private function roleOptions(): array
    {
        return Role::withoutGlobalScopes()
            ->where('conference_id', App::getCurrentScheduledConference()->conference_id)
            ->where('scheduled_conference_id', App::getCurrentScheduledConferenceId())
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }

    private function scheduledConferenceRoles($roles)
    {
        $scheduledConference = App::getCurrentScheduledConference();

        return $roles
            ->withoutGlobalScopes()
            ->where('roles.conference_id', $scheduledConference->conference_id)
            ->where('roles.scheduled_conference_id', $scheduledConference->getKey());
    }

    private function ensureAdmin(): void
    {
        abort_unless(static::canAccess(), 403);
    }
}
