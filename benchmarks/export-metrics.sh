#!/usr/bin/env bash
#
# Run the realworld macro on Linux (the canonical Docker image) and export its
# parity-gated results as JSON for the docs to consume live.
#
# Output: docs/.vitepress/data/benchmarks.json — a committed build input. The docs
# render their headline numbers straight from it, so the published figures are exactly
# what this harness measured (and refuse to publish if parity fails: a PARITY FAIL
# aborts realworld.php with a non-zero exit before any JSON is written).
#
#   bash benchmarks/export-metrics.sh [rounds]
#
# Requires the grease-bench image (see benchmarks/docker/Dockerfile):
#   docker build -t grease-bench benchmarks/docker
#
set -euo pipefail

ROUNDS="${1:-40}"
REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
OUT="docs/.vitepress/data/benchmarks.json"
SHA="$(git -C "$REPO_ROOT" rev-parse --short HEAD 2>/dev/null || echo '')"

echo "→ running realworld macro (Linux/Docker, ${ROUNDS} rounds, git ${SHA:-unknown})…"

docker run --rm \
  -v "$REPO_ROOT":/app -w /app \
  -e "GREASE_BENCH_SHA=$SHA" \
  grease-bench \
  php -d xdebug.mode=off -d opcache.jit=tracing -d memory_limit=1G \
  benchmarks/realworld.php "$ROUNDS" --json="$OUT"

echo "✓ wrote $OUT"

# Sync the README's one-line summary from the same JSON (host-side; no DB/Docker).
if command -v php >/dev/null 2>&1; then
  php "$REPO_ROOT/benchmarks/update-readme.php" || true
else
  echo "  (php not on host — skipping README sync; the docs table is already live)"
fi
