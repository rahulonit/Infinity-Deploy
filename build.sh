#!/usr/bin/env bash
set -euo pipefail

project_dir="$(cd "$(dirname "$0")" && pwd)"
dist_dir="$project_dir/dist"
stage_dir="$(mktemp -d "${TMPDIR:-/tmp}/infinity-deploy.XXXXXX")"
trap 'rm -rf "$stage_dir"' EXIT

node --check "$project_dir/local-addon/lib/main.js"
node --check "$project_dir/local-addon/lib/renderer.js"
node -e 'JSON.parse(fs.readFileSync(process.argv[1], "utf8"))' "$project_dir/local-addon/package.json"
if command -v php >/dev/null 2>&1; then
  while IFS= read -r -d '' php_file; do
    php -l "$php_file" >/dev/null
  done < <(find "$project_dir/wordpress-plugin/infinity-deploy-bridge" -name '*.php' -print0)
fi

mkdir -p "$dist_dir" "$stage_dir/infinity-deploy-bridge" "$stage_dir/infinity-deploy-local-addon"
rsync -a --exclude='.DS_Store' "$project_dir/wordpress-plugin/infinity-deploy-bridge/" "$stage_dir/infinity-deploy-bridge/"
rsync -a --exclude='.DS_Store' "$project_dir/local-addon/" "$stage_dir/infinity-deploy-local-addon/"
cp "$project_dir/LICENSE.md" "$stage_dir/infinity-deploy-bridge/LICENSE.md"
cp "$project_dir/LICENSE.md" "$stage_dir/infinity-deploy-local-addon/LICENSE.md"

# Keep the expanded release directory in sync as well. Local development setups
# commonly symlink this directory, and a stale copy previously kept the old
# 60-second request timeout even after the source had been fixed.
mkdir -p "$dist_dir/infinity-deploy-local-addon"
rsync -a --delete --exclude='.DS_Store' "$stage_dir/infinity-deploy-local-addon/" "$dist_dir/infinity-deploy-local-addon/"

(
  cd "$stage_dir"
  zip -q -FS -r "$dist_dir/infinity-deploy-bridge.zip" infinity-deploy-bridge
  zip -q -FS -r "$dist_dir/infinity-deploy-local-addon.zip" infinity-deploy-local-addon
)

unzip -t "$dist_dir/infinity-deploy-bridge.zip" >/dev/null
unzip -t "$dist_dir/infinity-deploy-local-addon.zip" >/dev/null
(
  cd "$dist_dir"
  shasum -a 256 infinity-deploy-bridge.zip infinity-deploy-local-addon.zip > SHA256SUMS
)
printf 'Built:\n  %s\n  %s\n  %s\n' "$dist_dir/infinity-deploy-bridge.zip" "$dist_dir/infinity-deploy-local-addon.zip" "$dist_dir/SHA256SUMS"
