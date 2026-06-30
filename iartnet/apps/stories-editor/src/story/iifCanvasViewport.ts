export type IIFCanvasBounds = { width: number; height: number }

export const IIF_VIEWPORT_FIT_WIDTH_DEFAULT = 480

export const IIF_VIEWPORT_ZOOM_MIN = 0.5
export const IIF_VIEWPORT_ZOOM_MAX = 8
export const IIF_VIEWPORT_ZOOM_STEP = 1.25

export type CanvasDisplaySize = {
  width: number
  height: number
}

export function computeCanvasDisplaySize(
  bounds: IIFCanvasBounds,
  fitWidth: number,
  zoom: number,
  maxZoom = IIF_VIEWPORT_ZOOM_MAX,
): CanvasDisplaySize {
  const safeFit = Math.max(1, fitWidth)
  const safeZoom = Math.max(IIF_VIEWPORT_ZOOM_MIN, Math.min(maxZoom, zoom))
  const width = Math.max(1, Math.round(safeFit * safeZoom))
  const height = Math.max(1, Math.round((width * bounds.height) / bounds.width))
  return { width, height }
}

export function stepIifViewportZoom(
  current: number,
  direction: 1 | -1,
  maxZoom = IIF_VIEWPORT_ZOOM_MAX,
): number {
  const factor = direction > 0 ? IIF_VIEWPORT_ZOOM_STEP : 1 / IIF_VIEWPORT_ZOOM_STEP
  const next = current * factor
  const clamped = Math.min(maxZoom, Math.max(IIF_VIEWPORT_ZOOM_MIN, next))
  return Math.round(clamped * 100) / 100
}

/** Larghezza richiesta al servizio IIIF in base allo zoom visualizzato. */
export function iiifPreviewRequestWidth(
  bounds: IIFCanvasBounds,
  fitWidth: number,
  zoom: number,
  maxRequestWidth = 2400,
  baseRequestWidth = 800,
  maxZoom = IIF_VIEWPORT_ZOOM_MAX,
): number {
  const display = computeCanvasDisplaySize(bounds, fitWidth, zoom, maxZoom)
  return Math.min(bounds.width, maxRequestWidth, Math.max(baseRequestWidth, display.width))
}

export function measureViewportFitWidth(
  containerWidth: number,
  padding = 16,
  maxFit = IIF_VIEWPORT_FIT_WIDTH_DEFAULT,
): number {
  const inner = Math.max(120, containerWidth - padding)
  return Math.min(maxFit, inner)
}

export const IIF_VIEWPORT_FIT_WIDTH_FULLSCREEN = 960
export const IIF_VIEWPORT_ZOOM_MAX_FULLSCREEN = 12

export type IIFCanvasRect = { x: number; y: number; width: number; height: number }

export type ViewportScrollTarget = { left: number; top: number }

export const IIF_ANNOTATION_LABEL_SCREEN_PX = 44

export type IIFAnnotationLabelLayout = {
  fontSize: number
  textX: number
  textY: number
}

/** Numero annotazione centrato; dimensioni testo costanti in px schermo. */
export function iifAnnotationLabelLayout(
  rect: IIFCanvasRect,
  bounds: IIFCanvasBounds,
  displaySize: CanvasDisplaySize,
  screenFontPx = IIF_ANNOTATION_LABEL_SCREEN_PX,
): IIFAnnotationLabelLayout {
  const scale = displaySize.width / bounds.width
  const screenPad = 4
  const screenTextW = screenFontPx * 0.75
  const screenTextH = screenFontPx * 1.1

  let fontSize = screenFontPx / scale

  const pad = screenPad / scale
  const maxW = Math.max(rect.width - pad * 2, 1)
  const maxH = Math.max(rect.height - pad * 2, 1)
  const shrink = Math.min(1, maxW / (screenTextW / scale), maxH / (screenTextH / scale))
  fontSize *= shrink

  return {
    fontSize,
    textX: rect.x + rect.width / 2,
    textY: rect.y + rect.height / 2,
  }
}

/** Coordinate rettangolo canvas → pixel sul div anteprima scalato. */
export function iifRectToDisplayBounds(
  rect: IIFCanvasRect,
  bounds: IIFCanvasBounds,
  displaySize: CanvasDisplaySize,
) {
  const sx = displaySize.width / bounds.width
  const sy = displaySize.height / bounds.height
  return {
    left: rect.x * sx,
    top: rect.y * sy,
    width: rect.width * sx,
    height: rect.height * sy,
  }
}

/** Centra il rettangolo nel viewport scrollabile se non è già visibile. */
export function computeViewportScrollToRect(
  scrollEl: HTMLElement,
  canvasEl: HTMLElement,
  rect: IIFCanvasRect,
  bounds: IIFCanvasBounds,
  displaySize: CanvasDisplaySize,
  padding = 16,
): ViewportScrollTarget | null {
  const disp = iifRectToDisplayBounds(rect, bounds, displaySize)
  const canvasBox = canvasEl.getBoundingClientRect()
  const scrollBox = scrollEl.getBoundingClientRect()

  const renderScaleX = canvasBox.width / displaySize.width
  const renderScaleY = canvasBox.height / displaySize.height

  const rectClientLeft = canvasBox.left + disp.left * renderScaleX
  const rectClientTop = canvasBox.top + disp.top * renderScaleY
  const rectClientRight = rectClientLeft + disp.width * renderScaleX
  const rectClientBottom = rectClientTop + disp.height * renderScaleY

  const viewLeft = scrollBox.left + padding
  const viewTop = scrollBox.top + padding
  const viewRight = scrollBox.right - padding
  const viewBottom = scrollBox.bottom - padding

  if (
    rectClientLeft >= viewLeft &&
    rectClientTop >= viewTop &&
    rectClientRight <= viewRight &&
    rectClientBottom <= viewBottom
  ) {
    return null
  }

  const rectContentLeft = scrollEl.scrollLeft + (rectClientLeft - scrollBox.left)
  const rectContentTop = scrollEl.scrollTop + (rectClientTop - scrollBox.top)
  const rectContentWidth = disp.width * renderScaleX
  const rectContentHeight = disp.height * renderScaleY

  const maxScrollLeft = Math.max(0, scrollEl.scrollWidth - scrollEl.clientWidth)
  const maxScrollTop = Math.max(0, scrollEl.scrollHeight - scrollEl.clientHeight)

  return {
    left: Math.min(maxScrollLeft, Math.max(0, rectContentLeft + rectContentWidth / 2 - scrollEl.clientWidth / 2)),
    top: Math.min(maxScrollTop, Math.max(0, rectContentTop + rectContentHeight / 2 - scrollEl.clientHeight / 2)),
  }
}
