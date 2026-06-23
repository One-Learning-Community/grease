import DefaultTheme from 'vitepress/theme'
import './custom.css'
import BenchTable from './components/BenchTable.vue'
import StackTable from './components/StackTable.vue'

export default {
  extends: DefaultTheme,
  enhanceApp({ app }) {
    // Live numbers, rendered from docs/.vitepress/data/benchmarks.json.
    app.component('BenchTable', BenchTable)
    app.component('StackTable', StackTable)
  },
}
