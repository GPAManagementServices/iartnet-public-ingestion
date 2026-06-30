import type { TStoryIIFAnnotationType } from '../types/story'
import type { IIFCanvasBounds } from './iifCanvasViewport'
import { clampIIFRect, isDraftIIFRect } from './iifRect'

export const IIF_RECT_SNAP_GRID = 10

export type CanvasPoint = { x: number; y: number }

export type IIFResizeHandle = 'nw' | 'n' | 'ne' | 'e' | 'se' | 's' | 'sw' | 'w'

export function snapIIFCoord(value: number, enabled: boolean, grid = IIF_RECT_SNAP_GRID): number {
  if (!enabled) return Math.round(value)
  return Math.round(value / grid) * grid
}

export function canvasPointFromClient(
  clientX: number,
  clientY: number,
  canvasEl: HTMLElement,
  bounds: IIFCanvasBounds,
  snap: boolean,
): CanvasPoint {
  const box = canvasEl.getBoundingClientRect()
  if (box.width <= 0 || box.height <= 0) {
    return { x: 0, y: 0 }
  }
  const rawX = (clientX - box.left) * (bounds.width / box.width)
  const rawY = (clientY - box.top) * (bounds.height / box.height)
  return {
    x: snapIIFCoord(rawX, snap, IIF_RECT_SNAP_GRID),
    y: snapIIFCoord(rawY, snap, IIF_RECT_SNAP_GRID),
  }
}

export function rectFromDrawPoints(
  start: CanvasPoint,
  end: CanvasPoint,
): TStoryIIFAnnotationType['Rect'] {
  const x = Math.min(start.x, end.x)
  const y = Math.min(start.y, end.y)
  const width = Math.abs(end.x - start.x)
  const height = Math.abs(end.y - start.y)
  return { x, y, width, height }
}

export function moveIIFRect(
  startRect: TStoryIIFAnnotationType['Rect'],
  start: CanvasPoint,
  current: CanvasPoint,
  bounds: IIFCanvasBounds,
  snap: boolean,
): TStoryIIFAnnotationType['Rect'] {
  let x = startRect.x + (current.x - start.x)
  let y = startRect.y + (current.y - start.y)
  if (snap) {
    x = snapIIFCoord(x, true)
    y = snapIIFCoord(y, true)
  } else {
    x = Math.round(x)
    y = Math.round(y)
  }
  return clampIIFRect({ ...startRect, x, y }, bounds)
}

export function resizeIIFRect(
  startRect: TStoryIIFAnnotationType['Rect'],
  handle: IIFResizeHandle,
  current: CanvasPoint,
  bounds: IIFCanvasBounds,
  snap: boolean,
): TStoryIIFAnnotationType['Rect'] {
  let { x, y, width, height } = startRect
  const px = snap ? snapIIFCoord(current.x, true) : Math.round(current.x)
  const py = snap ? snapIIFCoord(current.y, true) : Math.round(current.y)

  const right = x + width
  const bottom = y + height

  if (handle.includes('w')) {
    x = Math.min(px, right - 1)
    width = right - x
  }
  if (handle.includes('e')) {
    width = Math.max(1, px - x)
  }
  if (handle.includes('n')) {
    y = Math.min(py, bottom - 1)
    height = bottom - y
  }
  if (handle.includes('s')) {
    height = Math.max(1, py - y)
  }

  return clampIIFRect({ x, y, width, height }, bounds)
}

export function handlePositions(rect: TStoryIIFAnnotationType['Rect']): Record<IIFResizeHandle, CanvasPoint> {
  const { x, y, width, height } = rect
  const cx = x + width / 2
  const cy = y + height / 2
  const r = x + width
  const b = y + height
  return {
    nw: { x, y },
    n: { x: cx, y },
    ne: { x: r, y },
    e: { x: r, y: cy },
    se: { x: r, y: b },
    s: { x: cx, y: b },
    sw: { x, y: b },
    w: { x, y: cy },
  }
}

export function hitTestHandle(
  point: CanvasPoint,
  rect: TStoryIIFAnnotationType['Rect'],
  bounds: IIFCanvasBounds,
): IIFResizeHandle | null {
  const tolerance = Math.max(12, bounds.width / 120)
  for (const [handle, pos] of Object.entries(handlePositions(rect)) as [IIFResizeHandle, CanvasPoint][]) {
    if (Math.abs(point.x - pos.x) <= tolerance && Math.abs(point.y - pos.y) <= tolerance) {
      return handle
    }
  }
  return null
}

export function pointInRect(point: CanvasPoint, rect: TStoryIIFAnnotationType['Rect']): boolean {
  return (
    point.x >= rect.x &&
    point.x <= rect.x + rect.width &&
    point.y >= rect.y &&
    point.y <= rect.y + rect.height
  )
}

export function cursorForIIFResizeHandle(handle: IIFResizeHandle): string {
  switch (handle) {
    case 'nw':
    case 'se':
      return 'nwse-resize'
    case 'ne':
    case 'sw':
      return 'nesw-resize'
    case 'n':
    case 's':
      return 'ns-resize'
    case 'e':
    case 'w':
      return 'ew-resize'
  }
}

export type IIFOverlayDragCursor = {
  mode: 'move' | 'resize' | 'draw'
  handle?: IIFResizeHandle
}

export function resolveIIFOverlayCursor(
  point: CanvasPoint,
  annotations: TStoryIIFAnnotationType[],
  activeIndex: number | null,
  hideInactiveAnnotations: boolean,
  bounds: IIFCanvasBounds,
  options?: { shiftKey?: boolean; drag?: IIFOverlayDragCursor },
): string {
  if (options?.drag?.mode === 'move') return 'move'
  if (options?.drag?.mode === 'draw') return 'crosshair'
  if (options?.drag?.mode === 'resize' && options.drag.handle) {
    return cursorForIIFResizeHandle(options.drag.handle)
  }

  if (options?.shiftKey && activeIndex != null && activeIndex >= 0) return 'crosshair'

  if (activeIndex != null && activeIndex >= 0) {
    const activeRect = annotations[activeIndex]?.Rect
    if (activeRect && !isDraftIIFRect(activeRect)) {
      const handle = hitTestHandle(point, activeRect, bounds)
      if (handle) return cursorForIIFResizeHandle(handle)
      if (pointInRect(point, activeRect)) return 'move'
    }
  }

  for (let i = annotations.length - 1; i >= 0; i--) {
    if (hideInactiveAnnotations && activeIndex != null && i !== activeIndex) continue
    const rect = annotations[i]!.Rect
    if (isDraftIIFRect(rect)) continue
    if (pointInRect(point, rect)) return 'move'
  }

  return 'default'
}
