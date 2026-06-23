<script setup lang="ts">
// Renders the live macro benchmark table from the committed JSON that
// `benchmarks/export-metrics.sh` writes. Regenerate with:
//   bash benchmarks/export-metrics.sh
import bench from '../../data/benchmarks.json'

const props = withDefaults(defineProps<{ pct?: number }>(), { pct: 50 })

const fmtMs = (us: number) => (us / 1000).toFixed(2) + ' ms'
const fmtPct = (d: number) => (d > 0 ? '+' : '−') + Math.abs(d).toFixed(0) + '%'

const rows = bench.macro.map((e: any) => {
  const p = e.percentiles?.[String(props.pct)] ?? e
  return { label: e.label, vanilla: p.vanilla_us, grease: p.grease_us, delta: p.delta_pct }
})
</script>

<template>
  <table class="bench-table">
    <thead>
      <tr>
        <th>Endpoint — one request, incl. SQL (p{{ props.pct }})</th>
        <th class="num">vanilla</th>
        <th class="num">+ Grease</th>
        <th class="num">Δ</th>
      </tr>
    </thead>
    <tbody>
      <tr v-for="row in rows" :key="row.label">
        <td>{{ row.label }}</td>
        <td class="num">{{ fmtMs(row.vanilla) }}</td>
        <td class="num">{{ fmtMs(row.grease) }}</td>
        <td class="num delta">{{ fmtPct(row.delta) }}</td>
      </tr>
    </tbody>
  </table>
  <p class="bench-meta">
    Live from <code>{{ bench.source }}</code> · {{ bench.env }} · PHP {{ bench.php_version }}
    · <code>{{ bench.git_sha }}</code> · {{ bench.rounds }} rounds · parity {{ bench.parity }}
    · generated {{ bench.generated_at.slice(0, 10) }}
  </p>
</template>

<style scoped>
.bench-table {
  display: table;
  width: 100%;
  border-collapse: collapse;
  margin: 1rem 0 0.5rem;
}
.bench-table th,
.bench-table td {
  border: 1px solid var(--vp-c-divider);
  padding: 0.5rem 0.75rem;
}
.bench-table th {
  background: var(--vp-c-bg-soft);
  text-align: left;
}
.bench-table .num {
  text-align: right;
  font-variant-numeric: tabular-nums;
}
.bench-table .delta {
  font-weight: 700;
  color: var(--vp-c-brand-1);
}
.bench-meta {
  font-size: 0.8rem;
  color: var(--vp-c-text-2);
  margin-top: 0;
}
</style>
