import {
  parseHeaderLayout,
  parseHeaderLayoutTheme,
  type TStoryHeaderSEOType,
  type TStorySection,
  type TStoriesExtJson,
  type TStoriesTypeData,
} from '../types/story'
import { createDefaultExtJson, resolveHeaderFontColor } from './defaults'
import { normalizeSeoSlugInput } from './seoSlug'
import { normalizeSection, parseSectionFromWire } from './sectionWire'

export type ParseStoryResult =
  | { ok: true; value: TStoriesTypeData }
  | { ok: false; error: string }

function isPlainObject(v: unknown): v is Record<string, unknown> {
  return typeof v === 'object' && v !== null && !Array.isArray(v)
}

function parseHeaderSeo(raw: unknown): TStoryHeaderSEOType | null {
  if (raw === null || raw === undefined) return null
  if (!isPlainObject(raw)) return null

  if (typeof raw.slug === 'string') {
    const slug = normalizeSeoSlugInput(raw.slug)
    return slug ? { slug } : null
  }

  // Retrocompat: Header.SEO.URL (deprecato)
  if (typeof raw.URL === 'string') {
    const slug = normalizeSeoSlugInput(raw.URL)
    return slug ? { slug } : null
  }

  return null
}

function parseExtJson(raw: unknown): TStoriesExtJson | string {
  if (!isPlainObject(raw)) return 'ext_json deve essere un oggetto'
  const header = raw.Header
  if (!isPlainObject(header)) return 'ext_json.Header mancante o non oggetto'
  const layout = parseHeaderLayout(header.Layout)
  if (layout === null) {
    return 'ext_json.Header.Layout non valido'
  }
  const sections = raw.sections
  if (!Array.isArray(sections)) return 'ext_json.sections deve essere un array'

  const parsedSections: TStorySection[] = []
  for (let i = 0; i < sections.length; i++) {
    const sec = sections[i]
    const parsed = parseSectionFromWire(sec)
    if (parsed.ok === false) {
      return `ext_json.sections[${i}]: ${parsed.error}`
    }
    parsedSections.push(normalizeSection(parsed.section))
  }

  const ext: TStoriesExtJson = {
    Header: {
      Layout: layout,
      Title: (header.Title as string | null | undefined) ?? null,
      SubTitle: (header.SubTitle as string | null | undefined) ?? null,
      SEO: parseHeaderSeo(header.SEO),
      FontColor: resolveHeaderFontColor(header.FontColor as string | null | undefined),
      Chip: (header.Chip as string | null | undefined) ?? null,
      Image: (header.Image as TStoriesExtJson['Header']['Image']) ?? null,
      IndexImage: (header.IndexImage as TStoriesExtJson['Header']['IndexImage']) ?? null,
      HeaderLayoutTheme: parseHeaderLayoutTheme(header.HeaderLayoutTheme),
    },
    sections: parsedSections,
  }

  if (raw.bibliography !== undefined) {
    if (!Array.isArray(raw.bibliography)) return 'ext_json.bibliography deve essere un array'
    ext.bibliography = raw.bibliography as TStoriesExtJson['bibliography']
  }
  if (raw.sitography !== undefined) {
    if (!Array.isArray(raw.sitography)) return 'ext_json.sitography deve essere un array'
    ext.sitography = raw.sitography as TStoriesExtJson['sitography']
  }
  if (raw.credits !== undefined) {
    if (!Array.isArray(raw.credits)) return 'ext_json.credits deve essere un array'
    ext.credits = raw.credits as TStoriesExtJson['credits']
  }
  if (raw.catalogoOpereCitate !== undefined) {
    if (!Array.isArray(raw.catalogoOpereCitate))
      return 'ext_json.catalogoOpereCitate deve essere un array'
    ext.catalogoOpereCitate =
      raw.catalogoOpereCitate as TStoriesExtJson['catalogoOpereCitate']
  }

  return ext
}

/** Parse dell'intero record story (metadata + ext_json). */
export function parseStoryJson(text: string): ParseStoryResult {
  let parsed: unknown
  try {
    parsed = JSON.parse(text) as unknown
  } catch {
    return { ok: false, error: 'JSON non valido' }
  }
  if (!isPlainObject(parsed)) {
    return { ok: false, error: 'La radice deve essere un oggetto' }
  }

  const extRaw = parsed.ext_json
  const extParsed = parseExtJson(extRaw)
  if (typeof extParsed === 'string') {
    return { ok: false, error: extParsed }
  }

  const story: TStoriesTypeData = {
    id: String(parsed.id ?? ''),
    name: String(parsed.name ?? ''),
    description: String(parsed.description ?? ''),
    created_at: String(parsed.created_at ?? ''),
    updated_at: String(parsed.updated_at ?? ''),
    publish_state: String(parsed.publish_state ?? ''),
    ext_json: extParsed,
  }

  return { ok: true, value: story }
}

/** Parse solo di ext_json (oggetto o stringa JSON). */
export function parseExtJsonString(text: string): ParseStoryResult {
  let parsed: unknown
  try {
    parsed = JSON.parse(text) as unknown
  } catch {
    return { ok: false, error: 'JSON non valido' }
  }
  const extParsed = parseExtJson(parsed)
  if (typeof extParsed === 'string') {
    return { ok: false, error: extParsed }
  }
  const base = createMinimalStoryForExt()
  base.ext_json = extParsed
  return { ok: true, value: base }
}

function createMinimalStoryForExt(): TStoriesTypeData {
  return {
    id: '',
    name: '',
    description: '',
    created_at: '',
    updated_at: '',
    publish_state: '',
    ext_json: createDefaultExtJson(),
  }
}
