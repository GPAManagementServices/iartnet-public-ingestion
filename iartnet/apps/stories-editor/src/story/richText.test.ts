import { describe, expect, it } from 'vitest'
import {
  editorHtmlToStoredValue,
  joinTextArray,
  normalizeRichText,
  richTextPlainPreview,
  richTextToPlainText,
} from './richText'

describe('joinTextArray', () => {
  it('unisce con <br />', () => {
    expect(joinTextArray(['<b>Titolo</b>', 'Paragrafo'])).toBe('<b>Titolo</b><br />Paragrafo')
  })

  it('stringhe vuote producono spaziatura', () => {
    expect(joinTextArray(['<b>Titolo</b>', '', 'Paragrafo'])).toBe(
      '<b>Titolo</b><br /><br />Paragrafo',
    )
  })

  it('singolo elemento senza br finale', () => {
    expect(joinTextArray(['solo'])).toBe('solo')
  })
})

describe('normalizeRichText', () => {
  it('string[] legacy → string HTML', () => {
    expect(normalizeRichText(['riga 1', 'riga 2'])).toBe('riga 1<br />riga 2')
  })

  it('plain string con \\n → <br />', () => {
    expect(normalizeRichText('line1\n\nline2')).toBe('line1<br /><br />line2')
  })

  it('HTML esistente preservato e br normalizzati', () => {
    expect(normalizeRichText('<b>Titolo</b><br>testo')).toBe('<b>Titolo</b><br />testo')
  })

  it('null/undefined → stringa vuota', () => {
    expect(normalizeRichText(null)).toBe('')
    expect(normalizeRichText(undefined)).toBe('')
  })

  it('stringa vuota → stringa vuota', () => {
    expect(normalizeRichText('')).toBe('')
  })
})

describe('richTextPlainPreview', () => {
  it('rimuove tag HTML', () => {
    expect(richTextPlainPreview('<b>Artists</b> on Stage')).toBe('Artists on Stage')
  })

  it('decodifica entità HTML comuni', () => {
    expect(richTextToPlainText('<b>A &amp; B</b>&nbsp;test')).toBe('A & B test')
  })

  it('testo vuoto', () => {
    expect(richTextPlainPreview('')).toBe('(senza testo)')
  })

  it('tronca testo lungo', () => {
    const long = 'a'.repeat(60)
    expect(richTextPlainPreview(long)).toBe(`${'a'.repeat(48)}…`)
  })
})

describe('editorHtmlToStoredValue', () => {
  it('stringa vuota editor → stringa vuota', () => {
    expect(editorHtmlToStoredValue('<p></p>')).toBe('')
    expect(editorHtmlToStoredValue('<p><br /></p>')).toBe('')
  })

  it('editorHtmlToStoredValue sanitizza output', () => {
    expect(editorHtmlToStoredValue('<p><b>ok</b><script>x</script></p>')).toBe('<p><b>ok</b></p>')
  })
})
