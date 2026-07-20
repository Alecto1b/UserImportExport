<?php

namespace LeconfePlugins\UserImportExport\Services;

use App\Models\Role;
use App\Models\ScheduledConference;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use LeconfePlugins\UserImportExport\Support\UserYamlSchema;

class TeamYamlImport
{
    public function __construct(private readonly UserYamlSchema $schema) {}

    /**
     * @return array{rows: array<int, array<string, mixed>>, errors: int, new_users: int, existing_users: int, profile_fields: int, ready_roles: int, already_assigned: int}
     */
    public function preview(string $path, ScheduledConference $scheduledConference): array
    {
        $parsed = $this->schema->parse($path);
        if ($parsed['errors'] !== []) {
            return $this->invalidDocumentPreview($parsed['errors']);
        }

        $roles = $this->targetRoles($scheduledConference);
        $seenEmails = [];
        $rows = [];

        foreach ($parsed['records'] as $record) {
            $errors = $record['errors'];
            $email = $record['email'];

            if ($errors === [] && isset($seenEmails[$email])) {
                $errors[] = 'Email appears more than once in the YAML file.';
            }
            $seenEmails[$email] = true;

            if ($errors === []) {
                foreach ($record['roles'] as $roleName) {
                    if (! isset($roles[$roleName])) {
                        $errors[] = "Role {$roleName} is not available in the target scheduled conference.";
                    }
                }
            }

            $user = $errors === []
                ? User::query()->whereRaw('LOWER(email) = ?', [$email])->first()
                : null;

            if ($errors === [] && ! $user && blank($record['given_name'])) {
                $errors[] = 'Given name is required when creating a new user.';
            }

            if ($errors !== []) {
                $rows[] = $this->row($record, 'error', null, [], [], [], implode(' ', $errors));

                continue;
            }

            $profileFields = $this->profileFieldsToFill($user, $record);
            $metadata = $this->metadataToFill($user, $record['meta']);
            $readyRoles = [];
            $alreadyAssigned = [];

            foreach ($record['roles'] as $roleName) {
                $role = $roles[$roleName];
                if ($user && $this->hasAssignment($user, $role, $scheduledConference)) {
                    $alreadyAssigned[] = $roleName;
                } else {
                    $readyRoles[] = $roleName;
                }
            }

            $status = (! $user || $profileFields !== [] || $metadata !== [] || $readyRoles !== []) ? 'ready' : 'unchanged';
            $message = $status === 'ready' ? 'Ready to import.' : 'No changes are needed.';
            $rows[] = $this->row($record, $status, $user, $profileFields, $metadata, $readyRoles, $message, $alreadyAssigned);
        }

        return $this->withCounts($rows);
    }

    /**
     * @return array{created: int, existing: int, profile_fields_filled: int, roles_added: int, already_assigned: int}
     */
    public function import(string $path, ScheduledConference $scheduledConference): array
    {
        $preview = $this->preview($path, $scheduledConference);
        if ($preview['errors'] > 0) {
            throw ValidationException::withMessages([
                'importData.file' => 'Fix every invalid YAML user before importing.',
            ]);
        }

        $roles = $this->targetRoles($scheduledConference);

        return DB::transaction(function () use ($preview, $roles, $scheduledConference): array {
            $summary = [
                'created' => 0,
                'existing' => 0,
                'profile_fields_filled' => 0,
                'roles_added' => 0,
                'already_assigned' => 0,
            ];

            foreach ($preview['rows'] as $row) {
                $record = $row['record'];
                $user = User::query()
                    ->whereRaw('LOWER(email) = ?', [$record['email']])
                    ->lockForUpdate()
                    ->first();

                if (! $user) {
                    $user = User::create([
                        'email' => $record['email'],
                        'given_name' => $record['given_name'],
                        'family_name' => $record['family_name'],
                        'password' => Hash::make(Str::random(64)),
                    ]);
                    $user->setAttribute('public_name', $record['public_name']);
                    $user->save();
                    $summary['created']++;
                } else {
                    $summary['existing']++;
                    $summary['profile_fields_filled'] += $this->fillBlankProfileFields($user, $record);
                }

                $metadata = $this->metadataToFill($user, $record['meta']);
                if ($metadata !== []) {
                    $user->setManyMeta($metadata);
                    $summary['profile_fields_filled'] += count($metadata);
                }

                foreach ($record['roles'] as $roleName) {
                    $role = $roles[$roleName];
                    if ($this->hasAssignment($user, $role, $scheduledConference)) {
                        $summary['already_assigned']++;

                        continue;
                    }

                    $user->assignRole($role);
                    $summary['roles_added']++;
                }
            }

            return $summary;
        });
    }

    /**
     * @return array<string, Role>
     */
    private function targetRoles(ScheduledConference $scheduledConference): array
    {
        return Role::withoutGlobalScopes()
            ->where('conference_id', $scheduledConference->conference_id)
            ->where('scheduled_conference_id', $scheduledConference->getKey())
            ->get()
            ->keyBy('name')
            ->all();
    }

    /**
     * @param  array<string, mixed>  $record
     * @return array<int, string>
     */
    private function profileFieldsToFill(?User $user, array $record): array
    {
        if (! $user) {
            return array_keys(array_filter(Arr::only($record, ['given_name', 'family_name', 'public_name']), fn ($value): bool => filled($value)));
        }

        return collect(['given_name', 'family_name', 'public_name'])
            ->filter(fn (string $field): bool => blank($user->getAttribute($field)) && filled($record[$field]))
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @return array<string, mixed>
     */
    private function metadataToFill(?User $user, array $metadata): array
    {
        if (! $user) {
            return $metadata;
        }

        return collect($metadata)
            ->filter(fn ($value, string $key): bool => blank($user->getMeta($key)) && filled($value))
            ->all();
    }

    /**
     * @param  array<string, mixed>  $record
     * @param  array<int, string>  $profileFields
     * @param  array<string, mixed>  $metadata
     * @param  array<int, string>  $readyRoles
     * @param  array<int, string>  $alreadyAssigned
     * @return array<string, mixed>
     */
    private function row(array $record, string $status, ?User $user, array $profileFields, array $metadata, array $readyRoles, string $message, array $alreadyAssigned = []): array
    {
        return [
            'index' => $record['index'],
            'email' => $record['email'],
            'user_status' => $user ? 'existing' : 'new',
            'status' => $status,
            'message' => $message,
            'profile_fields' => $profileFields,
            'metadata_keys' => array_keys($metadata),
            'ready_roles' => $readyRoles,
            'already_assigned_roles' => $alreadyAssigned,
            'record' => $record,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array{rows: array<int, array<string, mixed>>, errors: int, new_users: int, existing_users: int, profile_fields: int, ready_roles: int, already_assigned: int}
     */
    private function withCounts(array $rows): array
    {
        return [
            'rows' => $rows,
            'errors' => count(array_filter($rows, fn (array $row): bool => $row['status'] === 'error')),
            'new_users' => count(array_filter($rows, fn (array $row): bool => $row['status'] !== 'error' && $row['user_status'] === 'new')),
            'existing_users' => count(array_filter($rows, fn (array $row): bool => $row['status'] !== 'error' && $row['user_status'] === 'existing')),
            'profile_fields' => array_sum(array_map(fn (array $row): int => count($row['profile_fields']) + count($row['metadata_keys']), $rows)),
            'ready_roles' => array_sum(array_map(fn (array $row): int => count($row['ready_roles']), $rows)),
            'already_assigned' => array_sum(array_map(fn (array $row): int => count($row['already_assigned_roles']), $rows)),
        ];
    }

    /**
     * @param  array<int, string>  $errors
     * @return array{rows: array<int, array<string, mixed>>, errors: int, new_users: int, existing_users: int, profile_fields: int, ready_roles: int, already_assigned: int}
     */
    private function invalidDocumentPreview(array $errors): array
    {
        return $this->withCounts([
            [
                'index' => 0,
                'email' => '',
                'user_status' => '-',
                'status' => 'error',
                'message' => implode(' ', $errors),
                'profile_fields' => [],
                'metadata_keys' => [],
                'ready_roles' => [],
                'already_assigned_roles' => [],
                'record' => [],
            ],
        ]);
    }

    private function fillBlankProfileFields(User $user, array $record): int
    {
        $fields = $this->profileFieldsToFill($user, $record);
        foreach ($fields as $field) {
            $user->setAttribute($field, $record[$field]);
        }

        if ($fields !== []) {
            $user->save();
        }

        return count($fields);
    }

    private function hasAssignment(User $user, Role $role, ScheduledConference $scheduledConference): bool
    {
        $table = config('permission.table_names.model_has_roles', 'model_has_roles');
        $rolePivotKey = config('permission.column_names.role_pivot_key') ?: 'role_id';
        $modelMorphKey = config('permission.column_names.model_morph_key', 'model_id');

        return DB::table($table)
            ->where($rolePivotKey, $role->getKey())
            ->where('conference_id', $scheduledConference->conference_id)
            ->where('scheduled_conference_id', $scheduledConference->getKey())
            ->where('model_type', User::class)
            ->where($modelMorphKey, $user->getKey())
            ->exists();
    }
}
