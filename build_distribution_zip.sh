#!/usr/bin/env bash
# Build the wordpress.org zip from committed files listed in .distinclude (explicit include list, never the
# working folder), so internal files (.hermes/, frontend-app/, .env*, tests) can't end up in a release.
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_NAME="$(basename "$ROOT_DIR")"
STAMP="$(date +%Y%m%d-%H%M%S)"
OUTPUT_ZIP="$(dirname "$ROOT_DIR")/${PROJECT_NAME}-plugin-${STAMP}.zip"

cd "$ROOT_DIR"
INCLUDE="$(grep -v '^#' .distinclude | xargs)"
# shellcheck disable=SC2086
git archive --format=zip --prefix="${PROJECT_NAME}/" -o "$OUTPUT_ZIP" HEAD $INCLUDE

echo "Created: $OUTPUT_ZIP (from HEAD $(git rev-parse --short HEAD); uncommitted changes are not included)"
