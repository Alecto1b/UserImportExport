<?php

namespace LeconfePlugins\UserImportExport\Pages;

use App\Models\Enums\UserRole;
use App\Models\Role;
use App\Models\User;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Schema;
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

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-arrow-path-rounded-square';

    protected static ?int $navigationSort = 90;

    protected string $view = 'UserImportExport::pages.user-import-export';

    public array $importData = [];

    public string $activeTab = 'export';

    public array $preview = [];

    public ?array $summary = null;

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
        $this->getForm('importForm')?->fill();
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
        return ['importForm'];
    }

    public function importForm(Schema $schema): Schema
    {
        return $schema->components([$this->fileField()])->statePath('importData');
    }

    protected function fileField(): FileUpload
    {
        return FileUpload::make('file')
            ->label('YAML file')
            ->acceptedFileTypes(['application/x-yaml', 'application/yaml', 'text/x-yaml', 'text/yaml', 'text/plain', 'application/octet-stream'])
            ->helperText('Upload a UTF-8 YAML file.')
            ->maxSize(config('media-library.max_file_size') / 1024)
            ->rules(['mimes:yaml,yml'])
            ->storeFiles(false)
            ->required();
    }

    public function updatedImportData(): void
    {
        $this->preview = [];
        $this->summary = null;
    }

    public function downloadTemplate()
    {
        $this->ensureAdmin();

        return response()->streamDownload(function (): void {
            echo Yaml::dump(['version' => 1, 'users' => [[
                'email' => 'ani@example.com', 'given_name' => 'Ani', 'family_name' => 'Pratama',
                'public_name' => 'Ani Pratama', 'meta' => ['public_name' => 'Ani Pratama'], 'roles' => ['Reviewer'],
            ]]], 8, 2);
        }, 'user-import-template.yaml', ['Content-Type' => 'application/x-yaml; charset=UTF-8']);
    }

    public function exportAllUsers()
    {
        $this->ensureAdmin();

        return $this->downloadExport();
    }

    public function exportSelectedUsers($records)
    {
        $this->ensureAdmin();

        return $this->downloadExport($records->modelKeys());
    }

    public function previewImport(): void
    {
        $this->ensureAdmin();
        $data = $this->getForm('importForm')->getState();
        $this->preview = app(TeamYamlImport::class)->preview($data['file']->getRealPath(), App::getCurrentScheduledConference());
        $this->summary = null;
    }

    public function confirmImport(): void
    {
        $this->ensureAdmin();
        $data = $this->getForm('importForm')->getState();
        $this->summary = app(TeamYamlImport::class)->import($data['file']->getRealPath(), App::getCurrentScheduledConference());
        $this->preview = [];
        $this->getForm('importForm')?->fill();
        Notification::make()->success()->title('Users imported.')
            ->body("{$this->summary['created']} users created and {$this->summary['roles_added']} role assignments added.")->send();
    }

    private function downloadExport(?array $userIds = null)
    {
        $scheduledConference = App::getCurrentScheduledConference();
        $export = app(TeamYamlExport::class)->for($scheduledConference, $userIds);

        return response()->streamDownload(function () use ($export): void {
            echo Yaml::dump($export['document'], 8, 2);
        }, 'user-export-'.$scheduledConference->path.'.yaml', ['Content-Type' => 'application/x-yaml; charset=UTF-8']);
    }

    public function table(Table $table): Table
    {
        return $table
            ->query($this->exportUsersQuery())
            ->columns($this->tableColumns())
            ->filters([$this->roleFilter()])
            ->headerActions([$this->exportAllAction()])
            ->bulkActions([$this->exportSelectedAction()])
            ->checkIfRecordIsSelectableUsing(fn (User $record): bool => filled($record->email));
    }

    protected function tableColumns(): array
    {
        return [
            TextColumn::make('full_name')
                ->label('User')
                ->searchable(query: fn (Builder $q, string $search): Builder => $q->where('given_name', 'like', "%{$search}%")->orWhere('family_name', 'like', "%{$search}%")),
            TextColumn::make('email')->searchable()->sortable(),
            TextColumn::make('roles.name')->label('Roles')->badge(),
        ];
    }

    protected function roleFilter(): SelectFilter
    {
        return SelectFilter::make('role')->relationship('roles', 'name', fn ($query) => $this->scheduledConferenceRoles($query))
            ->label('Role')
            ->multiple()
            ;
    }

    protected function exportAllAction(): Action
    {
        return Action::make('exportAll')
            ->label('Export all users')
            ->icon('heroicon-o-arrow-down-tray')
            ->authorize(fn (): bool => static::canAccess())
            ->action(fn () => $this->exportAllUsers());
    }

    protected function exportSelectedAction(): BulkAction
    {
        return BulkAction::make('exportSelected')
            ->label('Export selected users')
            ->icon('heroicon-o-arrow-down-tray')
            ->authorize(fn (): bool => static::canAccess())
            ->action(fn (Collection $records) => $this->exportSelectedUsers($records));
    }

    protected function exportUsersQuery(): Builder
    {
        return User::query()
            ->with(['roles' => fn ($roles) => $this->scheduledConferenceRoles($roles)])
            ->whereHas('roles', fn ($roles) => $this->scheduledConferenceRoles($roles));

    }

    protected function roleOptions(): array
    {
        return Role::withoutGlobalScopes()
            ->where('conference_id', App::getCurrentScheduledConference()->conference_id)
            ->where('scheduled_conference_id', App::getCurrentScheduledConferenceId())
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }

    protected function scheduledConferenceRoles($roles)
    {
        $scheduledConference = App::getCurrentScheduledConference();

        return $roles
            ->where('roles.conference_id', $scheduledConference->conference_id)
            ->where('roles.scheduled_conference_id', $scheduledConference->getKey());
    }

    protected function ensureAdmin(): void
    {
        abort_unless(static::canAccess(), 403);
    }
}
