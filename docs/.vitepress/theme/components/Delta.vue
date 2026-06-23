<script setup lang="ts">
// One delta percentage, pulled live from docs/.vitepress/data/benchmarks.json by key —
// so a number quoted inline in prose tracks the same JSON the tables render from, and
// never needs hand-editing after a re-measure. Regenerate with:
//   bash benchmarks/export-metrics.sh
//
//   <Delta k="page-simple" />            → −28%   (blade section, p50, whole percent)
//   <Delta section="perOp" k="hydrate" /> → −34%
//   <Delta k="page-full" :digits="1" />  → −8.7%
import bench from '../../data/benchmarks.json'

const props = withDefaults(
  defineProps<{ k: string; section?: string; pct?: number; digits?: number }>(),
  { section: 'blade', pct: 50, digits: 0 },
)

const data: any[] = (bench as any)[props.section] ?? []
const row = data.find((e: any) => e.key === props.k)
// macro/blade carry percentiles; perOp/events hold the delta on the row itself.
const src = row?.percentiles?.[String(props.pct)] ?? row
const d: number | undefined = src?.delta_pct

const text =
  d == null
    ? `?${props.k}?`
    : (d > 0 ? '+' : '−') + Math.abs(d).toFixed(props.digits) + '%'
</script>

<template><strong>{{ text }}</strong></template>
