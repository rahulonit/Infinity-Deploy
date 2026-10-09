# Contributing

## Development setup

1. Install the Bridge from `wordpress-plugin/infinity-deploy-bridge/` on two disposable WordPress sites.
2. Install or symlink `local-addon/` using Local’s add-on development workflow.
3. Use dedicated Application Passwords and non-production data.
4. Keep deployments enabled only while testing.

The Local add-on is dependency-free CommonJS/Electron code. The Bridge is dependency-free WordPress PHP.

## Change requirements

- Preserve compatibility with WordPress 6.2+ and PHP 7.4+ unless a release explicitly changes it.
- Treat every REST route as privileged and validate capability, deployment state, input shape, size, filesystem scope, and output.
- Never log credentials, authorization headers, database contents, or full package paths containing secrets.
- Maintain serialized-data correctness and table-prefix remapping for database changes.
- Keep Local/Live direction explicit in UI text and confirmation prompts.
- Update documentation and changelogs with behavioral changes.
- Bump only the component whose distributed code changes; publish compatible pairs.

## Validation

Run `./build.sh`, PHP lint across every plugin file, and the manual acceptance tests in [the release guide](docs/RELEASE-GUIDE.md). New archive/import code requires malicious-path tests. New migration code requires both directions, preserved-users on/off, different prefixes, serialized values, and rollback testing.

## Commit and review hygiene

- Keep generated ZIPs separate from source review where possible.
- Do not commit `connections.json`, site exports, SQL files, backups, WordPress salts, or Application Passwords.
- Explain destructive behavior and rollback limits in the pull request/review description.
- Security-sensitive findings should follow [SECURITY.md](SECURITY.md), not a public issue.
