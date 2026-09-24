#!/bin/sh
# Fail when src/Generated is not what the generator makes of the gateway's contract.
#
# Regenerates into a temporary directory with the backend's tools/sdkgen (from
# services/core/api/openapi.json, checked against names.lock: a vanished or renamed public name is a
# breaking change and fails) and compares file by file. The backend checkout is $OBLODAI_BACKEND,
# else ../oblodai-backend next to this repository. Without it the check is skipped, loudly; with
# --require it fails instead. Fix drift by regenerating (`make sdk` in the backend), never by hand.
set -eu

root=$(cd "$(dirname "$0")/.." && pwd)
backend=${OBLODAI_BACKEND:-$root/../oblodai-backend}
sdkgen=$backend/tools/sdkgen
spec=$backend/services/core/api/openapi.json

if [ ! -d "$sdkgen/cmd/sdkgen" ] || [ ! -f "$spec" ]; then
    if [ "${1:-}" = "--require" ]; then
        echo "check-generated: no generator at $sdkgen (set OBLODAI_BACKEND to the backend checkout)" >&2
        exit 1
    fi
    echo "  (skipped: no generator at $sdkgen)"
    exit 0
fi

tmp=$(mktemp -d "${TMPDIR:-/tmp}/oblodai-php-generated.XXXXXX")
trap 'rm -rf "$tmp"' EXIT
cp "$root/names.lock" "$tmp/names.lock"
(
    cd "$sdkgen"
    GOTOOLCHAIN=${GOTOOLCHAIN:-go1.26.6} go run ./cmd/sdkgen -spec "$spec" -lang php -out "$tmp/out" -lock "$tmp/names.lock"
) || {
    echo "check-generated: sdkgen failed (a breaking name change fails against names.lock)" >&2
    exit 1
}
if ! diff -r "$tmp/out/src/Generated" "$root/src/Generated" > "$tmp/diff"; then
    head -n 40 "$tmp/diff" >&2
    echo "check-generated: src/Generated is stale; regenerate with \`make sdk\` in the backend" >&2
    exit 1
fi
if ! cmp -s "$tmp/names.lock" "$root/names.lock"; then
    echo "check-generated: names.lock is behind the contract; regenerate with -update-lock" >&2
    exit 1
fi
echo "generated code matches $spec"
