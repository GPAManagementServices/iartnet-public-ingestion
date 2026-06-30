import type {
  TStoryIIFAnnotationType,
  TStoryIIFAnnotationsGroupType,
  TStoryIIFImageType,
} from '../types/story'
import { createEmptyIIFImage } from '../types/story'
import { normalizeRichText } from './richText'
import { iiifPreviewRequestWidth } from './iifCanvasViewport'

export { createEmptyIIFImage }

/** Identificatore IIIF Image API 2 dopo `/iiif/2/` (es. `uuid.tif`). */
const IIIF_IDENTIFIER = /\/iiif\/2\/([^/?#]+)/i

/**
 * Estrae il BaseURI (@id) da un URL IIIF completo o restituisce l'input se già base.
 * Es.: `…/uuid.tif/full/max/0/default.jpg` → `…/uuid.tif`
 */
export function extractIiifBaseUri(input: string): string | null {
  const trimmed = input.trim()
  if (!trimmed) return null

  const idMatch = trimmed.match(IIIF_IDENTIFIER)
  if (!idMatch) return null

  const id = idMatch[1]!
  const prefix = trimmed.slice(0, idMatch.index! + '/iiif/2/'.length)
  return `${prefix}${id}`
}

export function iiifInfoJsonUrl(baseUri: string): string {
  return `${baseUri.replace(/\/$/, '')}/info.json`
}

/** Anteprima leggera per l'editor (non altera il sistema di coordinate delle annotazioni). */
export function iiifPreviewUrl(baseUri: string, maxWidth = 800): string {
  const base = baseUri.replace(/\/$/, '')
  // gpams/Cantaloupe: `full/800,/0/…` (sizeByW); `!800,` non supportato → 400
  return `${base}/full/${maxWidth},/0/default.jpg`
}

/** URL IIIF con risoluzione adeguata allo zoom del viewport. */
export function iiifPreviewUrlForZoom(
  baseUri: string,
  bounds: IIFImageBounds,
  fitWidth: number,
  zoom: number,
  maxRequestWidth = 2400,
  maxZoom?: number,
): string {
  const requestWidth = iiifPreviewRequestWidth(bounds, fitWidth, zoom, maxRequestWidth, 800, maxZoom)
  return iiifPreviewUrl(baseUri, requestWidth)
}

/** Base IIIF riconosciuto dall'input (URL completo o @id già normalizzato). */
export function resolveIiifBaseUri(input: string): string | null {
  const trimmed = input.trim()
  if (!trimmed) return null
  return extractIiifBaseUri(trimmed)
}

function parseOptionalDimension(raw: unknown): number | null {
  if (raw === null || raw === undefined || raw === '') return null
  const n = typeof raw === 'number' ? raw : Number(raw)
  if (!Number.isFinite(n) || n <= 0) return null
  return Math.round(n)
}

function normalizeIIFCaption(raw: unknown): string | null {
  if (raw === null || raw === undefined) return null
  const normalized = normalizeRichText(raw, { allowImages: true })
  return normalized === '' ? null : normalized
}

function normalizeIIFBgColor(raw: unknown): string | null {
  if (raw == null) return null
  if (typeof raw !== 'string') return null
  const trimmed = raw.trim()
  return trimmed === '' ? null : raw
}

function iifImageFromRecord(record: Record<string, unknown>, baseUri: string): TStoryIIFImageType {
  return {
    BaseURI: baseUri,
    Width: parseOptionalDimension(record.Width),
    Height: parseOptionalDimension(record.Height),
    bgColor: normalizeIIFBgColor(record.bgColor),
  }
}

export function normalizeIIFAnnotationsGroup(
  section: TStoryIIFAnnotationsGroupType,
): TStoryIIFAnnotationsGroupType {
  return {
    ...section,
    Image: normalizeIIFImage(section.Image),
    Caption: normalizeIIFCaption(section.Caption),
    Annotations: Array.isArray(section.Annotations) ? [...section.Annotations] : [],
  }
}

/** Normalizza Image da wire (BaseURI nuovo o URL legacy da TStoryImageType). */
export function normalizeIIFImage(raw: unknown): TStoryIIFImageType {
  if (!raw || typeof raw !== 'object' || Array.isArray(raw)) {
    return createEmptyIIFImage()
  }

  const record = raw as Record<string, unknown>

  if (typeof record.BaseURI === 'string') {
    return iifImageFromRecord(record, record.BaseURI.trim())
  }

  if (typeof record.URL === 'string') {
    const url = record.URL.trim()
    const base = extractIiifBaseUri(url) ?? url
    return iifImageFromRecord(record, base)
  }

  return createEmptyIIFImage()
}

export type IiifInfoResult =
  | { ok: true; width: number; height: number }
  | { ok: false; error: string }

/** Carica width/height canonici da info.json IIIF Image API 2. */
export async function fetchIiifImageInfo(baseUri: string): Promise<IiifInfoResult> {
  const base = baseUri.trim()
  if (!base) {
    return { ok: false, error: 'BaseURI vuoto' }
  }
  if (!extractIiifBaseUri(base)) {
    return { ok: false, error: 'BaseURI non riconosciuto come servizio IIIF' }
  }

  try {
    const res = await fetch(iiifInfoJsonUrl(base))
    if (!res.ok) {
      return { ok: false, error: `info.json: HTTP ${res.status}` }
    }
    const data = (await res.json()) as { width?: unknown; height?: unknown }
    const width = parseOptionalDimension(data.width)
    const height = parseOptionalDimension(data.height)
    if (width === null || height === null) {
      return { ok: false, error: 'info.json senza width/height validi' }
    }
    return { ok: true, width, height }
  } catch {
    return { ok: false, error: 'Impossibile caricare info.json' }
  }
}

export type IIFImageBounds = { width: number; height: number }

export function iifImageBounds(image: TStoryIIFImageType): IIFImageBounds | null {
  const width = image.Width
  const height = image.Height
  if (width == null || height == null || width <= 0 || height <= 0) return null
  return { width, height }
}

/** Regione IIIF: immagine intera o ritaglio pixel (coordinate canvas). */
export type IiifImageRegion =
  | 'full'
  | TStoryIIFAnnotationType['Rect']

export type IiifImageSize = {
  /** Larghezza finale richiesta (`w,` o `w,h`). */
  width: number | null
  /** Altezza finale richiesta (`,h` o `w,h`). */
  height: number | null
  /** Segmento size non numerico (`full` / `max`) quando width e height sono assenti. */
  keyword?: 'full' | 'max'
}

export type IiifImageUrlParts = {
  baseUri: string
  region: IiifImageRegion
  size: IiifImageSize
  rotation: number
  quality: string
  format: string
}

const IIIF_SUFFIX =
  /^([^/]+)\/([^/]+)\/([^/]+)\/([^/]+)$/

const IIIF_REGION_PIXEL = /^(\d+),(\d+),(\d+),(\d+)$/
const IIIF_SIZE_EXACT = /^(\d+),(\d+)$/
const IIIF_SIZE_BY_WIDTH = /^(\d+),$/
const IIIF_SIZE_BY_HEIGHT = /^,(\d+)$/

function iiifUrlSuffix(baseUri: string, url: string): string | null {
  const trimmed = url.trim()
  const base = baseUri.replace(/\/$/, '')
  if (!trimmed.startsWith(base)) return null
  const suffix = trimmed.slice(base.length)
  if (!suffix.startsWith('/')) return null
  return suffix.slice(1)
}

function parseIiifRegionSegment(segment: string): IiifImageRegion | null {
  if (segment === 'full' || segment === 'square') return 'full'
  const match = segment.match(IIIF_REGION_PIXEL)
  if (!match) return null
  return {
    x: Number(match[1]),
    y: Number(match[2]),
    width: Number(match[3]),
    height: Number(match[4]),
  }
}

function parseIiifSizeSegment(segment: string): IiifImageSize | null {
  if (segment === 'full') return { width: null, height: null, keyword: 'full' }
  if (segment === 'max') return { width: null, height: null, keyword: 'max' }

  const exact = segment.match(IIIF_SIZE_EXACT)
  if (exact) {
    return { width: Number(exact[1]), height: Number(exact[2]) }
  }
  const byWidth = segment.match(IIIF_SIZE_BY_WIDTH)
  if (byWidth) return { width: Number(byWidth[1]), height: null }
  const byHeight = segment.match(IIIF_SIZE_BY_HEIGHT)
  if (byHeight) return { width: null, height: Number(byHeight[1]) }
  return null
}

function formatIiifRegionSegment(region: IiifImageRegion): string {
  if (region === 'full') return 'full'
  return `${region.x},${region.y},${region.width},${region.height}`
}

function formatIiifSizeSegment(size: IiifImageSize): string {
  if (size.keyword === 'full' || size.keyword === 'max') return size.keyword
  const { width, height } = size
  if (width != null && height != null) return `${width},${height}`
  if (width != null) return `${width},`
  if (height != null) return `,${height}`
  return 'full'
}

function parseIiifQualityFormat(segment: string): { quality: string; format: string } | null {
  const dot = segment.lastIndexOf('.')
  if (dot <= 0 || dot === segment.length - 1) return null
  return {
    quality: segment.slice(0, dot),
    format: segment.slice(dot + 1),
  }
}

/** Da URL IIIF completo (TStoryImageType.URL) → parti editabili. */
export function parseIiifImageUrl(input: string): IiifImageUrlParts | null {
  const trimmed = input.trim()
  if (!trimmed) return null

  const baseUri = extractIiifBaseUri(trimmed)
  if (!baseUri) return null

  const suffix = iiifUrlSuffix(baseUri, trimmed)
  if (!suffix) return null

  const match = suffix.match(IIIF_SUFFIX)
  if (!match) return null

  const region = parseIiifRegionSegment(match[1]!)
  const size = parseIiifSizeSegment(match[2]!)
  const qualityFormat = parseIiifQualityFormat(match[4]!)
  if (!region || !size || !qualityFormat) return null

  const rotation = Number(match[3])
  if (!Number.isFinite(rotation)) return null

  return {
    baseUri,
    region,
    size,
    rotation: Math.round(rotation),
    quality: qualityFormat.quality,
    format: qualityFormat.format,
  }
}

/** Composizione URL IIIF da parti (salvato in TStoryImageType.URL). */
export function buildIiifImageUrl(parts: IiifImageUrlParts): string {
  const base = parts.baseUri.replace(/\/$/, '')
  const region = formatIiifRegionSegment(parts.region)
  const size = formatIiifSizeSegment(parts.size)
  const rotation = String(parts.rotation)
  const qualityFormat = `${parts.quality}.${parts.format}`
  return `${base}/${region}/${size}/${rotation}/${qualityFormat}`
}

/** Anteprima con regione e size richiesti (header / crop). */
export function iiifDeliveryUrl(parts: IiifImageUrlParts, maxDimension = 800): string {
  const previewSize: IiifImageSize = { ...parts.size }

  if (previewSize.keyword) {
    previewSize.keyword = undefined
    previewSize.width = maxDimension
    previewSize.height = null
  } else if (previewSize.width != null && previewSize.height == null) {
    previewSize.width = Math.min(previewSize.width, maxDimension)
  } else if (previewSize.width == null && previewSize.height != null) {
    previewSize.height = Math.min(previewSize.height, maxDimension)
  } else if (previewSize.width == null && previewSize.height == null) {
    previewSize.width = maxDimension
    previewSize.height = null
  } else {
    const scale = Math.min(1, maxDimension / Math.max(previewSize.width!, previewSize.height!))
    previewSize.width = Math.max(1, Math.round(previewSize.width! * scale))
    previewSize.height = Math.max(1, Math.round(previewSize.height! * scale))
  }

  return buildIiifImageUrl({ ...parts, size: previewSize })
}

export function isIiifImageRegionFull(region: IiifImageRegion): region is 'full' {
  return region === 'full'
}

export function iiifRegionToRect(
  region: IiifImageRegion,
  bounds: IIFImageBounds | null,
): TStoryIIFAnnotationType['Rect'] {
  if (region === 'full' && bounds) {
    return { x: 0, y: 0, width: bounds.width, height: bounds.height }
  }
  if (region === 'full') {
    return { x: 0, y: 0, width: 0, height: 0 }
  }
  return { ...region }
}

export function iiifRectToRegion(
  rect: TStoryIIFAnnotationType['Rect'],
  bounds: IIFImageBounds | null,
): IiifImageRegion {
  if (!bounds) return rect
  if (rect.x === 0 && rect.y === 0 && rect.width === bounds.width && rect.height === bounds.height) {
    return 'full'
  }
  return rect
}

export const IIIF_IMAGE_URL_DEFAULTS = {
  rotation: 0,
  quality: 'default',
  format: 'jpg',
} as const
