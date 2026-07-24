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
  "${PROJECT_NAME}/.wordpress-org/*" \
  "${PROJECT_NAME}/dist/*" \
  "${PROJECT_NAME}/frontend-app/*" \
  "${PROJECT_NAME}/vendor/*" \
  "${PROJECT_NAME}/AGENTS.md" \
  "${PROJECT_NAME}/COPILOT.md" \
  "${PROJECT_NAME}/composer.json" \
  "${PROJECT_NAME}/composer.lock" \
  "${PROJECT_NAME}/build_distribution_zip.sh" \
  "${PROJECT_NAME}/*.zip" \
  "${PROJECT_NAME}/.DS_Store" \
  "${PROJECT_NAME}/**/.DS_Store"

echo "Created: $OUTPUT_ZIP"