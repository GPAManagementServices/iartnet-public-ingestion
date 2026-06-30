import { describe, expect, it } from 'vitest'
import { createEmptySection } from './sectionKind'
import {
  normalizeSection,
  parseSectionFromWire,
  parseSectionKindField,
} from './sectionWire'

const BASE = { published: true, animazione: { Effetto: '' } }

describe('sectionWire', () => {
  it('parseSectionKindField accetta solo kind noti', () => {
    expect(parseSectionKindField('InlineText')).toBe('InlineText')
    expect(parseSectionKindField('Unknown')).toBeNull()
    expect(parseSectionKindField(null)).toBeNull()
  })

  it('parseSectionFromWire senza Kind fallisce', () => {
    const r = parseSectionFromWire({ Text: 'x' })
    expect(r.ok).toBe(false)
    if (r.ok) throw new Error('expected fail')
    expect(r.error).toMatch(/Kind mancante/i)
  })

  it('parseSectionFromWire con Kind InlineText preserva il tipo', () => {
    const r = parseSectionFromWire({ Kind: 'InlineText', Text: 'x' })
    expect(r.ok).toBe(true)
    if (!r.ok) throw new Error(r.error)
    expect(r.section.Kind).toBe('InlineText')
    expect(r.section).toMatchObject({ ...BASE, Text: 'x' })
  })

  it('parseSectionFromWire con Kind IIFAnnotationsGroup', () => {
    const payload = {
      Image: { URL: 'https://iiif.gpams.it/iiif/2/uuid.jpg/full/max/0/default.jpg' },
      Annotations: [{ Text: 'a', Rect: { x: 0, y: 0, width: 1, height: 1 } }],
    }
    const r = parseSectionFromWire({ Kind: 'IIFAnnotationsGroup', ...payload })
    expect(r.ok).toBe(true)
    if (!r.ok) throw new Error(r.error)
    expect(r.section.Kind).toBe('IIFAnnotationsGroup')
    expect(r.section).toMatchObject({
      ...BASE,
      Image: {
        BaseURI: 'https://iiif.gpams.it/iiif/2/uuid.jpg',
        Width: null,
        Height: null,
        bgColor: null,
      },
      Caption: null,
      Annotations: payload.Annotations,
    })
  })

  it('normalizeSection normalizza IIFAnnotationsGroup', () => {
    expect(
      normalizeSection({
        Kind: 'IIFAnnotationsGroup',
        ...BASE,
        Image: {
          BaseURI: 'https://iiif.gpams.it/iiif/2/uuid.tif',
          Width: 100,
          Height: 200,
        },
        Caption: null,
        Annotations: [],
      }),
    ).toEqual({
      Kind: 'IIFAnnotationsGroup',
      ...BASE,
      Image: {
        BaseURI: 'https://iiif.gpams.it/iiif/2/uuid.tif',
        Width: 100,
        Height: 200,
        bgColor: null,
      },
      Caption: null,
      Annotations: [],
    })
  })

  it('parseSectionFromWire rifiuta Kind non valido', () => {
    const r = parseSectionFromWire({ Kind: 'Foo', Text: 'x' })
    expect(r.ok).toBe(false)
    if (r.ok) throw new Error('expected fail')
    expect(r.error).toMatch(/Kind non valido/)
  })

  it('parseSectionFromWire rifiuta payload incompleto per Kind', () => {
    const r = parseSectionFromWire({
      Kind: 'SplitImage',
      Text: 'solo',
    })
    expect(r.ok).toBe(false)
    if (r.ok) throw new Error('expected fail')
    expect(r.error).toMatch(/Layout/)
  })

  it('parseSectionFromWire SplitImage senza MediaType default Image', () => {
    const payload = {
      Layout: 'Right' as const,
      Text: 't',
      LinkScheda: { Layout: 'TopLeft' as const, URL: 'u' },
      Image: { URL: 'https://img.test/1.jpg' },
    }
    const r = parseSectionFromWire({ Kind: 'SplitImage', ...payload })
    expect(r.ok).toBe(true)
    if (!r.ok) throw new Error(r.error)
    expect(r.section).toMatchObject({ ...payload, MediaType: 'Image', ...BASE })
  })

  it('normalizeSection SplitImage preserva MediaType Video', () => {
    expect(
      normalizeSection({
        Kind: 'SplitImage',
        ...BASE,
        Layout: 'Left',
        Text: 't',
        LinkScheda: { Layout: 'TopLeft', URL: '' },
        Image: { URL: 'https://video.test/v.mp4' },
        MediaType: 'Video',
      }),
    ).toEqual({
      Kind: 'SplitImage',
      ...BASE,
      Layout: 'Left',
      Text: 't',
      LinkScheda: { Layout: 'TopLeft', URL: '' },
      Image: { URL: 'https://video.test/v.mp4' },
      MediaType: 'Video',
    })
  })

  it('normalizeSection include Kind e campi base', () => {
    expect(
      normalizeSection({
        Kind: 'ScrollReveal',
        ...BASE,
        Paragraphs: [
          {
            Text: 't',
            Image: { URL: 'u' },
            LinkScheda: { Layout: 'TopLeft', URL: '' },
          },
        ],
      }),
    ).toEqual({
      Kind: 'ScrollReveal',
      ...BASE,
      Paragraphs: [
        {
          Text: 't',
          Image: { URL: 'u' },
          LinkScheda: { Layout: 'TopLeft', URL: '' },
        },
      ],
    })
  })

  it('parseSectionFromWire applica default published e animazione', () => {
    const r = parseSectionFromWire({ Kind: 'TextIntro', Text: 'hello' })
    expect(r.ok).toBe(true)
    if (!r.ok) throw new Error(r.error)
    expect(r.section.published).toBe(true)
    expect(r.section.animazione).toEqual({ Effetto: '' })
  })

  it('parseSectionFromWire conserva published e animazione espliciti', () => {
    const r = parseSectionFromWire({
      Kind: 'InlineText',
      Text: 'x',
      published: false,
      animazione: { Effetto: 'slide' },
    })
    expect(r.ok).toBe(true)
    if (!r.ok) throw new Error(r.error)
    expect(r.section.published).toBe(false)
    expect(r.section.animazione).toEqual({ Effetto: 'slide' })
  })

  it('createEmptySection è compatibile con normalizeSection', () => {
    const section = createEmptySection('TextIntro')
    expect(normalizeSection(section)).toEqual(section)
  })
})
