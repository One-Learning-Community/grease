#!/usr/bin/env bash
#
# Run every benchmark family on Linux (the canonical Docker image) and export their
# parity-gated results as one JSON the docs render live. No hand-kept numbers anywhere.
#
# Sections of docs/.vitepress/data/benchmarks.json:
#   macro   — realworld.php   (end-to-end requests incl. SQL)
#   blade   — blade.php       (component render path)
#   perOp   — CastBench + DateSerializationBench   (per-operation A/B)
#   events  — DispatcherBench + EventStormBench     (event dispatch A/B)
#
# A PARITY FAIL in any family aborts before its JSON is written, so the docs can never
# publish a divergent build. The README's one-line summary is synced from the same JSON.
#
#   bash benchmarks/export-metrics.sh [macro_rounds] [blade_rounds]
#
# Requires the grease-bench image:  docker build -t grease-bench benchmarks/docker
set -euo pipefail

MACRO_ROUNDS="${1:-40}"
BLADE_ROUNDS="${2:-20}"
REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
OUT="docs/.vitepress/data/benchmarks.json"
TMP=".bench-tmp"
SHA="$(git -C "$REPO_ROOT" rev-parse --short HEAD 2>/dev/null || echo '')"

mkdir -p "$REPO_ROOT/$TMP"
trap 'rm -rf "$REPO_ROOT/$TMP"' EXIT

dkr() {
  docker run --rm -v "$REPO_ROOT":/app -w /app -e "GREASE_BENCH_SHA=$SHA" grease-bench "$@"
}
PHP="php -d xdebug.mode=off -d opcache.jit=tracing -d memory_limit=1G"

echo "→ macro (realworld.php, ${MACRO_ROUNDS} rounds)…"
dkr $PHP benchmarks/realworld.php "$MACRO_ROUNDS" --json="$OUT"

echo "→ blade (blade.php, ${BLADE_ROUNDS} rounds)…"
dkr $PHP benchmarks/blade.php 1000 "$BLADE_ROUNDS" --json="$TMP/blade.json"

echo "→ micro (phpbench: CastBench, DateSerializationBench, DispatcherBench, EventStormBench)…"
dkr php vendor/bin/phpbench run \
  benchmarks/Bench/CastBench.php \
  benchmarks/Bench/DateSerializationBench.php \
  benchmarks/Bench/DispatcherBench.php \
  benchmarks/Bench/EventStormBench.php \
  --progress=none --dump-file="$TMP/micro.xml"

echo "→ stack (stack_pipeline.php, cumulative tiers × JSON+Blade)…"
dkr $PHP benchmarks/stack_pipeline.php 120 --json="$TMP/stack.json"

echo "→ merging…"
php "$REPO_ROOT/benchmarks/augment-metrics.php" "$REPO_ROOT/$OUT" "$REPO_ROOT/$TMP/blade.json" "$REPO_ROOT/$TMP/micro.xml" "$REPO_ROOT/$TMP/stack.json"
php "$REPO_ROOT/benchmarks/update-readme.php" || true

echo "✓ wrote $OUT"
