import DefaultTheme from 'vitepress/theme'
import './custom.css'
import BenchTable from './components/BenchTable.vue'

export default {
  extends: DefaultTheme,
  enhanceApp({ app }) {
    // Live macro numbers, rendered from docs/.vitepress/data/benchmarks.json.
    app.component('BenchTable', BenchTable)
  },
}
