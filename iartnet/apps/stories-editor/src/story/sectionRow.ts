import type { SectionKind, TStorySection, TStoriesTypeData } from '../types/story'
import { createEmptySection } from './sectionKind'
import { normalizeSection, parseSectionFromWire } from './sectionWire'

export type SectionRow = {
  id: string
  section: TStorySection
}

export function newSectionRow(kind: SectionKind = 'TextIntro'): SectionRow {
  return {
    id: newClientId(),
    section: createEmptySection(kind),
  }
}

export function newClientId(): string {
  return (
    globalThis.crypto?.randomUUID?.() ??
    `id-${Date.now()}-${Math.random().toString(36).slice(2, 11)}`
  )
}

export function rowsFromSections(sections: TStorySection[]): SectionRow[] {
  return sections.map((wire) => {
    const parsed = parseSectionFromWire(wire)
    return {
      id: newClientId(),
      section: parsed.ok ? parsed.section : wire,
    }
  })
}

/** Story pronta per export JSON con sezioni normalizzate. */
export function storyWithWireSections(story: TStoriesTypeData, rows: SectionRow[]): TStoriesTypeData {
  return {
    ...story,
    ext_json: {
      ...story.ext_json,
      sections: sectionsFromRows(rows),
    },
  }
}

export function sectionsFromRows(rows: SectionRow[]): TStorySection[] {
  return rows.map((r) => normalizeSection(r.section))
}
