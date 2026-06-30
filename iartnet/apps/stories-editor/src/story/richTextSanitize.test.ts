import { describe, expect, it } from 'vitest'
import { parseRichTextImageUrl, sanitizeRichText } from './richTextSanitize'

describe('sanitizeRichText', () => {
  it('conserva tag ammessi', () => {
    expect(sanitizeRichText('<b>bold</b> <i>italic</i>')).toBe('<b>bold</b> <i>italic</i>')
  })

  it('conserva link con href', () => {
    expect(sanitizeRichText('<a href="https://ex.test">link</a>')).toBe(
      '<a href="https://ex.test">link</a>',
    )
  })

  it('rimuove script e attributi pericolosi', () => {
    expect(sanitizeRichText('<script>alert(1)</script><b onclick="x()">ok</b>')).toBe('<b>ok</b>')
  })

  it('rimuove tag non in whitelist mantenendo il testo', () => {
    expect(sanitizeRichText('<h1>title</h1><p>body</p>')).toBe('title<p>body</p>')
  })

  it('rimuove img se allowImages è false', () => {
    expect(sanitizeRichText('<p><img src="https://ex.test/a.jpg" alt=""></p>')).toBe('<p></p>')
  })

  it('conserva img https con allowImages', () => {
    expect(
      sanitizeRichText('<img src="https://ex.test/a.jpg" alt="desc">', { allowImages: true }),
    ).toBe('<img src="https://ex.test/a.jpg" alt="desc">')
  })

  it('rimuove src non http(s) con allowImages', () => {
    expect(
      sanitizeRichText('<img src="javascript:alert(1)" alt="">', { allowImages: true }),
    ).toBe('<img alt="">')
  })
})

describe('parseRichTextImageUrl', () => {
  it('accetta solo http/https', () => {
    expect(parseRichTextImageUrl('https://ex.test/x.png')).toBe('https://ex.test/x.png')
    expect(parseRichTextImageUrl('javascript:x')).toBeNull()
  })
})
