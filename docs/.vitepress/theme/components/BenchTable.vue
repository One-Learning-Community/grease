<script setup lang="ts">
// Renders a section of the live benchmark JSON that benchmarks/export-metrics.sh writes.
// Regenerate the data with:  bash benchmarks/export-metrics.sh
import bench from '../../data/benchmarks.json'

const props = withDefaults(
  defineProps<{ section?: 'macro' | 'blade' | 'perOp' | 'events'; pct?: number }>(),
  { section: 'macro', pct: 50 },
)

// Per-section presentation: column header + how to render the time columns.
const CONFIG: Record<string, { head: string; unit: 'ms' | 'us'; from: 'us' | 'ms'; times: boolean }> = {
  macro: { head: 'Endpoint — one request, incl. SQL', unit: 'ms', from: 'us', times: true },
  blade: { head: 'Render — 1,000 components', unit: 'ms', from: 'ms', times: true },
  perOp: { head: 'Operation', unit: 'us', from: 'us', times: true },
  events: { head: 'Dispatch', unit: 'us', from: 'us', times: true },
}

const cfg = CONFIG[props.section]
const data: any[] = (bench as any)[props.section] ?? []
const hasPct = props.section === 'macro' || props.section === 'blade'

const fmtTime = (raw: number) => {
  if (cfg.unit === 'ms') {
    return (cfg.from === 'ms' ? raw : raw / 1000).toFixed(2) + ' ms'
  }
  return raw.toFixed(raw < 10 ? 2 : 1) + ' µs'
}
const fmtPct = (d: number) => (d > 0 ? '+' : '−') + Math.abs(d).toFixed(0) + '%'
const field = cfg.from === 'ms' ? '_ms' : '_us'

const rows = data.map((e: any) => {
  // For macro/blade pick the requested percentile; perOp/events live on the row itself.
  const src = hasPct ? e.percentiles?.[String(props.pct)] ?? e : e
  return {
    label: e.label,
    vanilla: fmtTime(src['vanilla' + field]),
    grease: fmtTime(src['grease' + field]),
    delta: src.delta_pct,
  }
})
</script>

<template>
  <table class="bench-table">
    <thead>
      <tr>
        <th>{{ cfg.head }}<span v-if="section === 'macro' || section === 'blade'"> (p{{ props.pct }})</span></th>
        <th class="num">vanilla</th>
        <th class="num">+ Grease</th>
        <th class="num">Δ</th>
      </tr>
    </thead>
    <tbody>
      <tr v-for="row in rows" :key="row.label">
        <td>{{ row.label }}</td>
        <td class="num">{{ row.vanilla }}</td>
        <td class="num">{{ row.grease }}</td>
        <td class="num delta">{{ fmtPct(row.delta) }}</td>
      </tr>
    </tbody>
  </table>
  <p v-if="section === 'macro'" class="bench-meta">
    Live from <code>{{ bench.source }}</code> · {{ bench.env }} · PHP {{ bench.php_version }}
    · <code>{{ bench.git_sha }}</code> · parity {{ bench.parity }}
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
