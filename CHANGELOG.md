# Changelog

All notable changes are documented here. Component versions are independent.

## Bridge 1.0.4 / Local add-on 1.1.0 — unreleased (Phase 2 candidate)

### Reliability
- Added authenticated chunked uploads for full-site database and file imports (4 MiB max chunk, SHA-256 per chunk and full package, offset recovery after transient errors).
- Added bounded per-user transfer sessions with 24-hour expiry and explicit status/complete/cancel REST operations.
- Added a Local-side durable diagnostic operation journal and cooperative cancellation at safe checkpoints.
- Added configurable request timeouts (30–1800 seconds) in the Local interface.
- Added Bridge critical health paths setting, with `/` and `/wp-json/` as defaults.

### Compatibility and validation
- Added JavaScript unit tests for resumable retries, cancellation, and operation journal recovery.
- Added WordPress REST runtime smoke tests for WordPress 6.2/PHP 8.1 and latest WordPress/PHP 8.3.
- Added PHP 7.4 and 8.3 syntax checks and release ZIP/checksum verification in GitHub Actions.
- Full-site operations now require Bridge 1.0.4+ on both Local and Live.

### Phase 3 — UX and maintainability
- Extracted full-site orchestration from the Electron entrypoint and isolated pure preflight/comparison models.
- Added a deployment preflight dashboard covering connection, compatible Bridge versions, enablement, ZIP availability, free-space reporting, and endpoint scheme.
- Added detailed UUID/hash content and media comparisons, and bounded site-file manifests (1,200 entries, SHA-256 for files up to 5 MiB) with explicit partial/unverified markers.
- Added status-aware recovery guidance, operation checkpoint display, and an authenticated Live audit event viewer.
- Updated Bridge audit records with event IDs, schema version 2, severity and a strict metadata allowlist.
- Added Node model tests and WordPress smoke coverage for manifest and audit routes.

### Limitations
- Resume occurs inside an active upload operation after transport errors. Restarting Local does not automatically resume a full-site deployment.
- Package downloads, selective media, and theme uploads are not yet resumable.
- Cancellation is cooperative; an in-progress import may complete and earlier changes are not automatically reverted.
- Neither this milestone nor existing backups provides atomic whole-site rollback. Keep host backups before production use.

## Bridge 1.0.3 / Local add-on 1.0.5 — 2026-10-09

### Security and reliability

- Enforced the Bridge deployment-enabled setting for export and mutation endpoints.
- Restricted Bridge fallback authentication to WordPress Application Passwords; primary account passwords are no longer accepted.
- Restricted site-package creation/import to uploads, plugins, and themes.
- Rejected archive paths outside allowed roots, symlinks, and attempts to replace the active Bridge.
- Applied the configured package-size limit to database and site-package imports.
- Raised the configurable package-size ceiling to 2048 MB while keeping the default at 200 MB.
- Full clone now reports component failures instead of displaying unconditional success.
- Full clone/push now report failed final critical health checks.
- Clarified in the Local UI that themes are always included in full migrations.
- Blocks full migrations unless both endpoints run Bridge 1.0.3 or later and have deployments enabled.
- Displays both Bridge versions in the connection result so mismatches are visible before deployment.

### Documentation

- Added installation, user, architecture, API, security, troubleshooting, contribution, and release/distribution documentation.
- Rewrote WordPress and Local component readmes for full migration functionality.

## Bridge 1.0.2 / Local add-on 1.0.3

- Streamed database/site-package file bytes correctly in multipart uploads, fixing repeated `socket hang up` failures.
- Increased request/download/upload timeout to ten minutes and added safe retries for connection resets.
- Deferred database views until base tables exist and removed source-only database qualifiers and definers.
- Synchronized the expanded Local add-on release directory during builds.

## Bridge 1.0.1

- Added safe token-level replacement for serialized settings containing classes unavailable on the destination.

## Bridge 1.0.0 / Local add-on 1.0.0

- Initial paired release with authenticated connections, selective content/media sync, theme deployment/rollback, database/site migration, snapshots, health checks, and logs.
