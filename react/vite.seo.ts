import type { IndexHtmlTransformContext, Plugin } from 'vite'

const API = process.env.SEO_API_ORIGIN ?? 'http://127.0.0.1:8000'

interface SeoDocument {
  title: string
  description: string
  image: string
  image_alt: string
  url: string
  type: string
  locale: string
  site_name: string
  robots: string
  json_ld: unknown
}

/** يحقن بطاقة المشاركة في أول HTML، فواتساب وغوغل يقرآنها قبل تشغيل الصفحة. */
export function seoPlugin(): Plugin {
  return {
    name: 'b2b-seo',
    async transformIndexHtml(html: string, ctx: IndexHtmlTransformContext) {
      const request = ctx.originalUrl ?? '/'
      if (request.startsWith('/@') || request.startsWith('/src/')) return html

      try {
        const endpoint = `${API}/seo.json?path=${encodeURIComponent(request.split('#')[0] || '/')}`
        const response = await fetch(endpoint, {
          headers: { Accept: 'application/json', 'X-Locale': 'ar' },
          signal: AbortSignal.timeout(800),
        })
        if (!response.ok) return html

        return inject(html, (await response.json()) as SeoDocument)
      } catch {
        return html
      }
    },
  }
}

function inject(html: string, meta: SeoDocument): string {
  const json = JSON.stringify(meta.json_ld).replace(/</g, '\\u003c')
  const tags = [
    metaTag('name', 'description', meta.description),
    metaTag('name', 'robots', meta.robots),
    `<link rel="canonical" href="${esc(meta.url)}">`,
    metaTag('property', 'og:site_name', meta.site_name),
    metaTag('property', 'og:locale', meta.locale),
    metaTag('property', 'og:type', meta.type === 'product' ? 'product' : 'website'),
    metaTag('property', 'og:title', meta.title),
    metaTag('property', 'og:description', meta.description),
    metaTag('property', 'og:url', meta.url),
    metaTag('property', 'og:image', meta.image),
    metaTag('property', 'og:image:alt', meta.image_alt),
    metaTag('name', 'twitter:card', 'summary_large_image'),
    metaTag('name', 'twitter:title', meta.title),
    metaTag('name', 'twitter:description', meta.description),
    metaTag('name', 'twitter:image', meta.image),
    `<script type="application/ld+json">${json}</script>`,
  ].join('\n    ')

  return html
    .replace(/<title>[\s\S]*?<\/title>/, `<title>${esc(meta.title)}</title>`)
    .replace('</head>', `    ${tags}\n  </head>`)
}

function metaTag(attr: 'name' | 'property', key: string, content: string): string {
  return `<meta ${attr}="${esc(key)}" content="${esc(content)}">`
}

function esc(value: string): string {
  return value
    .replace(/&/g, '&amp;')
    .replace(/"/g, '&quot;')
    .replace(/</g, '&lt;')
}
