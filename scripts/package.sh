#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
OUTPUT_DIR="${1:-}"
if [[ -z "$OUTPUT_DIR" ]]; then
    OUTPUT_DIR="$ROOT/dist"
fi
VERSION="$(sed -n "s/.*PLUGIN_MAILBLAST_VERSION', *'\\([^']*\\)'.*/\\1/p" "$ROOT/setup.php" | head -n 1)"

mkdir -p "$OUTPUT_DIR"
OUTPUT_DIR="$(cd "$OUTPUT_DIR" && pwd)"
STAGE="$(mktemp -d)"
trap 'rm -rf "$STAGE"' EXIT
mkdir -p "$STAGE/mailblast"

# Explicit allow-list: CI, tests and packaging helpers can never leak into releases.
for item in setup.php hook.php plugin.xml LICENSE README.md CHANGELOG.md logo.png user.png config.png src templates locales js; do
    if [[ ! -e "$ROOT/$item" ]]; then
        echo "Required release item is missing: $item" >&2
        exit 1
    fi
    cp -a "$ROOT/$item" "$STAGE/mailblast/"
done

ARCHIVE="$OUTPUT_DIR/mailblast-$VERSION.zip"
rm -f "$ARCHIVE"
(
    cd "$STAGE"
    zip -qr "$ARCHIVE" mailblast
)
unzip -t "$ARCHIVE" >/dev/null
echo "Created and validated $ARCHIVE"
