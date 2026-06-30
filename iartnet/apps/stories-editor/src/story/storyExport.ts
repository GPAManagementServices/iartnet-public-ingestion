import type { TStoriesTypeData, TStorySection } from '../types/story'
import { normalizeSection } from './sectionWire'

/** Ri-normalizza una sezione (HTML string, campi base, payload per Kind). */
export function normalizeSectionForExport(section: TStorySection): TStorySection {
  return normalizeSection(section)
}

/** Story pronta per serializzazione JSON: testi ricchi sempre `string` HTML. */
export function normalizeStoryForExport(story: TStoriesTypeData): TStoriesTypeData {
  return {
    ...story,
    ext_json: {
      ...story.ext_json,
      sections: story.ext_json.sections.map(normalizeSectionForExport),
    },
  }
}

/** Campi testo ricco nelle sezioni (non include Image.Caption generica). */
const RICH_TEXT_KEYS = new Set(['Text', 'LeftText', 'RightText'])

/** Verifica che nel payload export non compaiano `string[]` sui campi testo ricco. */
export function findLegacyRichTextArrays(value: unknown, path = ''): string[] {
  const issues: string[] = []
  if (Array.isArray(value)) {
    if (path.endsWith('.Text') || path.endsWith('.LeftText') || path.endsWith('.RightText')) {
      issues.push(path)
    }
    value.forEach((item, i) => {
      issues.push(...findLegacyRichTextArrays(item, `${path}[${i}]`))
    })
    return issues
  }
  if (!value || typeof value !== 'object') return issues
  for (const [key, child] of Object.entries(value as Record<string, unknown>)) {
    const childPath = path ? `${path}.${key}` : key
    if (RICH_TEXT_KEYS.has(key) && Array.isArray(child)) {
      issues.push(childPath)
    } else {
      issues.push(...findLegacyRichTextArrays(child, childPath))
    }
  }
  return issues
}
