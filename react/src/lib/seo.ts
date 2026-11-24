export interface SeoDocument {
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

/** يحدّث عنوان التبويب وبطاقة المشاركة بعد ما تُعرف لغة القارئ. */
export function applySeo(meta: SeoDocument) {
  document.title = meta.title
  setMeta('name', 'description', meta.description)
  setMeta('name', 'robots', meta.robots)
  setMeta('property', 'og:site_name', meta.site_name)
  setMeta('property', 'og:locale', meta.locale)
  setMeta('property', 'og:type', meta.type === 'product' ? 'product' : 'website')
  setMeta('property', 'og:title', meta.title)
  setMeta('property', 'og:description', meta.description)
  setMeta('property', 'og:url', meta.url)
  setMeta('property', 'og:image', meta.image)
  setMeta('property', 'og:image:alt', meta.image_alt)
  setMeta('name', 'twitter:card', 'summary_large_image')
  setMeta('name', 'twitter:title', meta.title)
  setMeta('name', 'twitter:description', meta.description)
  setMeta('name', 'twitter:image', meta.image)

  let canonical = document.head.querySelector<HTMLLinkElement>('link[rel="canonical"]')
  if (!canonical) {
    canonical = document.createElement('link')
    canonical.rel = 'canonical'
    document.head.appendChild(canonical)
  }
  canonical.href = meta.url

  let script = document.getElementById('seo-ld')
  if (!script) {
    script = document.createElement('script')
    script.id = 'seo-ld'
    script.setAttribute('type', 'application/ld+json')
    document.head.appendChild(script)
  }
  script.textContent = JSON.stringify(meta.json_ld)
}

function setMeta(attr: 'name' | 'property', key: string, content: string) {
  let element = document.head.querySelector<HTMLMetaElement>(`meta[${attr}="${key}"]`)
  if (!element) {
    element = document.createElement('meta')
    element.setAttribute(attr, key)
    document.head.appendChild(element)
  }
  element.content = content
}
