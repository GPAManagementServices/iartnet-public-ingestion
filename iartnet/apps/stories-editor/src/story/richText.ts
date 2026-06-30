import { sanitizeRichText, type RichTextSanitizeOptions } from './richTextSanitize'

export type RichTextNormalizeOptions = RichTextSanitizeOptions

const HTML_TAG_RE = /<\/?([a-z][a-z0-9]*)\b[^>]*>/i
const BR_TAG_RE = /<br\s*\/?>/gi

/** Unisce elementi legacy `string[]` con `<br />` (stringhe vuote → spaziatura). */
export function joinTextArray(parts: string[]): string {
  return parts.map((p) => String(p)).join('<br />')
}

function containsHtmlMarkup(text: string): boolean {
  return HTML_TAG_RE.test(text)
}

function normalizeBrTags(html: string): string {
  return html.replace(BR_TAG_RE, '<br />')
}

function plainTextToHtml(text: string): string {
  return text.split('\n').join('<br />')
}

function normalizeStringValue(text: string, options?: RichTextNormalizeOptions): string {
  const normalizedBr = normalizeBrTags(text)
  const withBreaks = containsHtmlMarkup(normalizedBr)
    ? normalizedBr.replace(/\n/g, '<br />')
    : plainTextToHtml(text)
  return sanitizeRichText(withBreaks, options)
}

/**
 * Normalizza testo ricco in ingresso (wire legacy o editor) a `string` HTML.
 * - `string[]` → join con `<br />`
 * - plain `string` con `\n` → `<br />`
 * - HTML esistente → preservato (br normalizzati) e sanitizzato
 */
export function normalizeRichText(raw: unknown, options?: RichTextNormalizeOptions): string {
  if (raw === null || raw === undefined) return ''
  if (Array.isArray(raw)) {
    return normalizeStringValue(joinTextArray(raw.map(String)), options)
  }
  if (typeof raw !== 'string') return ''
  if (raw === '') return ''
  return normalizeStringValue(raw, options)
}

/** HTML → testo plain (anteprima, tooltip). */
export function richTextToPlainText(html: string): string {
  return html
    .replace(/<br\s*\/?>/gi, ' ')
    .replace(/<[^>]+>/g, '')
    .replace(/&nbsp;/gi, ' ')
    .replace(/&amp;/g, '&')
    .replace(/&lt;/g, '<')
    .replace(/&gt;/g, '>')
    .replace(/&quot;/g, '"')
    .replace(/&#39;/g, "'")
    .replace(/\s+/g, ' ')
    .trim()
}

/** Anteprima plain text (accordion, label) senza tag HTML. */
export function richTextPlainPreview(html: string, maxLength = 48): string {
  const plain = richTextToPlainText(html)
  if (!plain) return '(senza testo)'
  return plain.length > maxLength ? `${plain.slice(0, maxLength)}…` : plain
}

const EMPTY_EDITOR_HTML = new Set(['', '<p></p>', '<p><br></p>', '<p><br />', '<p><br /></p>'])

/** HTML TipTap → valore persistito (sanitizzato; editor vuoto → ''). */
export function editorHtmlToStoredValue(html: string, options?: RichTextNormalizeOptions): string {
  const sanitized = sanitizeRichText(html, options)
  if (EMPTY_EDITOR_HTML.has(sanitized)) return ''
  return sanitized
}

/** Valore persistito → HTML per TipTap. */
export function storedValueToEditorHtml(html: string): string {
  if (!html.trim()) return ''
  return html
}
