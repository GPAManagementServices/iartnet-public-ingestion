import { describe, expect, it } from 'vitest'
import {
  DEFAULT_SECTION_PUBLISHED,
  DEFAULT_STORY_ANIMATION,
  createSectionBase,
  isSectionBgImageEmpty,
  normalizeSectionBase,
} from './sectionBase'

describe('sectionBase', () => {
  it('createSectionBase imposta Kind e default', () => {
    expect(createSectionBase('TextIntro')).toEqual({
      Kind: 'TextIntro',
      published: DEFAULT_SECTION_PUBLISHED,
      animazione: { ...DEFAULT_STORY_ANIMATION },
    })
  })

  it('normalizeSectionBase applica default su oggetto vuoto', () => {
    expect(normalizeSectionBase({}, 'SplitImage')).toEqual({
      Kind: 'SplitImage',
      published: true,
      animazione: { Effetto: '' },
    })
  })

  it('normalizeSectionBase conserva published esplicito', () => {
    expect(normalizeSectionBase({ published: false }, 'InlineText')).toMatchObject({
      published: false,
    })
  })

  it('normalizeSectionBase conserva animazione.Effetto', () => {
    expect(
      normalizeSectionBase({ animazione: { Effetto: 'fade-in' } }, 'ScrollReveal'),
    ).toMatchObject({
      animazione: { Effetto: 'fade-in' },
    })
  })

  it('normalizeSectionBase con animazione senza Effetto usa stringa vuota', () => {
    expect(normalizeSectionBase({ animazione: {} }, 'TextIntro')).toMatchObject({
      animazione: { Effetto: '' },
    })
  })

  it('normalizeSectionBase normalizza bgColor e bgImage', () => {
    expect(
      normalizeSectionBase(
        {
          bgColor: '  ',
          bgImage: { URL: 'https://ex.test/bg.jpg', Caption: ' sfondo ', bgColor: '#abc' },
        },
        'TextIntro',
      ),
    ).toEqual({
      Kind: 'TextIntro',
      published: true,
      animazione: { Effetto: '' },
      bgImage: {
        URL: 'https://ex.test/bg.jpg',
        Caption: ' sfondo ',
        bgColor: '#abc',
      },
    })

    expect(
      normalizeSectionBase({ bgColor: 'rgba(0,0,0,1)' }, 'InlineText'),
    ).toMatchObject({
      bgColor: 'rgba(0,0,0,1)',
    })

    expect(
      normalizeSectionBase({ foreColor: 'rgba(255,255,255,1)' }, 'TextIntro'),
    ).toMatchObject({
      foreColor: 'rgba(255,255,255,1)',
    })

    expect(normalizeSectionBase({ foreColor: '  ' }, 'TextIntro')).toEqual({
      Kind: 'TextIntro',
      published: true,
      animazione: { Effetto: '' },
    })

    expect(normalizeSectionBase({ bgImage: { URL: '  ' } }, 'TextIntro')).toEqual({
      Kind: 'TextIntro',
      published: true,
      animazione: { Effetto: '' },
    })

    expect(
      normalizeSectionBase({ bgImage: { URL: '', bgColor: '#abc' } }, 'TextIntro'),
    ).toMatchObject({
      bgImage: { URL: '', bgColor: '#abc' },
    })

    expect(
      normalizeSectionBase({ bgImage: { URL: ' ', Caption: ' didascalia ' } }, 'TextIntro'),
    ).toMatchObject({
      bgImage: { URL: ' ', Caption: ' didascalia ' },
    })
  })

  it('isSectionBgImageEmpty considera URL, Caption e bgColor', () => {
    expect(isSectionBgImageEmpty(null)).toBe(true)
    expect(isSectionBgImageEmpty({ URL: '' })).toBe(true)
    expect(isSectionBgImageEmpty({ URL: '', bgColor: '#abc' })).toBe(false)
    expect(isSectionBgImageEmpty({ URL: '', Caption: 'cap' })).toBe(false)
    expect(isSectionBgImageEmpty({ URL: 'https://ex.test/a.jpg' })).toBe(false)
  })
})
