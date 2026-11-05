# REST API reference

Base URL:

```text
https://example.com/wp-json/infinity-deploy/v1
```

All routes require an authenticated WordPress user. Send the WordPress username/email and a dedicated Application Password using HTTP Basic authentication. JSON requests use `Content-Type: application/json`; package imports use `multipart/form-data`.

Unless noted, mutation routes require `manage_options` and the Bridge setting **Enable authenticated deployments**. Theme routes additionally require `update_themes` and `install_themes`. Selective export/manifest routes require `edit_posts` and an enabled Bridge. Read routes require `manage_options`.

## Endpoints

| Method | Route | Purpose | Permission |
|---|---|---|---|
| GET | `/status` | Connection, versions, settings summary, backups/snapshots | Read |
| GET | `/content/export` | Full selective-content/media payload | Export |
| GET | `/content/manifest` | UUID/hash manifest | Export |
| POST | `/content/sync` | Add/update up to 200 content items | Deploy |
| POST | `/media/sync` | Upload one UUID/checksum-bound media item | Deploy |
| POST | `/theme/deploy` | Validate, back up, and install theme ZIP | Theme deploy |
| POST | `/theme/rollback` | Restore retained theme ZIP | Theme deploy |
| POST | `/db/export` | Create downloadable SQL/SQL.GZ | Deploy |
| POST | `/db/import` | Import database with optional remapping/preservation | Deploy |
| POST | `/db/snapshot` | Create retained database snapshot | Deploy |
| POST | `/db/restore` | Restore retained database snapshot | Deploy |
| GET | `/db/snapshots` | List retained database snapshots | Read |
| POST | `/site/export` | Create ZIP of allowed `wp-content` components | Deploy |
| POST | `/site/manifest` | Bounded read-only file comparison manifest | Deploy |
| POST | `/site/import` | Overlay an allowed site ZIP | Deploy |
| GET | `/package/download` | Stream a generated package | Deploy |
| POST | `/package/cleanup` | Delete a temporary package | Deploy |
| POST | `/maintenance` | Hooks, rewrites, cache flush, health checks | Deploy |
| POST | `/transfer/start` | Start a checksummed package upload session | Deploy |
| GET | `/transfer/status?id=...` | Query uploaded offset for owner | Deploy |
| POST | `/transfer/chunk` | Upload next fixed-offset multipart chunk | Deploy |
| POST | `/transfer/complete` | Final SHA-256 validation and package key | Deploy |
| POST | `/transfer/cancel` | Delete uncommitted chunks | Deploy |
| GET | `/logs` | Read newest-first bounded activity log | Read |

## Status

`GET /status` returns `ok`, Bridge/WordPress versions, environment, site/home URLs, active theme, `deployment_enabled`, allowlists, permalink structure, ZIP availability, upload limit, theme backups, and database snapshots. It remains readable when deployment is disabled.

## Selective content

`POST /content/sync`

```json
{
  "source_url": "http://project.local",
  "items": [
    {
      "uuid": "stable-uuid",
      "type": "page",
      "title": "About",
      "slug": "about",
      "status": "publish",
      "content": "...",
      "expected_remote_hash": "optional-sha256"
    }
  ]
}
```

Maximum 200 items per request. The add-on uses batches of 25. Each result can be `synced` or an item-level error such as `content_conflict`.

`POST /media/sync` is multipart with file field `media` and text fields `uuid`, `hash`, `title`, and `alt`. The SHA-256 value is verified when supplied.

## Theme deployment

`POST /theme/deploy` is multipart:

- File field: `package` (ZIP).
- Text field: `theme_slug`.
- Header: `X-Infinity-Checksum` containing the lowercase SHA-256 checksum.

The top-level ZIP directory must equal `theme_slug` and contain `style.css`. A successful response includes theme metadata, backup key, and health results.

`POST /theme/rollback`

```json
{
  "backup_key": "theme-YYYYMMDD-HHMMSS-random",
  "theme_slug": "theme-directory"
}
```

## Database

`POST /db/export`

```json
{ "exclude_users": true }
```

Returns `file_key`, filename, size, compression flag, source site URL, source filesystem path, and source table prefix.

`POST /db/import` accepts a multipart file field named `database` plus optional text fields:

| Field | Meaning |
|---|---|
| `source_url` | Origin to replace |
| `target_url` | Informational; the Bridge uses its own current site URL as target |
| `source_path` | Source filesystem root to replace |
| `source_prefix` | Source database table prefix |
| `preserve_users` | `1` retains destination users/usermeta |

The endpoint also accepts a previously generated `file_key`, or legacy base64 `sql`/`sql_gz` parameters. File upload is preferred.

`POST /db/snapshot` accepts `{ "label": "pre-release" }`. `POST /db/restore` accepts `{ "snapshot_key": "..." }`.

## Site files

`POST /site/export`

```json
{ "components": ["uploads", "plugins", "themes"] }
```

Unsupported component names are ignored. The generated package contains paths rooted below `wp-content/`.

`POST /site/import` accepts multipart file field `package`, a prior `file_key`, or a legacy base64 `zip` field. The configured maximum uploaded package size applies.

`GET /package/download?file_key=...` streams the binary package. `POST /package/cleanup` accepts `{ "file_key": "..." }`.

## Errors

WordPress REST errors use this shape:

```json
{
  "code": "package_too_large",
  "message": "The site package exceeds the configured size limit.",
  "data": { "status": 413 }
}
```

Common HTTP meanings: 400 invalid input/package, 401 invalid Application Password, 403 capability/disabled/allowlist failure, 404 missing package/snapshot, 413 configured or server upload limit, and 500 import/filesystem/health failure.

The API is an implementation contract for the bundled add-on, not a general remote-management API. Backward compatibility should be reviewed and documented before exposing it to third-party clients.

## Resumable package uploads (Bridge 1.0.4+)

Start with `POST /transfer/start` JSON: `{"filename":"site.zip","size":1234,"sha256":"<64 hexadecimal characters>"}`. The server returns a random `id`, `offset: 0`, and `chunk_size` (max 4 MiB). Send each chunk to `POST /transfer/chunk` as multipart with file field `chunk` and text fields `id`, `offset`, `sha256` (chunk digest). Chunks are sequential and authenticated to the creating user. On timeout or lost acknowledgment, request `GET /transfer/status?id=...` and continue at that byte offset. After all bytes are committed, `POST /transfer/complete` with `{"id":"..."}`; the returned `file_key` can be passed to `/db/import` or `/site/import`. Server sessions expire after 24 hours; a user is limited to five active sessions. The final digest is always verified before import.

Only full-site **uploads** use chunked transfer in this release. Downloads, theme uploads, and selective media retain their previous transport. The Local journal is diagnostic rather than an automatic workflow-resume mechanism, and cancellation is cooperative between network steps. Never assume a cancelled deployment rolled back previously applied changes.

## Phase 3 file comparison and event metadata

`POST /site/manifest` takes `{"components":["themes","plugins","uploads"]}`. Its response contains `files` entries with relative `path`, `size`, and a `sha256` digest when the file is at most 5 MiB. It returns no more than 1,200 entries and flags `truncated` if the scan stopped early. A null digest is **unverified**, not evidence that matching-size files are identical. File comparison is informational: package import overlays and does not delete destination-only files. Manifests are not a guarantee of exact filesystem parity and may not include every packaging exclusion on unusual paths.

Bridge audit events now include `schema_version:2`, a generated `event_id`, `time_gmt`, `user_id`, `action`, `status`, `severity` and a short, allowlisted `context`. The log retains at most 200 events. Legacy events may remain in the list. Payloads and arbitrary error messages are intentionally omitted to reduce secret disclosure.
