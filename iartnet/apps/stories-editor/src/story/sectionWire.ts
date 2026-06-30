import type {
  SectionKind,
  TStoryIIFAnnotationsGroupType,
  TStoryScrollRevealType,
  TStorySection,
  TStorySplitImageType,
} from '../types/story'
import { normalizeIIFAnnotationsGroup } from './iiifImage'
import { normalizeScrollReveal } from './scrollReveal'
import { normalizeSplitImage } from './splitImage'
import { normalizeSectionRichTextFields } from './sectionRichText'
import { SECTION_KIND_LABELS, STORY_SECTION_KIND_FIELD } from './sectionKind'
import { normalizeSectionBase } from './sectionBase'

export { STORY_SECTION_KIND_FIELD }

const SECTION_KINDS = new Set<SectionKind>(
  Object.keys(SECTION_KIND_LABELS) as SectionKind[],
)

const SPLIT_LAYOUTS = new Set<string>([
  'Right',
  'Left',
  'RightInline',
  'LeftInline',
  'RightInlineVertical',
  'LeftInlineVertical',
])

export function parseSectionKindField(raw: unknown): SectionKind | null {
  if (raw === undefined || raw === null) return null
  if (typeof raw !== 'string') return null
  return SECTION_KINDS.has(raw as SectionKind) ? (raw as SectionKind) : null
}

function validateSectionPayload(kind: SectionKind, record: Record<string, unknown>): string | null {
  switch (kind) {
    case 'TextIntro':
    case 'InlineText':
      if (!('Text' in record)) return 'campo Text mancante'
      return null
    case 'SplitContent':
      if (!('LeftText' in record) || !('RightText' in record)) {
        return 'campi LeftText/RightText mancanti'
      }
      return null
    case 'SplitImage': {
      if (!('Layout' in record) || typeof record.Layout !== 'string') {
        return 'campo Layout mancante o non valido'
      }
      if (!SPLIT_LAYOUTS.has(record.Layout)) return 'Layout SplitImage non valido'
      if (!('Text' in record)) return 'campo Text mancante'
      if (!('Image' in record)) return 'campo Image mancante'
      return null
    }
    case 'ScrollReveal': {
      if (!Array.isArray(record.Paragraphs) || record.Paragraphs.length === 0) {
        return 'campo Paragraphs mancante o vuoto'
      }
      for (let i = 0; i < record.Paragraphs.length; i++) {
        const paragraph = record.Paragraphs[i]
        if (!paragraph || typeof paragraph !== 'object' || Array.isArray(paragraph)) {
          return `Paragraphs[${i}] non valido`
        }
        const p = paragraph as Record<string, unknown>
        if (!('Text' in p)) return `Paragraphs[${i}].Text mancante`
        if (!('Image' in p)) return `Paragraphs[${i}].Image mancante`
      }
      return null
    }
    case 'InlineImage':
      if (!('Image' in record)) return 'campo Image mancante'
      return null
    case 'ImageFullScreen': {
      if (!('Position' in record)) return 'campo Position mancante'
      if (!('Fit' in record)) return 'campo Fit mancante'
      if (record.Fit !== 'Cover' && record.Fit !== 'Contain') return 'Fit non valido'
      if (!('Image' in record)) return 'campo Image mancante'
      return null
    }
    case 'IIFAnnotationsGroup':
      if (!('Image' in record)) return 'campo Image mancante'
      if (!('Annotations' in record) || !Array.isArray(record.Annotations)) {
        return 'campo Annotations mancante o non valido'
      }
      return null
    default: {
      const _k: never = kind
      return `tipo non supportato: ${_k}`
    }
  }
}

export type ParseSectionWireResult =
  | { ok: true; section: TStorySection }
  | { ok: false; error: string }

function normalizeParsedSection(section: TStorySection): TStorySection {
  const kind = section.Kind
  const base = normalizeSectionBase(section as unknown as Record<string, unknown>, kind)
  let payload: TStorySection
  if (kind === 'IIFAnnotationsGroup') {
    payload = normalizeIIFAnnotationsGroup(section as TStoryIIFAnnotationsGroupType)
  } else if (kind === 'SplitImage') {
    payload = normalizeSplitImage(section as TStorySplitImageType)
  } else if (kind === 'ScrollReveal') {
    payload = normalizeScrollReveal(section as TStoryScrollRevealType)
  } else {
    payload = section
  }
  return normalizeSectionRichTextFields(kind, { ...payload, ...base } as TStorySection)
}

export function parseSectionFromWire(raw: unknown): ParseSectionWireResult {
  if (!raw || typeof raw !== 'object' || Array.isArray(raw)) {
    return { ok: false, error: 'la sezione deve essere un oggetto' }
  }

  const record = raw as Record<string, unknown>
  const hasKindField = STORY_SECTION_KIND_FIELD in record
  const explicitRaw = hasKindField ? record[STORY_SECTION_KIND_FIELD] : undefined

  if (!hasKindField || explicitRaw === undefined || explicitRaw === null) {
    return { ok: false, error: 'Kind mancante' }
  }

  const kind = parseSectionKindField(explicitRaw)
  if (!kind) {
    return { ok: false, error: `Kind non valido: ${String(explicitRaw)}` }
  }

  const payloadError = validateSectionPayload(kind, record)
  if (payloadError) {
    return { ok: false, error: payloadError }
  }

  const base = normalizeSectionBase(record, kind)
  const section = normalizeParsedSection({ ...record, ...base } as TStorySection)
  return { ok: true, section }
}

/** Ri-normalizza una sezione (HTML string, campi base, payload per Kind). */
export function normalizeSection(section: TStorySection): TStorySection {
  const parsed = parseSectionFromWire(section)
  if (!parsed.ok) return normalizeParsedSection(section)
  return parsed.section
}
