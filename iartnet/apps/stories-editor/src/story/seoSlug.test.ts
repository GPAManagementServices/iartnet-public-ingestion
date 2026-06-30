import { describe, expect, it } from 'vitest'
import {
  isValidSeoSlug,
  normalizeSeoSlugInput,
  seoSlugMatchesTitle,
  SEO_SLUG_MAX_LENGTH,
  titleToSeoSlug,
} from './seoSlug'

describe('titleToSeoSlug', () => {
  it('converte un titolo inglese in kebab-case', () => {
    expect(
      titleToSeoSlug('Mozart and the Masonic interpretation of The Magic Flute'),
    ).toBe('mozart-and-the-masonic-interpretation-of-the-magic-flute')
  })

  it('rimuove accenti e punteggiatura', () => {
    expect(titleToSeoSlug('Caffè & Perù')).toBe('caffe-peru')
  })

  it('gestisce apostrofi', () => {
    expect(titleToSeoSlug("Mozart's Magic")).toBe('mozarts-magic')
    expect(titleToSeoSlug("L'arte del Rinascimento")).toBe('l-arte-del-rinascimento')
  })

  it('collassa separatori ripetuti', () => {
    expect(titleToSeoSlug('---Hello---World---')).toBe('hello-world')
  })

  it('ritorna stringa vuota per titolo vuoto', () => {
    expect(titleToSeoSlug('   ')).toBe('')
    expect(titleToSeoSlug('!!!')).toBe('')
  })

  it('tronca su confine parola entro maxLength', () => {
    const longTitle =
      'One Two Three Four Five Six Seven Eight Nine Ten Eleven Twelve Thirteen Fourteen Fifteen'
    const slug = titleToSeoSlug(longTitle, SEO_SLUG_MAX_LENGTH)
    expect(slug.length).toBeLessThanOrEqual(SEO_SLUG_MAX_LENGTH)
    expect(slug.endsWith('-')).toBe(false)
    expect(slug).toBe(
      'one-two-three-four-five-six-seven-eight-nine-ten-eleven-twelve-thirteen-fourteen',
    )
  })
})

describe('normalizeSeoSlugInput', () => {
  it('normalizza slug già valido', () => {
    expect(normalizeSeoSlugInput('already-valid-slug')).toBe('already-valid-slug')
  })

  it('estrae slug da path relativo', () => {
    expect(normalizeSeoSlugInput('/stories/mozart-and-the-magic-flute')).toBe(
      'mozart-and-the-magic-flute',
    )
  })

  it('estrae slug da URL assoluto', () => {
    expect(
      normalizeSeoSlugInput('https://example.org/stories/mozart-and-the-magic-flute'),
    ).toBe('mozart-and-the-magic-flute')
  })

  it('ritorna stringa vuota per input vuoto', () => {
    expect(normalizeSeoSlugInput('   ')).toBe('')
  })
})

describe('isValidSeoSlug', () => {
  it('accetta slug validi', () => {
    expect(isValidSeoSlug('mozart-and-the-magic-flute')).toBe(true)
    expect(isValidSeoSlug('abc123')).toBe(true)
  })

  it('rifiuta slug non validi', () => {
    expect(isValidSeoSlug('-leading')).toBe(false)
    expect(isValidSeoSlug('trailing-')).toBe(false)
    expect(isValidSeoSlug('UPPER-case')).toBe(false)
    expect(isValidSeoSlug('spaced slug')).toBe(false)
  })
})

describe('seoSlugMatchesTitle', () => {
  it('confronta slug con derivazione dal titolo', () => {
    const title = 'Mozart and the Magic Flute'
    const slug = titleToSeoSlug(title)
    expect(seoSlugMatchesTitle(slug, title)).toBe(true)
    expect(seoSlugMatchesTitle('custom-slug', title)).toBe(false)
  })
})
