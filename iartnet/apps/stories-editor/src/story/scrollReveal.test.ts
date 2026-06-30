import { describe, expect, it } from 'vitest'
import { createEmptySection } from './sectionKind'
import { normalizeScrollReveal } from './scrollReveal'
import { parseSectionFromWire } from './sectionWire'
import type { TStoryScrollRevealType } from '../types/story'

describe('normalizeScrollReveal', () => {
  it('preserva Paragraphs', () => {
    const section = createEmptySection('ScrollReveal') as TStoryScrollRevealType
    expect(normalizeScrollReveal(section)).toEqual(section)
  })
})

describe('parseSectionFromWire ScrollReveal', () => {
  it('rifiuta Paragraphs vuoto', () => {
    const r = parseSectionFromWire({
      Kind: 'ScrollReveal',
      Paragraphs: [],
    })
    expect(r.ok).toBe(false)
    if (r.ok) throw new Error('expected fail')
    expect(r.error).toMatch(/Paragraphs/)
  })

  it('rifiuta wire senza Paragraphs', () => {
    const r = parseSectionFromWire({
      Kind: 'ScrollReveal',
      Type: 'HalfInlineVertical',
      Text: 'hello',
      Image: { URL: 'https://img.test/1.jpg' },
    })
    expect(r.ok).toBe(false)
    if (r.ok) throw new Error('expected fail')
    expect(r.error).toMatch(/Paragraphs/)
  })

  it('accetta Paragraphs multipli', () => {
    const r = parseSectionFromWire({
      Kind: 'ScrollReveal',
      Paragraphs: [
        { Text: 'uno', Image: { URL: 'a' } },
        { Text: ['due', 'tre'], Image: { URL: 'b' } },
      ],
    })
    expect(r.ok).toBe(true)
    if (!r.ok) throw new Error(r.error)
    expect(r.section).toMatchObject({
      Kind: 'ScrollReveal',
      Paragraphs: [
        { Text: 'uno', Image: { URL: 'a' } },
        { Text: 'due<br />tre', Image: { URL: 'b' } },
      ],
    })
  })
})
