<?php

namespace Leantime\Plugins\Databridge\Services;

use Illuminate\Support\Facades\Log;
use Leantime\Plugins\Databridge\Exceptions\InvalidApiKeyException;
use Leantime\Plugins\Databridge\Exceptions\OperationNotGrantedException;
use Leantime\Plugins\Databridge\Model\ApiUser;
use Leantime\Plugins\Databridge\Model\Operation;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * Loads Databridge API users from the auth YAML file and authenticates/authorizes requests.
 *
 * Fail-closed: any load or schema problem yields zero users (logged), so every request is
 * rejected with 401 rather than leaking a 500 or granting access.
 */
class ApiUsers
{
    /** @var ?list<array{key: string, user: ApiUser}> Per-request cache; keys stay out of ApiUser. */
    private ?array $entries = null;

    /**
     * Resolve a presented API key to a user.
     *
     * @throws InvalidApiKeyException When the key is missing, empty, or unknown.
     */
    public function authenticate(?string $presentedKey): ApiUser
    {
        $presentedKey = trim((string) $presentedKey);

        if ($presentedKey === '') {
            throw new InvalidApiKeyException('Missing API key');
        }

        $match = null;
        foreach ($this->getEntries() as $entry) {
            // Compare against every entry with hash_equals: constant time, no early return.
            if (hash_equals($entry['key'], $presentedKey)) {
                $match ??= $entry['user'];
            }
        }

        return $match ?? throw new InvalidApiKeyException('Unknown API key');
    }

    /**
     * Verify that the user holds the required operation grant.
     *
     * @throws OperationNotGrantedException
     */
    public function authorize(ApiUser $user, Operation $operation): void
    {
        if (! $user->can($operation)) {
            throw new OperationNotGrantedException($user->name, $operation);
        }
    }

    /**
     * @return list<array{key: string, user: ApiUser}>
     */
    private function getEntries(): array
    {
        return $this->entries ??= $this->loadEntries();
    }

    /**
     * Load and validate the auth YAML file.
     *
     * @return list<array{key: string, user: ApiUser}>
     */
    private function loadEntries(): array
    {
        $path = env('LEAN_DATABRIDGE_AUTH_FILE') ?: APP_ROOT.'/config/databridge_auth.yaml';

        if (! is_file($path) || ! is_readable($path)) {
            Log::error('Databridge auth: config file missing or unreadable — all requests will be rejected', ['path' => $path]);

            return [];
        }

        try {
            $data = Yaml::parseFile($path);
        } catch (ParseException $e) {
            Log::error('Databridge auth: YAML parse error — all requests will be rejected', ['path' => $path, 'message' => $e->getMessage()]);

            return [];
        }

        if (! is_array($data) || ! isset($data['users']) || ! is_array($data['users'])) {
            Log::error('Databridge auth: config must contain a "users" list — all requests will be rejected', ['path' => $path]);

            return [];
        }

        $entries = [];
        $seenKeys = [];
        $seenNames = [];

        foreach ($data['users'] as $index => $userData) {
            $entry = $this->validateUser(is_array($userData) ? $userData : [], (string) $index);

            if ($entry === null) {
                continue;
            }

            if (in_array($entry['key'], $seenKeys, true)) {
                Log::error('Databridge auth: skipping user with duplicate API key', ['name' => $entry['user']->name]);

                continue;
            }

            if (in_array($entry['user']->name, $seenNames, true)) {
                Log::warning('Databridge auth: duplicate user name in config', ['name' => $entry['user']->name]);
            }

            $seenKeys[] = $entry['key'];
            $seenNames[] = $entry['user']->name;
            $entries[] = $entry;
        }

        return $entries;
    }

    /**
     * Validate one user entry from the YAML file.
     *
     * A single invalid field invalidates the whole entry (fail loud: the user gets 401
     * immediately and the problem shows up in the log, instead of a grant being silently
     * dropped).
     *
     * @return ?array{key: string, user: ApiUser}
     */
    private function validateUser(array $userData, string $index): ?array
    {
        $name = is_string($userData['name'] ?? null) ? trim($userData['name']) : '';

        if ($name === '') {
            Log::error('Databridge auth: skipping user without a valid "name"', ['entry' => $index]);

            return null;
        }

        $key = is_string($userData['key'] ?? null) ? trim($userData['key']) : '';

        if ($key === '') {
            Log::error('Databridge auth: skipping user without a valid "key"', ['name' => $name]);

            return null;
        }

        $operationValues = $userData['operations'] ?? null;

        if (! is_array($operationValues) || $operationValues === []) {
            Log::error('Databridge auth: skipping user without a valid "operations" list', ['name' => $name]);

            return null;
        }

        $operations = [];
        foreach ($operationValues as $value) {
            $operation = is_string($value) ? Operation::tryFrom(strtolower(trim($value))) : null;

            if ($operation === null) {
                Log::error('Databridge auth: skipping user with unknown operation', [
                    'name' => $name,
                    'operation' => is_scalar($value) ? (string) $value : gettype($value),
                ]);

                return null;
            }

            if (! in_array($operation, $operations, true)) {
                $operations[] = $operation;
            }
        }

        return ['key' => $key, 'user' => new ApiUser($name, $operations)];
    }
}
