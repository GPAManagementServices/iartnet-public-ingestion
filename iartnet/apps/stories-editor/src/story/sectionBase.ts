import type {
  SectionKind,
  TStoryAnimazione,
  TStoryImageType,
  TStorySectionBase,
} from '../types/story'

export const DEFAULT_SECTION_PUBLISHED = true

export const DEFAULT_STORY_ANIMATION: TStoryAnimazione = { Effetto: '' }

export function createSectionBase(kind: SectionKind): TStorySectionBase {
  return {
    Kind: kind,
    published: DEFAULT_SECTION_PUBLISHED,
    animazione: { ...DEFAULT_STORY_ANIMATION },
  }
}

export function normalizeSectionBgColor(raw: unknown): string | null {
  if (raw == null) return null
  if (typeof raw !== 'string') return null
  const trimmed = raw.trim()
  return trimmed === '' ? null : raw
}

export function isSectionBgImageEmpty(image?: TStoryImageType | null): boolean {
  if (!image) return true
  if (image.URL?.trim()) return false
  if (image.Caption?.trim()) return false
  if (normalizeSectionBgColor(image.bgColor)) return false
  return true
}

export function normalizeSectionBgImage(raw: unknown): TStoryImageType | null {
  if (raw == null) return null
  if (typeof raw !== 'object' || Array.isArray(raw)) return null
  const record = raw as Record<string, unknown>
  const url = typeof record.URL === 'string' ? record.URL : ''
  const caption =
    'Caption' in record && record.Caption !== null && record.Caption !== undefined
      ? String(record.Caption)
      : null
  const captionNorm = caption?.trim() ? caption : null
  const bgColor = 'bgColor' in record ? normalizeSectionBgColor(record.bgColor) : null

  if (!url.trim() && !captionNorm && !bgColor) return null

  const image: TStoryImageType = { URL: url }
  if ('Caption' in record) {
    image.Caption = captionNorm
  }
  if (bgColor !== null) {
    image.bgColor = bgColor
  }
  return image
}

export function normalizeSectionBase(
  raw: Record<string, unknown>,
  kind: SectionKind,
): TStorySectionBase {
  const published =
    typeof raw.published === 'boolean' ? raw.published : DEFAULT_SECTION_PUBLISHED

  let effetto = DEFAULT_STORY_ANIMATION.Effetto
  if (raw.animazione && typeof raw.animazione === 'object' && !Array.isArray(raw.animazione)) {
    const anim = raw.animazione as Record<string, unknown>
    if (typeof anim.Effetto === 'string') {
      effetto = anim.Effetto
    }
  }

  const base: TStorySectionBase = {
    Kind: kind,
    published,
    animazione: { Effetto: effetto },
  }

  if ('foreColor' in raw) {
    const foreColor = normalizeSectionBgColor(raw.foreColor)
    if (foreColor !== null) {
      base.foreColor = foreColor
    }
  }

  if ('bgColor' in raw) {
    const bgColor = normalizeSectionBgColor(raw.bgColor)
    if (bgColor !== null) {
      base.bgColor = bgColor
    }
  }

  if ('bgImage' in raw) {
    const bgImage = normalizeSectionBgImage(raw.bgImage)
    if (bgImage !== null) {
      base.bgImage = bgImage
    }
  }

  return base
}
