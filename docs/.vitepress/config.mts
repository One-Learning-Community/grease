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
          "Opt-in performance for Laravel's hot paths. One trait, byte-identical output — and it really shines under Octane.",
      },
    ],
    ['meta', { property: 'og:type', content: 'website' }],
    ['meta', { property: 'og:url', content: 'https://one-learning-community.github.io/grease/' }],
    ['meta', { property: 'og:image', content: 'https://one-learning-community.github.io/grease/og-image.png' }],
    ['meta', { name: 'twitter:card', content: 'summary_large_image' }],
    ['meta', { name: 'twitter:image', content: 'https://one-learning-community.github.io/grease/og-image.png' }],
    // Cloudflare Web Analytics (public beacon token; cookieless). Keyed on the github.io
    // hostname, so the same token serves every One-Learning-Community docs site — filter by
    // page path (/grease/…) in the CF dashboard to separate projects.
    [
      'script',
      {
        defer: '',
        src: 'https://static.cloudflareinsights.com/beacon.min.js',
        'data-cf-beacon': '{"token": "b9e858dff0e743cf852f98b0b4a491a0"}',
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
        // Static label, not a version string — Packagist/Releases always show the current
        // version, so there's nothing to bump here on each release.
        text: 'Releases',
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
            { text: 'Grease & Octane', link: '/guide/octane' },
            { text: 'Grease & Livewire', link: '/guide/livewire' },
            { text: 'Getting Started', link: '/guide/getting-started' },
          ],
        },
        {
          text: 'Under the Hood',
          items: [
            { text: 'How It Works', link: '/guide/how-it-works' },
            { text: 'Serialization Helpers', link: '/guide/serialization-helpers' },
            { text: 'Benchmarks', link: '/guide/benchmarks' },
            { text: 'The Method', link: '/guide/method' },
            { text: 'The Event Dispatcher', link: '/guide/events' },
            { text: 'Blade Components', link: '/guide/blade' },
            { text: 'The Container', link: '/guide/container' },
            { text: 'The Request', link: '/guide/request' },
            { text: 'The Config Repository', link: '/guide/config' },
            { text: 'Validation', link: '/guide/validation' },
            { text: 'The Router', link: '/guide/routing' },
            { text: 'The View Cache', link: '/guide/view-cache' },
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
