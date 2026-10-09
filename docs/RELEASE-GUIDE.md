# Release and distribution guide

## Release model

Infinity Deploy ships two independently versioned artifacts:

- `infinity-deploy-bridge.zip`: WordPress plugin, currently 1.0.3.
- `infinity-deploy-local-addon.zip`: Local add-on, currently 1.0.4.

Publish them together and state compatible versions in every release. A Bridge should be updated on both Local and Live before relying on a newly documented behavior.

## Before tagging

1. Review `CHANGELOG.md` and component readmes.
2. Update the plugin header version, `INFINITY_DEPLOY_VERSION`, and WordPress `Stable tag` together.
3. Update `local-addon/package.json` version.
4. Confirm documentation version references and compatibility table.
5. Confirm license/author/support metadata and replace distributor-specific URLs as needed.
6. Perform a security review of authentication, authorization, archive extraction, package downloads, and logging.
7. Run automated checks and manual acceptance tests below.

## Build

From the repository root:

```bash
./build.sh
```

Expected output:

```text
dist/infinity-deploy-bridge.zip
dist/infinity-deploy-local-addon.zip
dist/SHA256SUMS
```

Do not zip the repository root. Each release archive must have exactly one correctly named top-level component folder.

## Automated verification

```bash
node --check local-addon/lib/main.js
node --check local-addon/lib/renderer.js
php -l wordpress-plugin/infinity-deploy-bridge/infinity-deploy-bridge.php
find wordpress-plugin/infinity-deploy-bridge -name '*.php' -exec php -l {} \;
unzip -t dist/infinity-deploy-bridge.zip
unzip -t dist/infinity-deploy-local-addon.zip
unzip -l dist/infinity-deploy-bridge.zip
unzip -l dist/infinity-deploy-local-addon.zip
```

Run WordPress Coding Standards/Plugin Check in the distributor’s CI when publishing through WordPress.org. Resolve warnings or document justified exceptions before release.

## Manual acceptance matrix

Test against fresh and representative populated sites:

| Test | Expected result |
|---|---|
| Bridge disabled | Status readable; export/write operations rejected |
| Valid/invalid Application Password | Valid connects; invalid returns 401 |
| Primary account password | Rejected |
| Compare unchanged/new/changed content | Correct counts and selectable items |
| Concurrent Live edit after Compare | Push returns content conflict |
| Selective content + media push/pull | Fields, URLs, relations, media preserved |
| Theme deploy | Checksum validated, backup created, health passes |
| Broken theme/critical route | Automatic theme rollback |
| Live → Local clone | DB remapped; selected components render locally |
| Preserve users during clone | Local login remains valid |
| Local → Live full push | Pre-push snapshot exists; Live renders correctly |
| Preserve users during push | Live login/customer user tables remain |
| Database restore | Selected snapshot restores and health runs |
| Large streamed package | Complete transfer without socket reset |
| Unsafe ZIP cases | Traversal, wrong scope, symlink, Bridge replacement rejected |
| Disabled/missing ZIP extension | Clear actionable error |

Also test WordPress in a subdirectory, different table prefixes, serialized plugin settings, unavailable serialized classes, MySQL views, HTTP Local with self-signed TLS variants, and common proxy/CDN configurations.

## Checksums and signing

The build writes SHA-256 checksums to `dist/SHA256SUMS`. Verify or regenerate them after the final build and publish them through the same trusted release channel:

```bash
shasum -a 256 dist/infinity-deploy-bridge.zip dist/infinity-deploy-local-addon.zip
```

For higher-assurance distribution, sign the checksum manifest or release artifacts with the organization’s established signing process. Never rebuild artifacts after publishing checksums.

## WordPress distribution

Before a WordPress.org submission:

- Follow the official detailed plugin guidelines and readme standard.
- Use GPL-compatible assets/code and disclose external services (there currently are none).
- Keep source human-readable and do not ship development secrets, backups, logs, or databases.
- Run Plugin Check and confirm `readme.txt` sections/tags are current.
- Supply WordPress.org banner/icon/screenshots using its required asset repository layout; root JPGs are source marketing assets, not automatically packaged by this build.
- Confirm trademarks, support URL, contributor account, and translations.

The Local add-on is a separate artifact and follows Local’s add-on structure/API requirements; WordPress.org distributes only the Bridge plugin.

Official references:

- [WordPress detailed plugin guidelines](https://developer.wordpress.org/plugins/wordpress-org/detailed-plugin-guidelines/)
- [WordPress readme standard](https://developer.wordpress.org/plugins/wordpress-org/how-your-readme-txt-works/)
- [WordPress Application Passwords REST reference](https://developer.wordpress.org/rest-api/reference/application-passwords/)
- [Local: Building your add-on](https://localwp.com/help-docs/building-your-add-on/)
- [Local add-on structure](https://localwp.com/help-docs/building-your-add-on/add-on-structure/)
- [Local add-on API](https://localwp.com/help-docs/building-your-add-on/add-on-api/)

## Release notes template

```markdown
# Infinity Deploy <release name>

- Bridge: x.y.z
- Local add-on: x.y.z
- Requires: Local 9+, WordPress 6.2+, PHP 7.4+
- Compatible component pair: Bridge x.y.z / Add-on x.y.z

## Highlights
- ...

## Security and migration notes
- ...

## Upgrade
1. Back up both sites.
2. Upgrade Bridge on Local and Live.
3. Replace/restart the Local add-on.
4. Test both connections.

## SHA-256
- `infinity-deploy-bridge.zip`: ...
- `infinity-deploy-local-addon.zip`: ...
```

## Post-release

1. Install the exact published ZIPs in a clean environment.
2. Verify checksums and displayed versions.
3. Run connection, selective sync, and a non-production full clone smoke test.
4. Archive the source tag, artifacts, checksum manifest, and test record.
5. Publish known issues and a private security-reporting route.
