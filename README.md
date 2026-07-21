# Databridge Plugin

A Leantime plugin that exposes an API endpoint for retrieving tickets filtered by username (email), with optional date range and status filtering. Designed for consumption by remote AI agents and external integrations.

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

Each user entry defines a `name` (used in logs), a plaintext `key`, and the granted
`operations`. Changes apply on the next request — no cache to clear.

```yaml
users:
  - name: reporting-agent
    key: "32+-random-chars"
    operations: [read]
```

### Operations

| Operation | Used by |
|-----------|-----------------------------------------|
| `read`    | `GET\|POST /api/databridge/tickets`     |
| `write`   | Reserved for future endpoints           |
| `delete`  | Reserved for future endpoints           |

### POC limitations

- Keys are stored in plaintext (same trust level as the DB password in `config/.env`).
- No key expiry or rotation yet.

Failed authentication attempts are throttled: more than 20 failures per minute from the
same IP returns `429 Too Many Requests`.

## Endpoint

```
POST /api/databridge/tickets
GET  /api/databridge/tickets
```

### Parameters

| Parameter  | Type   | Required | Default | Description                                      |
|------------|--------|----------|---------|--------------------------------------------------|
| `username` | string | Yes      |         | Email/username to filter tickets by               |
| `dateFrom` | string | No       |         | ISO date (`Y-m-d`), filters `dateToFinish >=`     |
| `dateTo`   | string | No       |         | ISO date (`Y-m-d`), filters `dateToFinish <=`     |
| `status`   | string | No       |         | Status type: `NEW`, `INPROGRESS`, `DONE` (case-insensitive) |
| `start`    | int    | No       | 0       | Pagination offset by ticket ID                    |
| `limit`    | int    | No       | 100     | Maximum number of results                         |

### Ticket matching

A ticket is returned if the user is either:
- The **assigned editor** of the ticket, or
- A **collaborator** on the ticket

Milestones are excluded.

## Examples

### POST with JSON body (recommended)

```bash
curl -k -X POST https://leantime.example.com/api/databridge/tickets \
  -H "x-api-key: YOUR_API_KEY" \
  -H "Content-Type: application/json" \
  -d '{"username": "user@example.com"}'
```

### GET with query parameters

```bash
curl -k "https://leantime.example.com/api/databridge/tickets?username=user@example.com&limit=50" \
  -H "x-api-key: YOUR_API_KEY"
```

### With date filtering

```bash
curl -k -X POST https://leantime.example.com/api/databridge/tickets \
  -H "x-api-key: YOUR_API_KEY" \
  -H "Content-Type: application/json" \
  -d '{"username": "user@example.com", "dateFrom": "2026-01-01", "dateTo": "2026-12-31"}'
```

### With status filtering

```bash
curl -k -X POST https://leantime.example.com/api/databridge/tickets \
  -H "x-api-key: YOUR_API_KEY" \
  -H "Content-Type: application/json" \
  -d '{"username": "user@example.com", "status": "inprogress"}'
```

### Combined filters

```bash
curl -k -X POST https://leantime.example.com/api/databridge/tickets \
  -H "x-api-key: YOUR_API_KEY" \
  -H "Content-Type: application/json" \
  -d '{
    "username": "user@example.com",
    "status": "inprogress",
    "dateFrom": "2026-01-01",
    "dateTo": "2026-12-31",
    "limit": 10
  }'
```

### Pagination

Use `start` (minimum ticket ID) and `limit` to paginate through results:

```bash
# First page
curl -k -X POST https://leantime.example.com/api/databridge/tickets \
  -H "x-api-key: YOUR_API_KEY" \
  -H "Content-Type: application/json" \
  -d '{"username": "user@example.com", "start": 0, "limit": 50}'

# Next page (use the last ticket ID + 1 from previous response)
curl -k -X POST https://leantime.example.com/api/databridge/tickets \
  -H "x-api-key: YOUR_API_KEY" \
  -H "Content-Type: application/json" \
  -d '{"username": "user@example.com", "start": 1051, "limit": 50}'
```

## Response format

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

## Error responses

### Missing username (400)

```json
{"error": "The \"username\" parameter is required."}
```

### Invalid API key (401)

Returned for a missing, empty, or unknown key — and, fail-closed, when the auth YAML file
is missing or malformed (check the Leantime log). Also returned by Leantime core when the
plugin is disabled.

```json
{"error": "Invalid API Key"}
```

### Operation not granted (403)

The key is valid but its user lacks the operation the endpoint requires.

```json
{"error": "Operation not permitted for this API key"}
```

### Too many failed attempts (429)

More than 20 failed authentications per minute from the same IP.

```json
{"error": "Too many failed authentication attempts. Try again later."}
```
