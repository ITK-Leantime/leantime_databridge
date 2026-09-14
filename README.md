# Databridge Plugin

A Leantime plugin that exposes API endpoints for retrieving tickets filtered by username (email) — with optional
date range and status filtering — and for creating tickets assigned to a user. Designed for consumption by remote
AI agents and external integrations.

## Installation

1. Place the `Databridge` folder in `app/Plugins/`
2. Go to **Settings > Plugins** in Leantime
3. Find "Databridge" under new plugins and click **Install**
4. Enable the plugin

Or via CLI:

```bash
php bin/leantime plugin:enable Databridge
```

## Authentication

The plugin uses its **own** API keys, defined in a YAML file — core Leantime API keys
(`lt_...`) are **not** accepted on these endpoints. The key is passed via the `x-api-key`
header.

### Setup

1. Copy `databridge_auth.sample.yaml` (in this plugin) to `<leantime>/config/databridge_auth.yaml`
   — next to Leantime's `.env`, **not** inside the plugin folder (deploys may overwrite it).
2. Generate a key per consumer: `openssl rand -base64 32`
3. Restrict access: `chmod 600 config/databridge_auth.yaml`. Never commit it.
4. Optionally override the file location in `config/.env`:
   `LEAN_DATABRIDGE_AUTH_FILE=/absolute/path/to/databridge_auth.yaml`

Each user entry defines a `name` (used in logs), a plaintext `key`, the granted
`operations`, and the granted `projects`. Changes apply on the next request — no cache
to clear.

```yaml
users:
  - name: reporting-agent
    key: "32+-random-chars"
    operations: [read]
    projects: [1, 5, 12]     # Leantime project IDs this key may access
  - name: sync-service
    key: "another-key"
    operations: [read, write]
    projects: all            # explicit sentinel: every project
```

### Operations

| Operation | Used by                                 |
| --------- | --------------------------------------- |
| `read`    | `GET /api/databridge/tickets`           |
| `write`   | `POST /api/databridge/tickets`          |
| `delete`  | Reserved for future endpoints           |

### POC limitations

- Keys are stored in plaintext (same trust level as the DB password in `config/.env`).
- No key expiry or rotation yet.

Failed authentication attempts are throttled: more than 20 failures per minute from the
same IP returns `429 Too Many Requests`. Both limits are configurable in `config/.env`
(a non-positive or non-numeric value falls back to the default):

- `LEAN_DATABRIDGE_RATELIMIT_ATTEMPTS` — failed attempts allowed per window (default `20`)
- `LEAN_DATABRIDGE_RATELIMIT_DECAY` — window length in seconds (default `60`)

## Endpoints

| Method  | Path                                     | Operation | Description                                 |
| ------- | ---------------------------------------- | --------- | ------------------------------------------- |
| `GET`   | `/api/databridge/tickets`                | `read`    | List tickets for a username                 |
| `POST`  | `/api/databridge/tickets`                | `write`   | Create a ticket for a username              |
| `GET`   | `/api/databridge/tickets/{id}`           | `read`    | Get a single ticket                         |
| `PATCH` | `/api/databridge/tickets/{id}`           | `write`   | Update selected fields of a ticket          |
| `GET`   | `/api/databridge/tickets/{id}/comments`  | `read`    | List a ticket's comments                    |
| `POST`  | `/api/databridge/tickets/{id}/comments`  | `write`   | Add a comment to a ticket                   |
| `GET`   | `/api/databridge/tickets/{id}/files`     | `read`    | List a ticket's attachments (metadata only) |
| `GET`   | `/api/databridge/projects`               | `read`    | List granted projects                       |
| `GET`   | `/api/databridge/projects/{id}/progress` | `read`    | Project completion percentage and dates     |
| `GET`   | `/api/databridge/projects/{id}/statuses` | `read`    | The project's status labels and types       |
| `GET`   | `/api/databridge/users`                  | `read`    | List users on granted projects              |
| `GET`   | `/api/databridge/milestones`             | `read`    | List milestones                             |
| `GET`   | `/api/databridge/timesheets`             | `read`    | List logged time entries                    |
| `POST`  | `/api/databridge/timesheets`             | `write`   | Log time against a ticket                   |

The `401` / `403` (operation) / `429` responses in [Error responses](#error-responses) apply to all of them.

Every endpoint is scoped to the calling key's granted projects: a request naming a project
outside the grant returns `403`, and list endpoints simply omit tickets from projects the key
cannot see. The project is always taken from the key's grants, never from a request parameter
alone.

> **No events or notifications fire on write endpoints.** Like `POST /tickets`, the write paths
> insert and update directly rather than going through core's services, which require a
> logged-in session user. Team members receive no email when an API key changes a ticket, logs
> time, or adds a comment. Each write is logged server-side with the key's name instead.

## Listing tickets (`GET /api/databridge/tickets`)

### Parameters

| Parameter  | Type   | Required | Default | Description                                                      |
| ---------- | ------ | -------- | ------- | ---------------------------------------------------------------- |
| `username` | string | Yes      |         | Email/username to filter tickets by                              |
| `dateFrom` | string | No       |         | ISO date (`Y-m-d`), filters `dateToFinish >=`                    |
| `dateTo`   | string | No       |         | ISO date (`Y-m-d`), filters `dateToFinish <=`                    |
| `status`   | string | No       |         | Status type: `NEW`, `INPROGRESS`, `DONE` (case-insensitive)      |
| `sinceId`  | int    | No       | 0       | Pagination cursor: minimum ticket ID (`id >=`), not a row offset |
| `limit`    | int    | No       | 100     | Maximum number of results                                        |

### Ticket matching

A ticket is returned if the user is either:

- The **assigned editor** of the ticket, or
- A **collaborator** on the ticket

Milestones are excluded.

### Project scoping

Results only include tickets from projects granted to the **API user** (the `projects`
key in the auth YAML). A grant listing a nonexistent project ID is legal and simply
yields empty results, not an error. Status filtering is equally scoped: the status
type (`NEW`/`INPROGRESS`/`DONE`) is resolved against granted projects only.

### Examples

#### Basic request

```bash
curl -k "https://leantime.example.com/api/databridge/tickets?username=user@example.com" \
  -H "x-api-key: YOUR_API_KEY"
```

#### With a result limit

```bash
curl -k "https://leantime.example.com/api/databridge/tickets?username=user@example.com&limit=50" \
  -H "x-api-key: YOUR_API_KEY"
```

#### With date filtering

```bash
curl -k "https://leantime.example.com/api/databridge/tickets?username=user@example.com&dateFrom=2026-01-01&dateTo=2026-12-31" \
  -H "x-api-key: YOUR_API_KEY"
```

#### With status filtering

```bash
curl -k "https://leantime.example.com/api/databridge/tickets?username=user@example.com&status=inprogress" \
  -H "x-api-key: YOUR_API_KEY"
```

#### Combined filters

```bash
curl -k "https://leantime.example.com/api/databridge/tickets?username=user@example.com&status=inprogress&dateFrom=2026-01-01&dateTo=2026-12-31&limit=10" \
  -H "x-api-key: YOUR_API_KEY"
```

#### Pagination

`sinceId` is a keyset cursor (minimum ticket ID), **not** a row offset. To fetch the next
page, pass the last returned ticket ID + 1:

```bash
# First page
curl -k "https://leantime.example.com/api/databridge/tickets?username=user@example.com&sinceId=0&limit=50" \
  -H "x-api-key: YOUR_API_KEY"

# Next page (use the last ticket ID + 1 from the previous response)
curl -k "https://leantime.example.com/api/databridge/tickets?username=user@example.com&sinceId=1051&limit=50" \
  -H "x-api-key: YOUR_API_KEY"
```

### Response format

```json
{
  "parameters": {
    "username": "user@example.com",
    "dateFrom": "2026-01-01",
    "dateTo": "2026-12-31",
    "status": null,
    "sinceId": 0,
    "limit": 100
  },
  "resultsCount": 3,
  "results": [
    {
      "id": 42,
      "projectId": 1,
      "name": "Fix login bug",
      "status": "INPROGRESS",
      "milestoneId": null,
      "tags": ["bug"],
      "worker": "user@example.com",
      "plannedHours": 4.0,
      "remainingHours": 2.0,
      "dueDate": "2026-03-15T00:00:00.000000Z",
      "resolutionDate": null,
      "modified": "2026-03-01T14:30:00.000000Z"
    }
  ]
}
```

Note: `plannedHours`/`remainingHours` may be `0` (UI-created tickets — core defaults empty
inputs to zero) or `null` (API-created tickets with no estimate).

## Creating a ticket (`POST /api/databridge/tickets`)

Creates a ticket in a granted project, assigned to the given username. Requires the
`write` operation. Body is JSON.

### Body fields

| Field | Type | Required | Default | Description |
| ----- | ---- | -------- | ------- | ----------- |
| `projectId` | int | Yes | | Target project; must be covered by the key's `projects` grant |
| `username` | string | Yes | | Assignee email; must have access to the target project; recorded as both the assignee and the creator |
| `name` | string | Yes | | Ticket headline (max 255 characters) |
| `description` | string | No | `""` | Ticket description (max 65535 bytes) |
| `dueDate` | string | No | none | `Y-m-d` or `Y-m-d H:i:s`, interpreted as **UTC**; a bare date gets a `00:00:00` time |
| `tags` | string[] | No | `[]` | Each tag non-empty and comma-free (comma is the storage delimiter); combined length max 255 |
| `plannedHours` | number | No | none | Planned hours, `0`–`100` (upper limit configurable, see below); also initializes remaining hours |
| `milestoneId` | int | No | none | Milestone to attach the ticket to; must be a milestone in the target project |

### Behavior

- **Project grant** — `403` if `projectId` is not covered by the key's `projects` grant.
  The grant is checked before existence, so an ungranted key cannot probe which project
  IDs exist.
- **Assignee must have project access** — the `username` user must be able to access the
  target project per Leantime's access model (admins/owners always; everyone for
  "accessible to everyone" projects; client users for client-scoped projects; directly
  assigned users otherwise). Otherwise `400` — a ticket assigned to someone who cannot
  see its project would be invisible to them.
- **No tickets in closed projects** — creating into a closed project ("Closed" in project
  settings; the projects board calls the same state "archive") returns `400`; Leantime
  hides closed projects from every listing, so the ticket would be invisible. Reading
  tickets from closed projects via `GET` still works (reporting/history).
- **No notifications, no events** — unlike in-app creation, this endpoint fires no Leantime
  events and sends no notification emails. This is by design: API-key requests have no
  session user to attribute the activity to.
- **Not idempotent** — each call creates a new ticket; retries create duplicates.
- **plannedHours cap** — values above `100` are rejected. Override the limit in
  `config/.env` via `LEAN_DATABRIDGE_MAX_PLANNED_HOURS` (a non-positive or non-numeric
  value falls back to the default of `100`).
- Tickets are created as type `task` with the project's first `NEW` status — the status is
  not client-settable. A fresh ticket's remaining hours equal its planned hours.

### Example

```bash
curl -k -X POST "https://leantime.example.com/api/databridge/tickets" \
  -H "x-api-key: YOUR_API_KEY" \
  -H "Content-Type: application/json" \
  -d '{
    "projectId": 1,
    "username": "user@example.com",
    "name": "Fix login bug",
    "description": "Users cannot log in with SSO",
    "dueDate": "2026-08-01",
    "tags": ["bug", "auth"],
    "plannedHours": 4
  }'
```

### Response (`201 Created`)

The created ticket is returned in the same shape as a list result:

```json
{
  "parameters": {
    "projectId": 1,
    "username": "user@example.com",
    "name": "Fix login bug",
    "description": "Users cannot log in with SSO",
    "dueDate": "2026-08-01",
    "tags": ["bug", "auth"],
    "plannedHours": 4.0,
    "milestoneId": null
  },
  "resultsCount": 1,
  "results": [
    {
      "id": 43,
      "projectId": 1,
      "name": "Fix login bug",
      "status": "NEW",
      "milestoneId": null,
      "tags": ["bug", "auth"],
      "worker": "user@example.com",
      "plannedHours": 4.0,
      "remainingHours": 4.0,
      "dueDate": "2026-08-01T00:00:00.000000Z",
      "resolutionDate": null,
      "modified": "2026-07-22T09:30:00.000000Z"
    }
  ]
}
```

## Updating a ticket (`PATCH /api/databridge/tickets/{id}`)

Only the fields present in the body are written; everything else is left untouched. Omitting a
field never clears it, so a caller can change one attribute without reading the ticket first.
`modified` is always bumped.

### Body fields

| Field            | Type            | Description                                                            |
| ---------------- | --------------- | ---------------------------------------------------------------------- |
| `name`           | string          | Ticket headline (max 255 characters)                                   |
| `description`    | string          | Free text                                                              |
| `status`         | string          | `NEW`, `INPROGRESS` or `DONE` (case-insensitive), resolved per project |
| `dueDate`        | string          | `Y-m-d` or `Y-m-d H:i:s`, interpreted as UTC                           |
| `plannedHours`   | number          | Estimate; `null` clears it                                             |
| `remainingHours` | number          | Remaining work; `null` clears it                                       |
| `tags`           | array of string | Replaces the whole tag list                                            |
| `milestoneId`    | integer         | Must be a milestone in the same project                                |
| `assignee`       | string          | Username (email) of the new assignee                                   |

Status is given as a **type**, not an integer, because status ids are configured per project and
can be relabelled — `DONE` resolves to whichever id that project uses.

```shell
curl -k -X PATCH "https://leantime.example.com/api/databridge/tickets/42" \
  -H "x-api-key: your-key" \
  -H "Content-Type: application/json" \
  -d '{"status":"done","plannedHours":6}'
```

Responds `200` with the updated ticket in the same shape as `GET /tickets/{id}`.

## Logging time (`POST /api/databridge/timesheets`)

| Field         | Type    | Required | Description                                         |
| ------------- | ------- | -------- | --------------------------------------------------- |
| `ticketId`    | integer | Yes      | Ticket to log against; must be in a granted project |
| `hours`       | number  | Yes      | Hours worked                                        |
| `workDate`    | string  | Yes      | `Y-m-d` or `Y-m-d H:i:s`, interpreted as UTC        |
| `username`    | string  | Yes      | Who the time belongs to (there is no session user)  |
| `description` | string  | No       | Free text                                           |
| `kind`        | string  | No       | Timesheet kind; defaults to Leantime's general kind |

```shell
curl -k -X POST "https://leantime.example.com/api/databridge/timesheets" \
  -H "x-api-key: your-key" \
  -H "Content-Type: application/json" \
  -d '{"ticketId":42,"hours":2.5,"workDate":"2026-08-11","username":"user@example.com"}'
```

Responds `201` with the created entry, or `409` when time is already logged for that
person, ticket, date and kind — Leantime enforces `UNIQUE (userId, ticketId, workDate, kind)`.

That makes this endpoint **safe to retry**: a client whose request timed out cannot tell
whether the write landed, and repeating it can never book the same work twice. To add hours to
a day that already has an entry, read it first and update rather than logging a second one.

## Adding a comment (`POST /api/databridge/tickets/{id}/comments`)

| Field      | Type   | Required | Description                               |
| ---------- | ------ | -------- | ----------------------------------------- |
| `text`     | string | Yes      | Comment body                              |
| `username` | string | Yes      | Comment author (there is no session user) |

Responds `201` with the created comment. Comments are returned oldest-first by
`GET /api/databridge/tickets/{id}/comments`.

## Attachments (`GET /api/databridge/tickets/{id}/files`)

Returns **metadata only** — id, filename, extension, uploader and upload date. File contents are
never returned; download the file through Leantime itself if the bytes are needed.

## Project endpoints

- `GET /api/databridge/projects` — the projects the key may access.
- `GET /api/databridge/projects/{id}/progress` — completion `percent` plus estimated and planned
  completion dates. Core renders the estimate as HTML for the UI; it is reduced to plain text here.
- `GET /api/databridge/projects/{id}/statuses` — each status int with its label, `statusType`
  (`NEW`/`INPROGRESS`/`DONE`) and whether it appears as a kanban column. Use this to map a status
  without hardcoding ids, which differ per project.

## Users (`GET /api/databridge/users`)

| Parameter   | Type    | Required | Description                              |
| ----------- | ------- | -------- | ---------------------------------------- |
| `projectId` | integer | No       | Restrict to one project; must be granted |

Lists active users assigned to the granted projects, so a client can resolve a person to the
`username` that the ticket and timesheet endpoints take — those identify a user by username
with no other way to discover one.

Each row carries `id`, `username`, `firstname`, `lastname`, `jobTitle`, `department` and the
`projects` the user is assigned to. No password, session or 2FA fields are exposed.

Scoping notes:

- `projects` lists only projects the **calling key** may access, so it never reveals that a
  user also works on a project the key cannot see.
- Membership means explicit assignment (`zp_relationuserproject`), not core's broader "may
  open this project" rule — admins are not listed under every project they can reach.
- Deactivated users and Leantime API service accounts (`source = api`) are excluded.

```shell
curl -H "x-api-key: $KEY" "https://leantime.example.com/api/databridge/users?projectId=42"
```

## Milestones (`GET /api/databridge/milestones`)

| Parameter   | Type    | Required | Description                              |
| ----------- | ------- | -------- | ---------------------------------------- |
| `projectId` | integer | No       | Restrict to one project; must be granted |

Milestones are stored as tickets of type `milestone`; this endpoint returns them with their
project, status type and due date.

## Error responses

Shared across all endpoints unless noted.

### Bad request (400)

Input is invalid; the `error` message states the problem. Examples:

- `The "username" parameter is required.` — GET, missing username
- `Request body must be a non-empty JSON object.` — POST, missing or non-JSON body
- `The "projectId" field is required and must be a positive integer.`
- `The "name" field is required.` / `The "name" field must not exceed 255 characters.`
- `Unknown "projectId".` / `Unknown "username".`
- `The given project is closed.`
- `The "username" user does not have access to the given project.`
- `The "dueDate" field must be a valid date in "Y-m-d" or "Y-m-d H:i:s" format (UTC).`
- `The "tags" field must be an array of non-empty strings without commas.`
- `The "plannedHours" field must be a number between 0 and 100.`
- `The "description" field must not exceed 65535 bytes.`
- `The "milestoneId" field must be a positive integer.`
- `Unknown "milestoneId" for the given project.`

```json
{"error": "The \"username\" parameter is required."}
```

### Invalid API key (401)

Returned for a missing, empty, or unknown key — and, fail-closed, when the auth YAML file
is missing or malformed, or when the user's entry is invalid (e.g. a missing or invalid
`projects` key; check the Leantime log). Also returned by Leantime core when the plugin
is disabled.

```json
{"error": "Invalid API Key"}
```

### Operation not granted (403)

The key is valid but its user lacks the operation the endpoint requires (e.g. a `read`-only
key on the `POST` endpoint).

```json
{"error": "Operation not permitted for this API key"}
```

Naming a project the key's `projects` grant does not cover gets a distinct `403`, on every
endpoint that takes a `projectId`:

```json
{"error": "Project not granted for this API key."}
```

### Unknown ticket (404)

Returned by the ticket-scoped endpoints when the ticket does not exist — and also when it
exists in a project the key is not granted. Deliberately the same answer for both: a
distinct `403` would tell an ungranted key which ticket IDs are real.

```json
{"error": "Unknown ticket."}
```

### Conflict (409)

The request is well-formed but the row already exists. Currently only
`POST /timesheets`, which Leantime constrains to one entry per person, ticket, date and kind:

```json
{"error": "Time is already logged for this person, todo, date and kind. Read the existing entries before retrying."}
```

### Too many failed attempts (429)

More than 20 failed authentications per minute from the same IP.

```json
{"error": "Too many failed authentication attempts. Try again later."}
```

## Development

Clone this repository into your Leantime plugins folder:

```shell
git clone https://github.com/ITK-Leantime/leantime_databridge.git app/Plugins/Databridge
```

Run composer install:

```shell name=development-install
docker run --interactive --rm --volume ${PWD}:/app itkdev/php8.3-fpm:latest composer install --no-security-blocking
```

### Composer normalize

```shell name=composer-normalize
docker run --rm --volume ${PWD}:/app itkdev/php8.3-fpm:latest composer normalize
```

### Coding standards

#### Check and apply with phpcs

```shell name=check-coding-standards
docker run --interactive --rm --volume ${PWD}:/app itkdev/php8.3-fpm:latest composer coding-standards-check
```

```shell name=apply-coding-standards
docker run --interactive --rm --volume ${PWD}:/app itkdev/php8.3-fpm:latest composer coding-standards-apply
```

#### Check and apply with prettier

```shell name=prettier-check
docker run --rm -v "$(pwd):/work" tmknom/prettier:latest --check assets
```

```shell name=prettier-apply
docker run --rm -v "$(pwd):/work" tmknom/prettier:latest --write assets
```

#### Check and apply markdownlint

```shell name=markdown-check
docker run --rm --volume "$PWD:/md" itkdev/markdownlint '**/*.md'
```

```shell name=markdown-apply
docker run --rm --volume "$PWD:/md" itkdev/markdownlint '**/*.md' --fix
```

#### Check with shellcheck

```shell name=shell-check
docker run --rm --volume "$PWD:/app" --workdir /app peterdavehello/shellcheck shellcheck bin/create-release
docker run --rm --volume "$PWD:/app" --workdir /app peterdavehello/shellcheck shellcheck bin/deploy
docker run --rm --volume "$PWD:/app" --workdir /app peterdavehello/shellcheck shellcheck bin/local.create-release
```

### Code analysis

```shell name=code-analysis
# This analysis takes a bit more than the default allocated ram.
docker run --interactive --rm --volume ${PWD}:/app --env PHP_MEMORY_LIMIT=256M itkdev/php8.3-fpm:latest composer code-analysis
```

## Test release build

```shell name=test-create-release
docker compose build && docker compose run --rm php bin/create-release dev-test
```

The create-release script replaces `%%VERSION%%` in
[register.php](https://github.com/ITK-Leantime/leantime_databridge/blob/main/register.php)
with the tag provided (in the above it is `dev-test`).

## Deploy

The deploy script downloads a [release](https://github.com/ITK-Leantime/leantime_databridge/releases) from Github and
unzips it. The script should be passed a tag as argument. In the process the script deletes itself, but the script
finishes because it [is still in memory](https://linux.die.net/man/3/unlink).
