#!/usr/bin/env bash
#
# Refresh data/disposable-domains.txt from the disposable-email-domains
# project (CC0), keeping only lines that are valid lowercase domains.
# Commit the file with the next release.
#
# Usage: bin/update-disposable-domains.sh

set -euo pipefail

URL="https://raw.githubusercontent.com/disposable-email-domains/disposable-email-domains/main/disposable_email_blocklist.conf"
DEST="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)/data/disposable-domains.txt"
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT

curl -sSfL --max-time 60 "$URL" -o "$TMP/raw.txt"

# Lowercase, trim, drop comments and anything that isn't a plain domain.
tr '[:upper:]' '[:lower:]' < "$TMP/raw.txt" |
	sed -e 's/[[:space:]]//g' -e '/^#/d' -e '/^$/d' |
	grep -E '^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?(\.[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?)+$' |
	LC_ALL=C sort -u > "$TMP/new.txt"

count="$(wc -l < "$TMP/new.txt" | tr -d ' ')"
if [ "$count" -lt 1000 ]; then
	echo "Only $count domains came back; keeping the current list." >&2
	exit 1
fi

if [ -f "$DEST" ]; then
	LC_ALL=C sort -u "$DEST" > "$TMP/old.txt"
	added="$(LC_ALL=C comm -13 "$TMP/old.txt" "$TMP/new.txt" | wc -l | tr -d ' ')"
	removed="$(LC_ALL=C comm -23 "$TMP/old.txt" "$TMP/new.txt" | wc -l | tr -d ' ')"
	echo "$count domains ($added added, $removed removed)."
else
	echo "$count domains."
fi

cp "$TMP/new.txt" "$DEST"
