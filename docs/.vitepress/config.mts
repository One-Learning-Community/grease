import { defineConfig } from 'vitepress'

// https://vitepress.dev/reference/site-config
export default defineConfig({
  title: 'Grease',
  description:
    "Opt-in performance for Laravel's hot paths — one trait, byte-identical output, real requests measurably faster end to end.",

  // GitHub Pages project site → One-Learning-Community.github.io/grease/.
  // Serving from a root/custom domain instead? Set this to '/'.
  base: '/grease/',

  cleanUrls: true,
  lastUpdated: true,

  head: [
    ['link', { rel: 'icon', href: '/grease/logo.svg', type: 'image/svg+xml' }],
    ['meta', { name: 'theme-color', content: '#e8a33d' }],
    ['meta', { property: 'og:title', content: 'Grease — Get greased.' }],
    [
      'meta',
      {
        property: 'og:description',
        content:
          "Opt-in performance for Laravel's hot paths. One trait, byte-identical output — built from optimizations declined upstream.",
      },
    ],
  ],

  themeConfig: {
    logo: '/logo.svg',

    nav: [
      { text: 'Benchmarks', link: '/guide/benchmarks' },
      { text: 'Guide', link: '/guide/getting-started', activeMatch: '/guide/' },
      { text: 'Why', link: '/guide/why' },
      {
        text: 'v0.1',
        items: [
          { text: 'Changelog', link: 'https://github.com/One-Learning-Community/grease/releases' },
          { text: 'Packagist', link: 'https://packagist.org/packages/onelearningcommunity/grease' },
        ],
      },
    ],

    sidebar: {
      '/guide/': [
        {
          text: 'Introduction',
          items: [
            { text: 'Why Grease', link: '/guide/why' },
            { text: 'Getting Started', link: '/guide/getting-started' },
          ],
        },
        {
          text: 'Under the Hood',
          items: [
            { text: 'How It Works', link: '/guide/how-it-works' },
            { text: 'Serialization Helpers', link: '/guide/serialization-helpers' },
            { text: 'Benchmarks', link: '/guide/benchmarks' },
            { text: 'The Event Dispatcher', link: '/guide/events' },
            { text: 'Blade Components', link: '/guide/blade' },
          ],
        },
        {
          text: 'Fine Print',
          items: [{ text: 'Caveats & Narrowing', link: '/guide/caveats' }],
        },
      ],
    },

    socialLinks: [
      { icon: 'github', link: 'https://github.com/One-Learning-Community/grease' },
    ],

    search: { provider: 'local' },

    editLink: {
      pattern:
        'https://github.com/One-Learning-Community/grease/edit/main/docs/:path',
      text: 'Edit this page on GitHub',
    },

    footer: {
      message: 'Byte-identical to vanilla, or it\'s a failing test. · MIT Licensed',
      copyright: 'Copyright © 2026 One Learning Community LTD',
    },
  },
})
