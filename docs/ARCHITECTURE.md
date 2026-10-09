# Architecture and data flows

## Components

```text
Local desktop add-on (Electron main + renderer)
       | Basic Auth using Application Passwords
       | JSON and streamed multipart HTTPS/HTTP
       v
Local WordPress Bridge <----------> Live WordPress Bridge
       |                                   |
       +-- WordPress database              +-- WordPress database
       +-- wp-content                      +-- wp-content
       +-- protected recovery directory    +-- protected recovery directory
```

The renderer provides the UI and confirmation prompts. The Electron main process owns credentials, network traffic, package streaming, temporary desktop files, theme packaging, retries, and orchestration. Each Bridge authenticates requests, checks WordPress capabilities and deployment state, validates data/packages, performs local filesystem/database work, records activity, and runs health checks.

## Connection and transport

- REST namespace: `/wp-json/infinity-deploy/v1`.
- Authentication: HTTP Basic header containing a WordPress username/email and Application Password.
- Primary WordPress passwords are not accepted by the Bridge’s fallback authenticator.
- Live must use HTTPS unless it resolves to a recognized local hostname.
- Local self-signed TLS verification is relaxed only for `localhost`, `127.0.0.1`, and `.local` hosts.
- General, download, and upload operations use ten-minute client timeouts.
- JSON and package downloads retry twice on connection reset/socket hang-up.
- Redirects are limited to three, must retain the hostname, and cannot downgrade HTTPS.
- Full database and site packages stream through a temporary OS file; theme-only packages and selective media are buffered in memory.

## Selective content model

The Bridge assigns and persists stable UUID metadata to content and media. A normalized payload hash identifies changes. The export includes only configured post types and allowed metadata; the destination accepts only that same allowlist.

Push flow:

```text
Local export + Live manifest
        -> compare UUID/hash
        -> upload changed referenced media (3 workers)
        -> POST selected content (25 per request)
        -> verify expected Live hash
        -> rewrite URLs and update/add posts
        -> maintenance + health checks
```

Conflict detection is optimistic: Compare captures the current destination hash, which must still match when an existing item is updated. Pull intentionally sets no expected Local hash and should therefore be used with care if Local editors are active.

## Full database migration

The exporter writes table definitions and row batches, using gzip when available. Import:

1. Saves requested destination tables (normally users/usermeta).
2. Drops/recreates imported tables and inserts data.
3. Defers views until base tables exist, strips source database qualifiers/definers, and retries dependent views.
4. Restores preserved tables.
5. Adapts WordPress table-prefix option and usermeta keys.
6. Recursively replaces source URLs/paths in strings and serialized values.
7. Uses token-level serialized-string replacement when an unavailable PHP class deserializes as `__PHP_Incomplete_Class`.

The Bridge creates a database snapshot before every import and automatically restores it when import or the critical post-import health check fails.

## Site-file packages

Only `wp-content/uploads`, `wp-content/plugins`, and `wp-content/themes` are supported. Packages exclude version-control data, `node_modules`, common cache folders, temporary/log/backup files, Infinity Deploy recovery folders, and the Bridge itself.

Import rejects absolute paths, traversal, null bytes, symbolic links, paths outside the three allowed roots, and attempts to replace the Bridge. Extraction overlays the WordPress root; it does not remove stale destination files.

## Backups and temporary files

Bridge artifacts are stored under a randomly suffixed `wp-content/.infinity-deploy-backups-<secret>` directory. Apache/IIS denial files and an `index.php` guard are created. Because Nginx ignores `.htaccess`, the unpredictable suffix is defense in depth; distributors should recommend an explicit server rule denying dot-prefixed `wp-content` directories.

Transfer packages are deleted by the add-on after download where possible. Theme backups and database snapshots are retained and pruned according to settings. Desktop temporary packages use the OS temp directory and are removed in `finally` blocks.

## Health checks

Default paths are `/`, `/about/`, `/contact/`, `/products/`, and `/services/`. HTTP 2xx/3xx passes. Only `/` is critical by default. Filters can change paths, criticality, and TLS verification.

## Extension hooks

| Hook | Purpose |
|---|---|
| `infinity_deploy_allowed_post_types` | Filter selective-sync post types |
| `infinity_deploy_allowed_meta` | Filter allowed metadata for a post type |
| `infinity_deploy_admin_available_post_types` | Filter types shown in Bridge settings |
| `infinity_deploy_health_check_paths` | Change health-check paths |
| `infinity_deploy_critical_health_check_paths` | Choose paths that can fail/rollback a deployment |
| `infinity_deploy_health_check_sslverify` | Override loopback TLS verification |
| `infinity_deploy_theme_deployed` | Run integration work after theme install |
| `infinity_deploy_maintenance` | Run custom post-transfer maintenance |

The maintenance endpoint also calls legacy Rahul Graphics lifecycle functions when present, then flushes rewrite rules, theme cache, and object cache.
