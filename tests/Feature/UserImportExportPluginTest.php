<?php

namespace LeconfePlugins\UserImportExport\Tests\Feature;

require_once __DIR__.'/../../index.php';

use App\Models\Conference;
use App\Models\Enums\UserRole;
use App\Models\Role;
use App\Models\ScheduledConference;
use App\Models\User;
use App\Providers\PanelProvider;
use Filament\Forms\Components\FileUpload;
use Filament\Panel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\View;
use LeconfePlugins\UserImportExport\Pages\UserImportExportPage;
use LeconfePlugins\UserImportExport\Services\TeamYamlExport;
use LeconfePlugins\UserImportExport\Services\TeamYamlImport;
use LeconfePlugins\UserImportExport\UserImportExportPlugin;
use Livewire\Livewire;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

class UserImportExportPluginTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        View::addNamespace('UserImportExport', dirname(__DIR__, 2).'/resources/views');
    }

    public function test_export_uses_one_yaml_user_entry_with_all_current_scheduled_conference_roles_and_metadata(): void
    {
        [$conference, $source, $target] = $this->scheduledConferenceContext();
        $reviewer = $this->user(['email' => 'reviewer@example.test', 'public_name' => 'Reviewer Public']);
        $foreignReviewer = $this->user(['email' => 'foreign@example.test']);
        $customRole = Role::withoutGlobalScopes()->create([
            'name' => 'Session Chair',
            'guard_name' => 'web',
            'conference_id' => $conference->getKey(),
            'scheduled_conference_id' => $source->getKey(),
        ]);

        $reviewer->setManyMeta([
            'department' => 'Program Committee',
            'preferences' => ['language' => 'id'],
        ]);
        $reviewer->assignRole($this->role($conference, $source, UserRole::Reviewer));
        $reviewer->assignRole($customRole);
        $foreignReviewer->assignRole($this->role($conference, $target, UserRole::Reviewer));

        $export = app(TeamYamlExport::class)->for($source);

        $this->assertSame(1, $export['document']['version']);
        $this->assertCount(1, $export['document']['users']);
        $exportedUser = $export['document']['users'][0];
        $this->assertSame('reviewer@example.test', $exportedUser['email']);
        $this->assertSame($reviewer->given_name, $exportedUser['given_name']);
        $this->assertSame($reviewer->family_name, $exportedUser['family_name']);
        $this->assertSame('Reviewer Public', $exportedUser['public_name']);
        $this->assertSame('Program Committee', $exportedUser['meta']['department']);
        $this->assertSame(['language' => 'id'], $exportedUser['meta']['preferences']);
        $this->assertSame(['Reviewer', 'Session Chair'], $exportedUser['roles']);

        $selectedExport = app(TeamYamlExport::class)->for($source, [$reviewer->getKey()]);
        $this->assertCount(1, $selectedExport['document']['users']);
        $this->assertSame('reviewer@example.test', $selectedExport['document']['users'][0]['email']);
    }

    public function test_import_creates_missing_users_and_only_fills_blank_existing_profile_data_without_email_or_invitation(): void
    {
        [$conference, $source, $target] = $this->scheduledConferenceContext();
        $existing = $this->user([
            'email' => 'existing@example.test',
            'given_name' => 'Keep',
            'family_name' => null,
            'public_name' => null,
        ]);
        $existing->setMeta('department', '');
        $existing->assignRole($this->role($conference, $source, UserRole::Reviewer));
        $customRole = Role::withoutGlobalScopes()->create([
            'name' => 'Session Chair',
            'guard_name' => 'web',
            'conference_id' => $conference->getKey(),
            'scheduled_conference_id' => $target->getKey(),
        ]);
        $file = $this->yaml([
            'version' => 1,
            'users' => [
                [
                    'email' => 'existing@example.test',
                    'given_name' => 'Do Not Replace',
                    'family_name' => 'Existing',
                    'public_name' => 'Existing Public',
                    'meta' => ['department' => 'Editorial', 'timezone' => 'Asia/Makassar'],
                    'roles' => [UserRole::Reviewer->value],
                ],
                [
                    'email' => 'new@example.test',
                    'given_name' => 'New',
                    'family_name' => 'User',
                    'public_name' => 'New User',
                    'meta' => ['department' => 'Program Committee'],
                    'roles' => [UserRole::Author->value, 'Session Chair'],
                ],
            ],
        ]);

        Mail::fake();
        $importer = app(TeamYamlImport::class);
        $result = $importer->import($file, $target);

        $new = User::query()->where('email', 'new@example.test')->firstOrFail();
        $existing->refresh();
        $this->assertSame(1, $result['created']);
        $this->assertSame(1, $result['existing']);
        $this->assertSame('Keep', $existing->given_name);
        $this->assertSame('Existing', $existing->family_name);
        $this->assertSame('Existing Public', $existing->public_name);
        $this->assertSame('Editorial', $existing->getMeta('department'));
        $this->assertSame('Asia/Makassar', $existing->getMeta('timezone'));
        $this->assertNotSame('', $new->password);
        $this->assertTrue($this->hasAssignment($existing, $this->role($conference, $target, UserRole::Reviewer), $target));
        $this->assertTrue($this->hasAssignment($new, $this->role($conference, $target, UserRole::Author), $target));
        $this->assertTrue($this->hasAssignment($new, $customRole, $target));
        $this->assertTrue($this->hasAssignment($existing, $this->role($conference, $source, UserRole::Reviewer), $source));
        $this->assertDatabaseCount('user_invitations', 0);
        Mail::assertNothingSent();

        $secondResult = $importer->import($file, $target);
        $this->assertSame(0, $secondResult['created']);
        $this->assertSame(0, $secondResult['profile_fields_filled']);
        $this->assertSame(0, $secondResult['roles_added']);
    }

    public function test_invalid_yaml_records_block_every_write(): void
    {
        [$conference, $source, $target] = $this->scheduledConferenceContext();
        $existing = $this->user(['email' => 'existing@example.test']);
        $file = $this->yaml([
            'version' => 1,
            'users' => [
                [
                    'email' => 'existing@example.test',
                    'given_name' => 'Existing',
                    'meta' => [],
                    'roles' => [UserRole::Reviewer->value],
                ],
                [
                    'email' => 'existing@example.test',
                    'given_name' => 'Duplicate',
                    'meta' => [],
                    'roles' => [],
                ],
                [
                    'email' => 'invalid-email',
                    'given_name' => 'Invalid',
                    'meta' => 'not-an-object',
                    'roles' => [UserRole::Author->value],
                ],
                [
                    'email' => 'new@example.test',
                    'given_name' => null,
                    'meta' => [],
                    'roles' => [],
                ],
            ],
        ]);

        $preview = app(TeamYamlImport::class)->preview($file, $target);
        $this->assertGreaterThan(0, $preview['errors']);
        $this->expectException(\Illuminate\Validation\ValidationException::class);

        try {
            app(TeamYamlImport::class)->import($file, $target);
        } finally {
            $this->assertFalse($this->hasAssignment($existing, $this->role($conference, $target, UserRole::Reviewer), $target));
            $this->assertNull(User::query()->where('email', 'new@example.test')->first());
        }
    }

    public function test_malformed_yaml_and_tampered_global_admin_role_are_rejected(): void
    {
        [$conference, $source, $target] = $this->scheduledConferenceContext();
        $user = $this->user(['email' => 'user@example.test']);
        $adminRole = Role::withoutGlobalScopes()->create([
            'name' => UserRole::Admin->value,
            'guard_name' => 'web',
            'conference_id' => 0,
            'scheduled_conference_id' => 0,
        ]);
        $malformed = tempnam(sys_get_temp_dir(), 'user-import-');
        file_put_contents($malformed, 'version: [');
        $tampered = $this->yaml([
            'version' => 1,
            'users' => [[
                'email' => 'user@example.test',
                'given_name' => 'User',
                'meta' => [],
                'roles' => [UserRole::Admin->value],
            ]],
        ]);

        $importer = app(TeamYamlImport::class);
        $this->assertSame(1, $importer->preview($malformed, $target)['errors']);
        $this->assertSame(1, $importer->preview($tampered, $target)['errors']);
        $this->assertFalse($user->hasRole($adminRole));
    }

    public function test_only_global_admin_can_access_the_plugin_page(): void
    {
        $admin = $this->user();
        $nonAdmin = $this->user();
        $adminRole = $this->globalAdminRole();
        $admin->assignRole($adminRole);

        $this->actingAs($admin);
        $this->assertTrue(UserImportExportPage::canAccess());
        $this->assertFalse(UserImportExportPage::shouldRegisterNavigation());

        $this->actingAs($nonAdmin);
        $this->assertFalse(UserImportExportPage::canAccess());
    }

    public function test_export_table_lists_all_scoped_users_and_offers_yaml_import_and_export_actions(): void
    {
        [$conference, $source, $target] = $this->scheduledConferenceContext();
        $reviewer = $this->user(['email' => 'reviewer@example.test']);
        $author = $this->user(['email' => 'author@example.test']);
        $foreignUser = $this->user(['email' => 'foreign@example.test']);
        $reviewer->assignRole($this->role($conference, $source, UserRole::Reviewer));
        $author->assignRole($this->role($conference, $source, UserRole::Author));
        $foreignUser->assignRole($this->role($conference, $target, UserRole::Participant));

        $admin = $this->user();
        $admin->assignRole($this->globalAdminRole());
        app()->setCurrentConferenceId($conference->getKey());
        app()->setCurrentScheduledConferenceId($source->getKey());
        $this->actingAs($admin);

        $table = Livewire::test(UserImportExportPage::class)
            ->assertFormFieldExists('file', 'importForm', fn (FileUpload $field): bool => in_array('application/octet-stream', $field->getAcceptedFileTypes(), true)
                && $field->getMaxSize() === config('media-library.max_file_size') / 1024)
            ->assertSee('YAML file')
            ->assertCanSeeTableRecords([$reviewer, $author])
            ->assertCanNotSeeTableRecords([$foreignUser])
            ->assertTableActionExists('exportAll')
            ->assertTableBulkActionExists('exportSelected');

        $table
            ->searchTable('author@example.test')
            ->assertCanSeeTableRecords([$author])
            ->assertCanNotSeeTableRecords([$reviewer]);

        $table
            ->searchTable('')
            ->filterTable('role', [$this->role($conference, $source, UserRole::Reviewer)->getKey()])
            ->assertCanSeeTableRecords([$reviewer])
            ->assertCanNotSeeTableRecords([$author]);
    }

    public function test_preview_only_offers_confirmation_when_changes_are_ready(): void
    {
        [$conference, $source] = $this->scheduledConferenceContext();
        $admin = $this->user();
        $admin->assignRole($this->globalAdminRole());
        app()->setCurrentConferenceId($conference->getKey());
        app()->setCurrentScheduledConferenceId($source->getKey());
        $this->actingAs($admin);

        $unchangedPreview = [
            'rows' => [[
                'email' => 'existing@example.test',
                'user_status' => 'existing',
                'status' => 'unchanged',
                'message' => 'No changes are needed.',
                'profile_fields' => [],
                'metadata_keys' => [],
                'ready_roles' => [],
                'already_assigned_roles' => ['Reviewer'],
            ]],
            'errors' => 0,
            'new_users' => 0,
            'existing_users' => 1,
            'profile_fields' => 0,
            'ready_roles' => 0,
            'already_assigned' => 1,
        ];

        Livewire::test(UserImportExportPage::class)
            ->set('preview', $unchangedPreview)
            ->assertSee('No changes to import')
            ->assertDontSee('Import 1 user');

        $readyPreview = $unchangedPreview;
        $readyPreview['rows'][0]['status'] = 'ready';
        $readyPreview['rows'][0]['ready_roles'] = ['Reviewer'];
        $readyPreview['ready_roles'] = 1;

        Livewire::test(UserImportExportPage::class)
            ->set('preview', $readyPreview)
            ->assertSee('1 user is ready to import')
            ->assertSee('Import 1 user');
    }

    public function test_plugin_registers_its_page_only_on_the_scheduled_conference_panel(): void
    {
        $plugin = new UserImportExportPlugin;
        $scheduledConferencePanel = Panel::make()->id(PanelProvider::PANEL_SCHEDULED_CONFERENCE);
        $conferencePanel = Panel::make()->id(PanelProvider::PANEL_CONFERENCE);

        $plugin->onPanel($scheduledConferencePanel);
        $plugin->onPanel($conferencePanel);

        $this->assertContains(UserImportExportPage::class, $scheduledConferencePanel->getPages());
        $this->assertNotContains(UserImportExportPage::class, $conferencePanel->getPages());
    }

    public function test_plugin_ships_a_scoped_native_stylesheet(): void
    {
        $stylesheet = dirname(__DIR__, 2).'/public/css/user-import-export.css';
        $source = dirname(__DIR__, 2).'/resources/css/user-import-export.css';

        $this->assertFileExists($stylesheet);
        $this->assertSame(file_get_contents($source), file_get_contents($stylesheet));
        $this->assertStringContainsString('.uie-summary', (string) file_get_contents($stylesheet));
        $this->assertStringNotContainsString('@tailwind', (string) file_get_contents($stylesheet));
    }

    /**
     * @return array{Conference, ScheduledConference, ScheduledConference}
     */
    private function scheduledConferenceContext(): array
    {
        $conference = Conference::factory()->create();
        $source = ScheduledConference::factory()->create(['conference_id' => $conference->getKey()]);
        $target = ScheduledConference::factory()->create(['conference_id' => $conference->getKey()]);

        foreach ([$source, $target] as $scheduledConference) {
            foreach (UserRole::scheduledConferenceRoles() as $role) {
                Role::withoutGlobalScopes()->firstOrCreate([
                    'name' => $role->value,
                    'guard_name' => 'web',
                    'conference_id' => $conference->getKey(),
                    'scheduled_conference_id' => $scheduledConference->getKey(),
                ]);
            }
        }

        return [$conference, $source, $target];
    }

    private function globalAdminRole(): Role
    {
        return Role::withoutGlobalScopes()->firstOrCreate([
            'name' => UserRole::Admin->value,
            'guard_name' => 'web',
            'conference_id' => 0,
            'scheduled_conference_id' => 0,
        ]);
    }

    private function role(Conference $conference, ScheduledConference $scheduledConference, UserRole $role): Role
    {
        return Role::withoutGlobalScopes()
            ->where('conference_id', $conference->getKey())
            ->where('scheduled_conference_id', $scheduledConference->getKey())
            ->where('name', $role->value)
            ->firstOrFail();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function user(array $attributes = []): User
    {
        return User::factory()->create([
            'password' => Hash::make('password12345'),
            ...$attributes,
        ]);
    }

    /**
     * @param  array<string, mixed>  $document
     */
    private function yaml(array $document): string
    {
        $path = tempnam(sys_get_temp_dir(), 'user-import-');
        file_put_contents($path, Yaml::dump($document, 8, 2));

        return $path;
    }

    private function hasAssignment(User $user, Role $role, ScheduledConference $scheduledConference): bool
    {
        return DB::table('model_has_roles')
            ->where('role_id', $role->getKey())
            ->where('conference_id', $scheduledConference->conference_id)
            ->where('scheduled_conference_id', $scheduledConference->getKey())
            ->where('model_type', User::class)
            ->where('model_id', $user->getKey())
            ->exists();
    }
}
