<?php

/*
 * Self-check for the project scoping on GET /api/databridge/users.
 *
 * The rule this guards: a key must never learn about a user, or a user's project, outside
 * its own grant. That logic lives in SQL (DatabridgeRepository::getUsers) and in the
 * controller's grant check, so this cannot run without a database — it drives the real
 * endpoint over HTTP.
 *
 * Run against a local instance with two keys configured in databridge_auth.yaml, one
 * unrestricted and one scoped to a single project. Run it INSIDE the phpfpm container: the
 * host may carry an older PHP than the application targets, and the container cannot resolve
 * the Traefik hostname anyway — so it talks to nginx directly and sends the vhost as a Host
 * header.
 *
 *   docker compose exec \
 *     -e ALL_KEY=<key with projects: all> \
 *     -e SCOPED_KEY=<key with projects: [N]> \
 *     -e SCOPED_PROJECT=N \
 *     phpfpm php app/Plugins/Databridge/tests/users_scope_test.php
 *
 * BASE_URL and HOST_HEADER override the defaults for other setups.
 *
 * Uses plain conditionals rather than assert(): the PHP-FPM container ships
 * zend.assertions=-1, which compiles assertions out entirely.
 */

$baseUrl = getenv('BASE_URL') ?: 'http://nginx:8080';
$hostHeader = getenv('HOST_HEADER') ?: 'leantime.local.itkdev.dk';
$allKey = getenv('ALL_KEY');
$scopedKey = getenv('SCOPED_KEY');
$scopedProject = (int) (getenv('SCOPED_PROJECT') ?: 0);

if (! $allKey || ! $scopedKey || $scopedProject <= 0) {
    fwrite(STDERR, "users_scope_test: set ALL_KEY, SCOPED_KEY and SCOPED_PROJECT (see header).\n");
    exit(2);
}

$failures = [];

/**
 * Record a failure unless $actual matches $expected.
 */
function check(string $description, mixed $expected, mixed $actual): void
{
    global $failures;

    if ($expected !== $actual) {
        $failures[] = sprintf(
            '%s (expected %s, got %s)',
            $description,
            var_export($expected, true),
            var_export($actual, true)
        );
    }
}

/**
 * GET a Databridge path with the given key.
 *
 * TLS verification is off so the script still works if pointed at an https URL serving local
 * dev's self-signed certificate; this is a developer tool aimed at their own instance, never
 * part of a deployed request path.
 *
 * @return array{status:int, body:array<string, mixed>}
 */
function get(string $baseUrl, string $path, string $key): array
{
    global $hostHeader;

    $handle = curl_init($baseUrl.$path);
    curl_setopt_array($handle, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['x-api-key: '.$key, 'Host: '.$hostHeader],
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => 0,
    ]);

    $body = (string) curl_exec($handle);
    $status = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
    curl_close($handle);

    return ['status' => $status, 'body' => json_decode($body, true) ?: []];
}

// An unrestricted key sees users, and every row carries the fields a client needs to act.
$all = get($baseUrl, '/api/databridge/users', $allKey);
check('unrestricted key gets 200', 200, $all['status']);
check('unrestricted key sees at least one user', true, count($all['body']['results'] ?? []) > 0);

foreach ($all['body']['results'] ?? [] as $user) {
    foreach (['id', 'username', 'firstname', 'lastname', 'projects'] as $field) {
        if (! array_key_exists($field, $user)) {
            $failures[] = "user row is missing field '{$field}'";
        }
    }

    // A leaked credential field would be far worse than a missing one.
    foreach (['password', 'twoFASecret', 'session', 'pwReset'] as $secret) {
        if (array_key_exists($secret, $user)) {
            $failures[] = "user row exposes '{$secret}'";
        }
    }
}

// The scoped key must see only its own project, in every row's projects array.
$scoped = get($baseUrl, '/api/databridge/users', $scopedKey);
check('scoped key gets 200', 200, $scoped['status']);

$leakedProjects = [];
foreach ($scoped['body']['results'] ?? [] as $user) {
    foreach ($user['projects'] ?? [] as $projectId) {
        if ($projectId !== $scopedProject) {
            $leakedProjects[] = $projectId;
        }
    }
}
check('scoped key sees no project outside its grant', [], array_values(array_unique($leakedProjects)));

// Naming an ungranted project must be refused rather than answered.
$ungranted = $scopedProject + 1;
$refused = get($baseUrl, '/api/databridge/users?projectId='.$ungranted, $scopedKey);
check('ungranted projectId is refused with 403', 403, $refused['status']);
check('refusal carries no user rows', true, ! isset($refused['body']['results']));

// A key must not be able to widen its own scope by naming its granted project explicitly.
$narrowed = get($baseUrl, '/api/databridge/users?projectId='.$scopedProject, $scopedKey);
check('granted projectId is allowed', 200, $narrowed['status']);

if ($failures !== []) {
    fwrite(STDERR, "users_scope_test FAILED:\n");
    foreach ($failures as $failure) {
        fwrite(STDERR, "  - {$failure}\n");
    }
    exit(1);
}

echo "users_scope_test: all checks passed\n";
