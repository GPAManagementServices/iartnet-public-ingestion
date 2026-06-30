import { describe, expect, it } from 'vitest'
import {
  computeCanvasDisplaySize,
  computeViewportScrollToRect,
  iifAnnotationLabelLayout,
  iiifPreviewRequestWidth,
  stepIifViewportZoom,
} from './iifCanvasViewport'

const bounds = { width: 5153, height: 7064 }

function mockScrollElements(
  scrollBox: { left: number; top: number; width: number; height: number },
  canvasBox: { left: number; top: number; width: number; height: number },
  scrollPos: { left: number; top: number },
  scrollSize: { width: number; height: number },
) {
  const scrollEl = {
    scrollLeft: scrollPos.left,
    scrollTop: scrollPos.top,
    clientWidth: scrollBox.width,
    clientHeight: scrollBox.height,
    scrollWidth: scrollSize.width,
    scrollHeight: scrollSize.height,
    getBoundingClientRect: () => ({
      left: scrollBox.left,
      top: scrollBox.top,
      right: scrollBox.left + scrollBox.width,
      bottom: scrollBox.top + scrollBox.height,
      width: scrollBox.width,
      height: scrollBox.height,
      x: scrollBox.left,
      y: scrollBox.top,
      toJSON: () => ({}),
    }),
  } as HTMLElement

  const canvasEl = {
    getBoundingClientRect: () => ({
      left: canvasBox.left,
      top: canvasBox.top,
      right: canvasBox.left + canvasBox.width,
      bottom: canvasBox.top + canvasBox.height,
      width: canvasBox.width,
      height: canvasBox.height,
      x: canvasBox.left,
      y: canvasBox.top,
      toJSON: () => ({}),
    }),
  } as HTMLElement

  return { scrollEl, canvasEl }
}

describe('iifCanvasViewport', () => {
  it('computeCanvasDisplaySize scala con zoom', () => {
    const fit = 400
    expect(computeCanvasDisplaySize(bounds, fit, 1)).toEqual({ width: 400, height: 548 })
    expect(computeCanvasDisplaySize(bounds, fit, 2)).toEqual({ width: 800, height: 1097 })
  })

  it('stepIifViewportZoom rispetta min e max', () => {
    expect(stepIifViewportZoom(0.5, -1)).toBe(0.5)
    expect(stepIifViewportZoom(8, 1)).toBe(8)
    expect(stepIifViewportZoom(1, 1)).toBe(1.25)
  })

  it('iiifPreviewRequestWidth cresce quando il display supera 800px', () => {
    const w1 = iiifPreviewRequestWidth(bounds, 400, 2)
    const w2 = iiifPreviewRequestWidth(bounds, 400, 3)
    expect(w1).toBe(800)
    expect(w2).toBe(1200)
    expect(w2).toBeLessThanOrEqual(2400)
  })

  it('computeViewportScrollToRect centra un rettangolo fuori vista', () => {
    const displaySize = { width: 400, height: 548 }
    const rect = { x: 3000, y: 4000, width: 200, height: 150 }
    const { scrollEl, canvasEl } = mockScrollElements(
      { left: 0, top: 0, width: 320, height: 200 },
      { left: 8, top: 8, width: 400, height: 548 },
      { left: 0, top: 0 },
      { width: 416, height: 564 },
    )
    const target = computeViewportScrollToRect(scrollEl, canvasEl, rect, bounds, displaySize, 0)
    expect(target).not.toBeNull()
    expect(target!.left).toBeGreaterThan(0)
    expect(target!.top).toBeGreaterThan(0)
  })

  it('computeViewportScrollToRect non scrolla se il rettangolo è già visibile', () => {
    const displaySize = { width: 400, height: 548 }
    const rect = { x: 10, y: 20, width: 100, height: 80 }
    const { scrollEl, canvasEl } = mockScrollElements(
      { left: 0, top: 0, width: 320, height: 200 },
      { left: 8, top: 8, width: 400, height: 548 },
      { left: 0, top: 0 },
      { width: 416, height: 564 },
    )
    const target = computeViewportScrollToRect(scrollEl, canvasEl, rect, bounds, displaySize, 0)
    expect(target).toBeNull()
  })

  it('iifAnnotationLabelLayout centra il testo e mantiene font ~44px schermo', () => {
    const displaySize = { width: 400, height: 548 }
    const rect = { x: 1000, y: 1200, width: 2400, height: 1800 }
    const layout = iifAnnotationLabelLayout(rect, bounds, displaySize)
    const scale = displaySize.width / bounds.width

    expect(layout.textX).toBe(rect.x + rect.width / 2)
    expect(layout.textY).toBe(rect.y + rect.height / 2)
    expect(layout.fontSize * scale).toBeCloseTo(44, 0)
  })

  it('iifAnnotationLabelLayout riduce il font se il rettangolo è piccolo', () => {
    const displaySize = { width: 400, height: 548 }
    const rect = { x: 10, y: 20, width: 30, height: 24 }
    const layout = iifAnnotationLabelLayout(rect, bounds, displaySize)
    const scale = displaySize.width / bounds.width

    expect(layout.fontSize * scale).toBeLessThan(44)
  })
})
