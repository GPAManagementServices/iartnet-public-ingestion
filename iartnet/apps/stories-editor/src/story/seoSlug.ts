/** Slug SEO: lowercase, segmenti kebab-case, no trattini in testa/coda. */
export const SEO_SLUG_PATTERN = /^[a-z0-9]+(?:-[a-z0-9]+)*$/

export const SEO_SLUG_MAX_LENGTH = 80

function stripAccents(value: string): string {
  return value.normalize('NFKD').replace(/\p{M}/gu, '')
}

function wordsToSlugParts(value: string): string[] {
  let normalized = stripAccents(value)
  normalized = normalized.replace(/\b([A-Za-z])'([a-z])/g, '$1-$2')
  normalized = normalized.replace(/'s(?=\s|$)/gi, 's')
  normalized = normalized.replace(/[''`]/g, '')

  return normalized
    .toLowerCase()
    .replace(/[^a-z0-9]+/g, ' ')
    .trim()
    .split(/\s+/)
    .filter(Boolean)
}

function truncateSlugParts(parts: string[], maxLength: number): string {
  let slug = parts.join('-')
  if (slug.length <= maxLength) return slug

  const kept: string[] = []
  for (const part of parts) {
    const next = kept.length === 0 ? part : `${kept.join('-')}-${part}`
    if (next.length > maxLength) break
    kept.push(part)
  }

  slug = kept.join('-')
  if (slug) return slug

  return parts[0]!.slice(0, maxLength).replace(/-+$/g, '')
}

/** Da titolo → slug puro kebab-case. */
export function titleToSeoSlug(title: string, maxLength = SEO_SLUG_MAX_LENGTH): string {
  const parts = wordsToSlugParts(title.trim())
  if (parts.length === 0) return ''
  return truncateSlugParts(parts, maxLength)
}

/** Normalizza input utente (slug, path o URL) → slug puro o stringa vuota. */
export function normalizeSeoSlugInput(raw: string): string {
  let value = raw.trim()
  if (!value) return ''

  value = value.replace(/^https?:\/\/[^/]+/i, '')
  value = value.replace(/^\/+|\/+$/g, '')
  if (value.includes('/')) {
    value = value.split('/').filter(Boolean).pop() ?? ''
  }

  return titleToSeoSlug(value)
}

export function isValidSeoSlug(slug: string): boolean {
  return SEO_SLUG_PATTERN.test(slug)
}

export function seoSlugMatchesTitle(slug: string, title: string | null | undefined): boolean {
  const expected = titleToSeoSlug(title?.trim() ?? '')
  if (!expected) return !slug.trim()
  return slug === expected
}
