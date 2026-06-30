import { describe, expect, it } from 'vitest'
import {
  SECTION_KIND_LABELS,
  changeSectionKind,
  createEmptySection,
} from './sectionKind'

const BASE = { published: true, animazione: { Effetto: '' } }

describe('sectionKind', () => {
  it('createEmptySection copre tutti i tipi con campi base', () => {
    const kinds = Object.keys(SECTION_KIND_LABELS) as (keyof typeof SECTION_KIND_LABELS)[]
    for (const k of kinds) {
      const s = createEmptySection(k)
      expect(s.Kind).toBe(k)
      expect(s).toMatchObject(BASE)
      if (k === 'TextIntro' || k === 'InlineText') {
        expect(s).toMatchObject({ Text: '' })
      }
    }
  })

  it('createEmptySection SplitImage include MediaType Image', () => {
    expect(createEmptySection('SplitImage')).toMatchObject({ MediaType: 'Image' })
  })

  it('createEmptySection ScrollReveal include Paragraphs', () => {
    expect(createEmptySection('ScrollReveal')).toMatchObject({
      Paragraphs: [expect.objectContaining({ Text: '', Image: { URL: '' } })],
    })
  })

  it('createEmptySection IIFAnnotationsGroup', () => {
    expect(createEmptySection('IIFAnnotationsGroup')).toEqual({
      Kind: 'IIFAnnotationsGroup',
      ...BASE,
      Image: { BaseURI: '', Width: null, Height: null, bgColor: null },
      Caption: null,
      Annotations: [],
    })
  })

  it('changeSectionKind TextIntro → InlineText preserva Text e base', () => {
    const section = {
      ...createEmptySection('TextIntro'),
      Text: 'contenuto',
      published: false,
      animazione: { Effetto: 'zoom' },
    }
    const next = changeSectionKind(section, 'InlineText')
    expect(next).toMatchObject({
      Kind: 'InlineText',
      Text: 'contenuto',
      published: false,
      animazione: { Effetto: 'zoom' },
    })
  })

  it('changeSectionKind verso altro template resetta payload ma conserva base', () => {
    const section = {
      ...createEmptySection('TextIntro'),
      Text: 'x',
      published: false,
      animazione: { Effetto: 'fade' },
      bgColor: 'rgba(0,0,0,1)',
      foreColor: 'rgba(255,255,255,1)',
      bgImage: { URL: 'https://ex.test/bg.jpg' },
    }
    const next = changeSectionKind(section, 'SplitImage')
    expect(next.Kind).toBe('SplitImage')
    expect(next.published).toBe(false)
    expect(next.animazione).toEqual({ Effetto: 'fade' })
    expect(next.foreColor).toBe('rgba(255,255,255,1)')
    expect(next.bgColor).toBe('rgba(0,0,0,1)')
    expect(next.bgImage).toEqual({ URL: 'https://ex.test/bg.jpg' })
    expect(next).toMatchObject({ Layout: 'Right', Text: '' })
  })
})
