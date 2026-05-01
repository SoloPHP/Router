import { defineConfig } from 'vitepress'

export default defineConfig({
  title: 'Solo Router',
  description: 'High-performance PHP router with middleware, groups, and advanced optional segments',
  base: '/Router/',
  
  head: [
    ['link', { rel: 'icon', type: 'image/svg+xml', href: '/Router/logo.svg' }],
    ['meta', { name: 'theme-color', content: '#f59e0b' }],
    ['meta', { property: 'og:type', content: 'website' }],
    ['meta', { property: 'og:title', content: 'Solo Router' }],
    ['meta', { property: 'og:description', content: 'High-performance PHP router with middleware, groups, and advanced optional segments' }],
  ],

  themeConfig: {
    logo: '/logo.svg',
    
    nav: [
      { text: 'Guide', link: '/guide/installation' },
      { text: 'Features', link: '/features/parameters' },
      { text: 'API', link: '/api/route-collector' },
      { text: 'v3.2.1', link: 'https://github.com/solophp/router/releases' },
      {
        text: 'Links',
        items: [
          { text: 'GitHub', link: 'https://github.com/solophp/router' },
          { text: 'Packagist', link: 'https://packagist.org/packages/solophp/router' },
          { text: 'SoloPHP', link: 'https://github.com/solophp' }
        ]
      }
    ],

    sidebar: [
      {
        text: 'Getting Started',
        items: [
          { text: 'Installation', link: '/guide/installation' },
          { text: 'Quick Start', link: '/guide/quick-start' },
          { text: 'Handlers', link: '/guide/handlers' }
        ]
      },
      {
        text: 'Features',
        items: [
          { text: 'Route Parameters', link: '/features/parameters' },
          { text: 'Optional Segments', link: '/features/optional-segments' },
          { text: 'Route Groups', link: '/features/groups' },
          { text: 'Middleware', link: '/features/middleware' },
          { text: 'Named Routes', link: '/features/named-routes' }
        ]
      },
      {
        text: 'API Reference',
        items: [
          { text: 'RouteCollector', link: '/api/route-collector' },
          { text: 'Router', link: '/api/router' },
          { text: 'Route', link: '/api/route' }
        ]
      }
    ],

    socialLinks: [
      { icon: 'github', link: 'https://github.com/solophp/router' }
    ],

    footer: {
      message: 'Released under the MIT License.',
      copyright: `Copyright © 2025-${new Date().getFullYear()} SoloPHP`
    },

    search: {
      provider: 'local'
    },

    editLink: {
      pattern: 'https://github.com/solophp/router/edit/main/docs/:path',
      text: 'Edit this page on GitHub'
    }
  }
})
