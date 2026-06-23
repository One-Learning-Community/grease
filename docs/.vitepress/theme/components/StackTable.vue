<script setup lang="ts">
// Renders the cumulative-stack matrix from the live benchmark JSON
// (benchmarks/stack_pipeline.php --json). Each row is a route; columns are the Grease
// levels layered in least→riskiest. The first column is the vanilla baseline (absolute
// time); the rest are the cumulative Δ vs vanilla at that level.
//
// Regenerate with:  bash benchmarks/export-metrics.sh
import bench from '../../data/benchmarks.json'

const stack = (bench as any).stack as
  | {
      levels: string[]
      routes: { route: string; vanilla_us: number; deltas: number[] }[]
      memory: { retained_mb: number[]; retained_delta_pct: number[]; peak_mb: number[] }
    }
  | undefined

const fmtTime = (us: number) =>
  us >= 1000 ? (us / 1000).toFixed(2) + ' ms' : us.toFixed(0) + ' µs'
const fmtPct = (d: number) =>
  d === 0 ? '—' : (d > 0 ? '+' : '−') + Math.abs(d).toFixed(0) + '%'
</script>

<template>
  <div v-if="!stack" class="bench-empty">
    No stack data yet — run <code>bash benchmarks/export-metrics.sh</code>.
  </div>
  <div v-else class="stack-wrap">
    <table class="bench-table stack-table">
      <thead>
        <tr>
          <th>Route — one request through the kernel</th>
          <th class="num">vanilla</th>
          <th v-for="lvl in stack.levels.slice(1)" :key="lvl" class="num">{{ lvl }}</th>
        </tr>
      </thead>
      <tbody>
        <tr v-for="row in stack.routes" :key="row.route">
          <td><code>{{ row.route }}</code></td>
          <td class="num">{{ fmtTime(row.vanilla_us) }}</td>
          <td v-for="(d, i) in row.deltas.slice(1)" :key="i" class="num delta">{{ fmtPct(d) }}</td>
        </tr>
      </tbody>
      <tfoot>
        <tr>
          <td>retained memory Δ</td>
          <td class="num">{{ stack.memory.retained_mb[0].toFixed(1) }} MB</td>
          <td v-for="(d, i) in stack.memory.retained_delta_pct.slice(1)" :key="i" class="num mem">
            {{ d > 0 ? '+' : '' }}{{ d.toFixed(1) }}%
          </td>
        </tr>
      </tfoot>
    </table>
  </div>
</template>

<style scoped>
.stack-wrap {
  overflow-x: auto;
}
.stack-table {
  display: table;
  width: 100%;
  border-collapse: collapse;
  margin: 1rem 0 0.5rem;
  font-size: 0.85rem;
}
.stack-table th,
.stack-table td {
  border: 1px solid var(--vp-c-divider);
  padding: 0.4rem 0.6rem;
  white-space: nowrap;
}
.stack-table th {
  background: var(--vp-c-bg-soft);
  text-align: left;
}
.stack-table .num {
  text-align: right;
  font-variant-numeric: tabular-nums;
}
.stack-table .delta {
  font-weight: 700;
  color: var(--vp-c-brand-1);
}
.stack-table tfoot td {
  color: var(--vp-c-text-2);
  font-style: italic;
}
.bench-empty {
  color: var(--vp-c-text-2);
  font-size: 0.85rem;
}
</style>
