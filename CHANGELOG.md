# Changelog

All notable changes are documented here. Component versions are independent.

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
