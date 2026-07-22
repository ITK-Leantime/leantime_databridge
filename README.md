# Databridge Plugin

A Leantime plugin that exposes API endpoints for retrieving tickets filtered by username (email) — with optional date range and status filtering — and for creating tickets assigned to a user. Designed for consumption by remote AI agents and external integrations.

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

| Operation | Used by |
|-----------|-----------------------------------------|
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

| Method | Path | Operation | Description |
|--------|------|-----------|--------------------------------|
| `GET`  | `/api/databridge/tickets` | `read`  | List tickets for a username |
| `POST` | `/api/databridge/tickets` | `write` | Create a ticket for a username |

The `401` / `403` (operation) / `429` responses in [Error responses](#error-responses) apply to both.

## Listing tickets (`GET /api/databridge/tickets`)

### Parameters

| Parameter  | Type   | Required | Default | Description                                      |
|------------|--------|----------|---------|--------------------------------------------------|
| `username` | string | Yes      |         | Email/username to filter tickets by               |
| `dateFrom` | string | No       |         | ISO date (`Y-m-d`), filters `dateToFinish >=`     |
| `dateTo`   | string | No       |         | ISO date (`Y-m-d`), filters `dateToFinish <=`     |
| `status`   | string | No       |         | Status type: `NEW`, `INPROGRESS`, `DONE` (case-insensitive) |
| `start`    | int    | No       | 0       | Pagination cursor: minimum ticket ID (`id >=`), not a row offset |
| `limit`    | int    | No       | 100     | Maximum number of results                         |

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

`start` is a keyset cursor (minimum ticket ID), **not** a row offset. To fetch the next
page, pass the last returned ticket ID + 1:

```bash
# First page
curl -k "https://leantime.example.com/api/databridge/tickets?username=user@example.com&start=0&limit=50" \
  -H "x-api-key: YOUR_API_KEY"

# Next page (use the last ticket ID + 1 from the previous response)
curl -k "https://leantime.example.com/api/databridge/tickets?username=user@example.com&start=1051&limit=50" \
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
    "start": 0,
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
|-------|------|----------|---------|-------------|
| `projectId` | int | Yes | | Target project; must be covered by the key's `projects` grant |
| `username` | string | Yes | | Assignee email; recorded as both the assignee and the creator |
| `name` | string | Yes | | Ticket headline (max 255 characters) |
| `description` | string | No | `""` | Ticket description (max 65535 bytes) |
| `dueDate` | string | No | none | `Y-m-d` or `Y-m-d H:i:s`, interpreted as **UTC**; a bare date gets a `00:00:00` time |
| `tags` | string[] | No | `[]` | Each tag non-empty and comma-free (comma is the storage delimiter); combined length max 255 |
| `plannedHours` | number | No | none | Planned hours, `0`–`100` (upper limit configurable, see below); also initializes remaining hours |
| `status` | string | No | project's first `NEW` status | `NEW`, `INPROGRESS`, or `DONE` (case-insensitive), resolved to a per-project status id |

### Behavior

- **Project grant** — `403` if `projectId` is not covered by the key's `projects` grant.
  The grant is checked before existence, so an ungranted key cannot probe which project
  IDs exist.
- **No notifications, no events** — unlike in-app creation, this endpoint fires no Leantime
  events and sends no notification emails. This is by design: API-key requests have no
  session user to attribute the activity to.
- **Not idempotent** — each call creates a new ticket; retries create duplicates.
- **plannedHours cap** — values above `100` are rejected. Override the limit in
  `config/.env` via `LEAN_DATABRIDGE_MAX_PLANNED_HOURS` (a non-positive or non-numeric
  value falls back to the default of `100`).
- Tickets are created as type `task`. A fresh ticket's remaining hours equal its planned
  hours.

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
    "plannedHours": 4,
    "status": "new"
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
    "status": "NEW"
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

## Error responses

Shared across both endpoints unless noted.

### Bad request (400)

Input is invalid; the `error` message states the problem. Examples:

- `The "username" parameter is required.` — GET, missing username
- `Request body must be a non-empty JSON object.` — POST, missing or non-JSON body
- `The "projectId" field is required and must be a positive integer.`
- `The "name" field is required.` / `The "name" field must not exceed 255 characters.`
- `Unknown "projectId".` / `Unknown "username".`
- `The "dueDate" field must be a valid date in "Y-m-d" or "Y-m-d H:i:s" format (UTC).`
- `The "status" field must be one of NEW, INPROGRESS, DONE.`
- `The "tags" field must be an array of non-empty strings without commas.`
- `The "plannedHours" field must be a number between 0 and 100.`
- `The "description" field must not exceed 65535 bytes.`

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

For `POST`, a valid `write` key whose `projects` grant does not cover the target project
gets a distinct `403`:

```json
{"error": "Project not granted for this API key."}
```

### Too many failed attempts (429)

More than 20 failed authentications per minute from the same IP.

```json
{"error": "Too many failed authentication attempts. Try again later."}
```
