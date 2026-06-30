import { describe, expect, it } from 'vitest'
import {
  newClientId,
  newSectionRow,
  rowsFromSections,
  sectionsFromRows,
} from './sectionRow'
import { createEmptySection } from './sectionKind'

const BASE = { published: true, animazione: { Effetto: '' } }

describe('sectionRow', () => {
  it('newClientId senza crypto.randomUUID usa fallback', () => {
    const prev = globalThis.crypto
    Object.defineProperty(globalThis, 'crypto', {
      value: {},
      configurable: true,
    })
    try {
      const id = newClientId()
      expect(id).toMatch(/^id-\d+-/)
    } finally {
      Object.defineProperty(globalThis, 'crypto', {
        value: prev,
        configurable: true,
      })
    }
  })

  it('newSectionRow crea riga con sezione vuota per kind', () => {
    const row = newSectionRow('SplitImage')
    expect(row.section.Kind).toBe('SplitImage')
    expect(row.section).toEqual(expect.objectContaining({ Layout: 'Right', Text: '' }))
  })

  it('rowsFromSections + sectionsFromRows preserva i contenuti delle sezioni', () => {
    const sections = [
      { Kind: 'TextIntro' as const, Text: 'solo testo', ...BASE },
      { Kind: 'SplitContent' as const, LeftText: 'L', RightText: 'R', ...BASE },
    ]
    const rows = rowsFromSections(sections)
    expect(rows).toHaveLength(2)
    expect(rows[0]!.section.Kind).toBe('TextIntro')
    expect(rows[1]!.section.Kind).toBe('SplitContent')
    expect(sectionsFromRows(rows)).toEqual(sections)
  })

  it('rowsFromSections rispetta Kind InlineText', () => {
    const rows = rowsFromSections([{ Kind: 'InlineText', Text: 'inline', ...BASE }])
    expect(rows[0]!.section.Kind).toBe('InlineText')
    expect(rows[0]!.section).toMatchObject({ Text: 'inline', ...BASE })
  })

  it('rowsFromSections + sectionsFromRows preserva IIFAnnotationsGroup', () => {
    const wire = {
      Kind: 'IIFAnnotationsGroup' as const,
      ...BASE,
      Image: {
        BaseURI: 'https://iiif.gpams.it/iiif/2/uuid.tif',
        Width: 5153,
        Height: 7064,
        bgColor: null,
      },
      Caption: null,
      Annotations: [{ Text: 'nota', Rect: { x: 1, y: 2, width: 3, height: 4 } }],
    }
    const rows = rowsFromSections([wire])
    expect(rows[0]!.section.Kind).toBe('IIFAnnotationsGroup')
    expect(sectionsFromRows(rows)).toEqual([wire])
  })

  it('sectionsFromRows normalizza sezione creata da createEmptySection', () => {
    const rows = [{ id: 'a', section: createEmptySection('TextIntro') }]
    expect(sectionsFromRows(rows)[0]).toEqual(createEmptySection('TextIntro'))
  })
})
