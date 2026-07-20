<?php

namespace LeconfePlugins\UserImportExport\Services;

use App\Models\ScheduledConference;
use App\Models\User;
use Illuminate\Support\Collection;
use LeconfePlugins\UserImportExport\Support\UserYamlSchema;

class TeamYamlExport
{
    /**
     * @return array{document: array<string, mixed>, omitted_user_count: int, omitted_metadata_count: int}
     */
    public function for(ScheduledConference $scheduledConference, ?array $userIds = null): array
    {
        $users = $this->users($scheduledConference, $userIds);
        $omittedMetadataCount = 0;
        $usersWithoutEmail = $users->filter(fn (User $user): bool => blank($user->email))->count();

        $documentUsers = $users
            ->filter(fn (User $user): bool => filled($user->email))
            ->map(function (User $user) use (&$omittedMetadataCount): array {
                $metadata = [];
                foreach ($user->getAllMeta()->all() as $key => $value) {
                    if (! UserYamlSchema::isSerializable($value)) {
                        $omittedMetadataCount++;

                        continue;
                    }

                    $metadata[(string) $key] = $value;
                }

                ksort($metadata);

                return [
                    'email' => mb_strtolower(trim($user->email)),
                    'given_name' => $user->given_name,
                    'family_name' => $user->family_name,
                    'public_name' => $user->getAttribute('public_name'),
                    'meta' => $metadata,
                    'roles' => $user->roles->pluck('name')->sort()->values()->all(),
                ];
            })
            ->sortBy('email')
            ->values()
            ->all();

        return [
            'document' => [
                'version' => UserYamlSchema::VERSION,
                'users' => $documentUsers,
            ],
            'omitted_user_count' => $usersWithoutEmail,
            'omitted_metadata_count' => $omittedMetadataCount,
        ];
    }

    /**
     * @return Collection<int, User>
     */
    private function users(ScheduledConference $scheduledConference, ?array $userIds): Collection
    {
        $query = User::query()
            ->with(['roles' => fn ($roles) => $this->scheduledConferenceRoles($roles, $scheduledConference)])
            ->whereHas('roles', fn ($roles) => $this->scheduledConferenceRoles($roles, $scheduledConference))
            ->orderBy('email');

        if ($userIds !== null) {
            $query->whereKey(
                collect($userIds)
                    ->map(fn ($userId): int => (int) $userId)
                    ->filter()
                    ->unique()
                    ->all(),
            );
        }

        return $query->get();
    }

    private function scheduledConferenceRoles($roles, ScheduledConference $scheduledConference)
    {
        return $roles
            ->withoutGlobalScopes()
            ->where('roles.conference_id', $scheduledConference->conference_id)
            ->where('roles.scheduled_conference_id', $scheduledConference->getKey());
    }
}
