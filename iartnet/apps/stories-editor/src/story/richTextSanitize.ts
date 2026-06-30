import DOMPurify from 'isomorphic-dompurify'

/** Tag ammessi in testo ricco (toolbar minima + paragrafi). */
export const RICH_TEXT_ALLOWED_TAGS = [
  'b',
  'strong',
  'i',
  'em',
  'a',
  'p',
  'br',
] as const

export const RICH_TEXT_IMAGE_TAG = 'img' as const

export type RichTextSanitizeOptions = {
  /** Consente `<img src="https://…">` (solo Caption / annotazioni IIIF). */
  allowImages?: boolean
}

const LINK_ATTRS = ['href', 'target', 'rel'] as const
const IMAGE_ATTRS = ['src', 'alt'] as const

const SAFE_IMAGE_SRC = /^https?:\/\//i

function uponSanitizeImgSrc(
  node: Element,
  data: { attrName: string; attrValue: string; keepAttr: boolean },
) {
  if (data.attrName !== 'src' || node.tagName !== 'IMG') return
  if (!SAFE_IMAGE_SRC.test(data.attrValue)) {
    data.keepAttr = false
  }
}

function buildSanitizeConfig(options?: RichTextSanitizeOptions) {
  const allowImages = options?.allowImages ?? false
  return {
    ALLOWED_TAGS: allowImages
      ? [...RICH_TEXT_ALLOWED_TAGS, RICH_TEXT_IMAGE_TAG]
      : [...RICH_TEXT_ALLOWED_TAGS],
    ALLOWED_ATTR: allowImages ? [...LINK_ATTRS, ...IMAGE_ATTRS] : [...LINK_ATTRS],
  }
}

function normalizeBrOutput(html: string): string {
  return html.replace(/<br\s*\/?>/gi, '<br />')
}

/** Rimuove markup non ammesso; mantiene HTML sicuro per il viewer Vue. */
export function sanitizeRichText(html: string, options?: RichTextSanitizeOptions): string {
  if (!html) return ''
  const allowImages = options?.allowImages ?? false
  if (allowImages) {
    DOMPurify.addHook('uponSanitizeAttribute', uponSanitizeImgSrc)
  }
  try {
    return normalizeBrOutput(DOMPurify.sanitize(html, buildSanitizeConfig(options)).trim())
  } finally {
    if (allowImages) {
      DOMPurify.removeHook('uponSanitizeAttribute', uponSanitizeImgSrc)
    }
  }
}

/** URL immagine per inserimento da prompt (solo http/https). */
export function parseRichTextImageUrl(raw: string): string | null {
  const trimmed = raw.trim()
  if (!trimmed || !SAFE_IMAGE_SRC.test(trimmed)) return null
  return trimmed
}
