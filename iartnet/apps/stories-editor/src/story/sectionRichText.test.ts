import { describe, expect, it } from 'vitest'
import { parseSectionFromWire } from './sectionWire'

describe('parseSectionFromWire rich text normalization', () => {
  it('TextIntro: string[] → string HTML', () => {
    const r = parseSectionFromWire({
      Kind: 'TextIntro',
      Text: ['<b>Titolo</b>', '', 'Paragrafo'],
    })
    expect(r.ok).toBe(true)
    if (!r.ok) throw new Error(r.error)
    expect(r.section).toMatchObject({
      Kind: 'TextIntro',
      Text: '<b>Titolo</b><br /><br />Paragrafo',
      published: true,
      animazione: { Effetto: '' },
    })
  })

  it('SplitContent: normalizza LeftText e RightText', () => {
    const r = parseSectionFromWire({
      Kind: 'SplitContent',
      LeftText: 'sinistra',
      RightText: ['destra', 'due'],
    })
    expect(r.ok).toBe(true)
    if (!r.ok) throw new Error(r.error)
    expect(r.section).toMatchObject({
      Kind: 'SplitContent',
      LeftText: 'sinistra',
      RightText: 'destra<br />due',
      published: true,
      animazione: { Effetto: '' },
    })
  })

  it('ScrollReveal: normalizza Text in Paragraphs', () => {
    const r = parseSectionFromWire({
      Kind: 'ScrollReveal',
      Paragraphs: [{ Text: ['a', 'b'], Image: { URL: 'u' } }],
    })
    expect(r.ok).toBe(true)
    if (!r.ok) throw new Error(r.error)
    expect(r.section).toMatchObject({
      Kind: 'ScrollReveal',
      Paragraphs: [{ Text: 'a<br />b', Image: { URL: 'u' } }],
    })
  })

  it('IIFAnnotationsGroup: normalizza Caption e Text annotazioni', () => {
    const r = parseSectionFromWire({
      Kind: 'IIFAnnotationsGroup',
      Image: { BaseURI: 'https://ex.test/iiif/2/x.tif' },
      Caption: ['<b>Didascalia</b>', 'sottotitolo'],
      Annotations: [{ Text: ['a', 'b'], Rect: { x: 0, y: 0, width: 1, height: 1 } }],
    })
    expect(r.ok).toBe(true)
    if (!r.ok) throw new Error(r.error)
    expect(r.section).toMatchObject({
      Caption: '<b>Didascalia</b><br />sottotitolo',
      Annotations: [{ Text: 'a<br />b' }],
    })
  })
})
