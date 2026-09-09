#!/usr/bin/env bash
#
# Build the release ZIP for the WordPress plugin.
#
#   scripts/build-plugin-zip.sh [ref]     # default: HEAD
#
# The 1.2.4 archive shipped three files that had no source in this repository,
# because it was zipped from a working copy. This script reads through
# `git archive` instead of the filesystem, so an uncommitted or untracked file
# cannot reach the artifact even if the tree is dirty — the guarantee is
# structural rather than a warning someone can click past.
#
# Output: bricks-api-bridge.zip in the repository root, with a top-level
# `bricks-api-bridge/` directory, matching every published release.

set -euo pipefail

REF="${1:-HEAD}"
SLUG="bricks-api-bridge"
ROOT="$(git rev-parse --show-toplevel)"
OUT="$ROOT/$SLUG.zip"

command -v zip >/dev/null || { echo "error: zip not found on PATH" >&2; exit 1; }

if ! git -C "$ROOT" rev-parse --verify --quiet "$REF^{commit}" >/dev/null; then
  echo "error: '$REF' is not a commit" >&2
  exit 1
fi

SHA="$(git -C "$ROOT" rev-parse --short "$REF")"
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT

# `plugin/` out of the object store, renamed to the plugin slug so the archive
# carries the folder WordPress expects.
git -C "$ROOT" archive "$REF" plugin | tar -x -C "$TMP"
mv "$TMP/plugin" "$TMP/$SLUG"

rm -f "$OUT"
( cd "$TMP" && zip -qr "$OUT" "$SLUG" )

VERSION="$(git -C "$ROOT" show "$REF:plugin/$SLUG.php" | sed -n 's/^ \* Version: *//p' | head -1)"
CONST="$(git -C "$ROOT" show "$REF:plugin/$SLUG.php" | sed -n "s/.*BRICKS_API_BRIDGE_VERSION', *'\([^']*\)'.*/\1/p" | head -1)"

if [ "$VERSION" != "$CONST" ]; then
  echo "error: header version ($VERSION) and BRICKS_API_BRIDGE_VERSION ($CONST) disagree" >&2
  echo "       the constant is what the plugin reports about itself in five places" >&2
  rm -f "$OUT"
  exit 1
fi

echo "built  $OUT"
echo "  ref      $REF ($SHA)"
echo "  version  $VERSION"
echo "  files    $(unzip -Z1 "$OUT" | grep -vc '/$')"
