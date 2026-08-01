<?php

namespace LeconfePlugins\UserImportExport\Support;

use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

class UserYamlSchema
{
    public const VERSION = 1;

    /** @var array<int, string> */
    private const ROOT_FIELDS = ['version', 'users'];

    /** @var array<int, string> */
    private const USER_FIELDS = ['email', 'given_name', 'family_name', 'public_name', 'meta', 'roles'];

    /**
     * @return array{records: array<int, array<string, mixed>>, errors: array<int, string>}
     */
    public function parse(string $path): array
    {
        if (! file_exists($path)) {
            return ['records' => [], 'errors' => ['File does not exist.']];
        }

        $maxSize = config('media-library.max_file_size', 10485760);
        if (filesize($path) > $maxSize) {
            return ['records' => [], 'errors' => ['YAML file is too large.']];
        }

        $contents = file_get_contents($path);

        if ($contents === false || ! mb_check_encoding($contents, 'UTF-8')) {
            return ['records' => [], 'errors' => ['YAML must be UTF-8 encoded.']];
        }

        try {
            $document = Yaml::parse($contents, Yaml::PARSE_EXCEPTION_ON_INVALID_TYPE);
        } catch (ParseException) {
            return ['records' => [], 'errors' => ['YAML is malformed.']];
        }

        if (! is_array($document) || array_is_list($document)) {
            return ['records' => [], 'errors' => ['YAML must contain a document object.']];
        }

        $unknownRootFields = array_diff(array_keys($document), self::ROOT_FIELDS);
        if ($unknownRootFields !== []) {
            return ['records' => [], 'errors' => ['YAML contains unsupported top-level fields.']];
        }

        if (($document['version'] ?? null) !== self::VERSION) {
            return ['records' => [], 'errors' => ['YAML version must be 1.']];
        }

        if (! isset($document['users']) || ! is_array($document['users']) || ! array_is_list($document['users'])) {
            return ['records' => [], 'errors' => ['YAML users must be a list.']];
        }

        $records = [];
        foreach ($document['users'] as $index => $user) {
            $records[] = $this->normalizeUser($user, $index + 1);
        }

        return ['records' => $records, 'errors' => []];
    }

    /**
     * @return array<string, mixed>
     */
    private function normalizeUser(mixed $user, int $index): array
    {
        $errors = [];
        if (! is_array($user) || array_is_list($user)) {
            return [
                'index' => $index,
                'email' => '',
                'errors' => ['User entry must be an object.'],
            ];
        }

        $unknownUserFields = array_diff(array_keys($user), self::USER_FIELDS);
        if ($unknownUserFields !== []) {
            $errors[] = 'User contains unsupported fields.';
        }

        $emailValue = $user['email'] ?? null;
        $email = is_string($emailValue) ? mb_strtolower(trim($emailValue)) : '';
        if (! is_string($emailValue) || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Email must be a valid address.';
        }

        $givenName = $this->nullableString($user['given_name'] ?? null, 'Given name', $errors);
        $familyName = $this->nullableString($user['family_name'] ?? null, 'Family name', $errors);
        $publicName = $this->nullableString($user['public_name'] ?? null, 'Public name', $errors);
        $metadata = $this->metadata($user['meta'] ?? [], $errors);
        $roles = $this->roles($user['roles'] ?? null, $errors);

        return [
            'index' => $index,
            'email' => $email,
            'given_name' => $givenName,
            'family_name' => $familyName,
            'public_name' => $publicName,
            'meta' => $metadata,
            'roles' => $roles,
            'errors' => $errors,
        ];
    }

    private function nullableString(mixed $value, string $label, array &$errors): ?string
    {
        if ($value === null) {
            return null;
        }

        if (! is_string($value)) {
            $errors[] = "{$label} must be a string.";

            return null;
        }

        return trim($value);
    }

    /**
     * @return array<string, mixed>
     */
    private function metadata(mixed $metadata, array &$errors): array
    {
        if (! is_array($metadata) || array_is_list($metadata)) {
            $errors[] = 'Meta must be an object.';

            return [];
        }

        foreach ($metadata as $key => $value) {
            if (! is_string($key) || blank($key)) {
                $errors[] = 'Meta keys must be non-empty strings.';

                continue;
            }

            if (! static::isSerializable($value)) {
                $errors[] = "Meta value for {$key} is not serializable.";
            }
        }

        return $metadata;
    }

    /**
     * @return array<int, string>
     */
    private function roles(mixed $roles, array &$errors): array
    {
        if (! is_array($roles) || ! array_is_list($roles)) {
            $errors[] = 'Roles must be a list.';

            return [];
        }

        $normalized = [];
        foreach ($roles as $role) {
            if (! is_string($role) || blank(trim($role))) {
                $errors[] = 'Every role must be a non-empty string.';

                continue;
            }

            $normalized[] = trim($role);
        }

        if (count($normalized) !== count(array_unique($normalized))) {
            $errors[] = 'Roles must not contain duplicates.';
        }

        return array_values(array_unique($normalized));
    }

    public static function isSerializable(mixed $value): bool
    {
        if ($value === null || is_scalar($value)) {
            return true;
        }

        if (! is_array($value)) {
            return false;
        }

        foreach ($value as $key => $nestedValue) {
            if (! is_int($key) && ! is_string($key)) {
                return false;
            }

            if (! static::isSerializable($nestedValue)) {
                return false;
            }
        }

        return true;
    }
}
