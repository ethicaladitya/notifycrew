#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_NAME="$(basename "$ROOT_DIR")"
STAMP="$(date +%Y%m%d-%H%M%S)"
OUTPUT_ZIP="$(dirname "$ROOT_DIR")/${PROJECT_NAME}-plugin-${STAMP}.zip"

cd "$(dirname "$ROOT_DIR")"

zip -r "$OUTPUT_ZIP" "$PROJECT_NAME" \
  -x "${PROJECT_NAME}/.git/*" \
  "${PROJECT_NAME}/.github/*" \
  "${PROJECT_NAME}/.release/*" \
  "${PROJECT_NAME}/dist/*" \
  "${PROJECT_NAME}/frontend-app/node_modules/*" \
  "${PROJECT_NAME}/frontend-app/dist/*" \
  "${PROJECT_NAME}/*.zip" \
  "${PROJECT_NAME}/.DS_Store" \
  "${PROJECT_NAME}/**/.DS_Store"

echo "Created: $OUTPUT_ZIP"