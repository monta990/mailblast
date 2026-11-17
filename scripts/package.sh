#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
OUTPUT_DIR="${1:-}"
if [[ -z "$OUTPUT_DIR" ]]; then
    OUTPUT_DIR="$ROOT/dist"
fi
VERSION="$(sed -n "s/.*PLUGIN_MAILBLAST_VERSION', *'\\([^']*\\)'.*/\\1/p" "$ROOT/setup.php" | head -n 1)"
if [[ ! "$VERSION" =~ ^[0-9]+\\.[0-9]+\\.[0-9]+([+-][0-9A-Za-z.-]+)?$ ]]; then
    echo "Could not read a valid plugin version from setup.php: '$VERSION'" >&2
    exit 1
fi

mkdir -p "$OUTPUT_DIR"
OUTPUT_DIR="$(cd "$OUTPUT_DIR" && pwd)"
STAGE="$(mktemp -d)"
trap 'rm -rf "$STAGE"' EXIT
mkdir -p "$STAGE/mailblast"

# Explicit allow-list: CI, tests and packaging helpers can never leak into releases.
for item in setup.php hook.php plugin.xml LICENSE README.md CHANGELOG.md logo.png user.png config.png src templates locales js public; do
    if [[ ! -e "$ROOT/$item" ]]; then
        echo "Required release item is missing: $item" >&2
        exit 1
    fi
    cp -a "$ROOT/$item" "$STAGE/mailblast/"
done

# Fail before creating an archive if any runtime asset needed by the plugin is absent.
for required in \
    setup.php hook.php plugin.xml \
    src/Service/QueueService.php \
    templates/send.html.twig \
    locales/es_MX.mo \
    js/mailblast_send.js \
    public/js/mailblast_editor.js \
    public/js/mailblast_send.js; do
    if [[ ! -f "$STAGE/mailblast/$required" ]]; then
        echo "Required runtime file is missing from the release package: $required" >&2
        exit 1
    fi
done

ARCHIVE="$OUTPUT_DIR/mailblast-$VERSION.zip"
rm -f "$ARCHIVE"
(
    cd "$STAGE"
    zip -qr "$ARCHIVE" mailblast
)
unzip -t "$ARCHIVE" >/dev/null

# Validate the archive itself, not just the staging directory.
for required in \
    mailblast/setup.php \
    mailblast/hook.php \
    mailblast/plugin.xml \
    mailblast/src/Service/QueueService.php \
    mailblast/templates/send.html.twig \
    mailblast/locales/es_MX.mo \
    mailblast/js/mailblast_send.js \
    mailblast/public/js/mailblast_editor.js \
    mailblast/public/js/mailblast_send.js; do
    if ! unzip -Z1 "$ARCHIVE" | grep -Fqx "$required"; then
        echo "Generated ZIP is missing required file: $required" >&2
        exit 1
    fi
done

echo "Created and validated $ARCHIVE"
