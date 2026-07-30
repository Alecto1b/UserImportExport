<?php

namespace LeconfePlugins\UserImportExport\Pages;

use App\Models\Enums\UserRole;
use App\Models\Role;
use App\Models\User;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\App;
use LeconfePlugins\UserImportExport\Services\TeamYamlExport;
use LeconfePlugins\UserImportExport\Services\TeamYamlImport;
use Symfony\Component\Yaml\Yaml;

abstract class AbstractUserImportExportPage extends Page
{
    protected static ?int $navigationSort = 90;
    public array $importData = [];
    public string $activeTab = 'export';
    public array $preview = [];
    public ?array $summary = null;

    public function mount(): void { abort_unless(static::canAccess(), 403); $this->importForm->fill(); }
    public static function getNavigationLabel(): string { return 'User Import / Export'; }
    public static function getNavigationGroup(): string { return 'Settings'; }
    public static function canAccess(): bool { return auth()->user()?->hasRole(UserRole::Admin->value) ?? false; }
    public static function shouldRegisterNavigation(): bool { return false; }
    protected function getForms(): array { return ['importForm' => 'makeImportForm']; }
    public function updatedImportData(): void { $this->preview = []; $this->summary = null; }

    public function downloadTemplate()
    {
        $this->ensureAdmin();
        return response()->streamDownload(function (): void { echo Yaml::dump(['version' => 1, 'users' => [[
            'email' => 'ani@example.com', 'given_name' => 'Ani', 'family_name' => 'Pratama',
            'public_name' => 'Ani Pratama', 'meta' => ['public_name' => 'Ani Pratama'], 'roles' => ['Reviewer'],
        ]]], 8, 2); }, 'user-import-template.yaml', ['Content-Type' => 'application/x-yaml; charset=UTF-8']);
    }
    public function exportAllUsers() { $this->ensureAdmin(); return $this->downloadExport(); }
    public function exportSelectedUsers($records) { $this->ensureAdmin(); return $this->downloadExport($records->modelKeys()); }
    public function previewImport(): void
    {
        $this->ensureAdmin(); $data = $this->importForm->getState();
        $this->preview = app(TeamYamlImport::class)->preview($data['file']->getRealPath(), App::getCurrentScheduledConference());
        $this->summary = null;
    }
    public function confirmImport(): void
    {
        $this->ensureAdmin(); $data = $this->importForm->getState();
        $this->summary = app(TeamYamlImport::class)->import($data['file']->getRealPath(), App::getCurrentScheduledConference());
        $this->preview = []; $this->importForm->fill();
        Notification::make()->success()->title('Users imported.')
            ->body("{$this->summary['created']} users created and {$this->summary['roles_added']} role assignments added.")->send();
    }
    private function downloadExport(?array $userIds = null)
    {
        $scheduledConference = App::getCurrentScheduledConference(); $export = app(TeamYamlExport::class)->for($scheduledConference, $userIds);
        return response()->streamDownload(function () use ($export): void { echo Yaml::dump($export['document'], 8, 2); }, 'user-export-'.$scheduledConference->path.'.yaml', ['Content-Type' => 'application/x-yaml; charset=UTF-8']);
    }
    protected function exportUsersQuery(): Builder
    {
        return User::query()->with(['roles' => fn ($roles) => $this->scheduledConferenceRoles($roles)])->whereHas('roles', fn ($roles) => $this->scheduledConferenceRoles($roles));
    }
    protected function roleOptions(): array
    {
        return Role::withoutGlobalScopes()->where('conference_id', App::getCurrentScheduledConference()->conference_id)
            ->where('scheduled_conference_id', App::getCurrentScheduledConferenceId())->orderBy('name')->pluck('name', 'id')->all();
    }
    protected function scheduledConferenceRoles($roles)
    {
        $scheduledConference = App::getCurrentScheduledConference();
        return $roles->withoutGlobalScopes()->where('roles.conference_id', $scheduledConference->conference_id)->where('roles.scheduled_conference_id', $scheduledConference->getKey());
    }
    protected function ensureAdmin(): void { abort_unless(static::canAccess(), 403); }
}
