import { describe, expect, it } from 'vitest'
import {
  cursorForIIFResizeHandle,
  moveIIFRect,
  rectFromDrawPoints,
  resizeIIFRect,
  resolveIIFOverlayCursor,
  snapIIFCoord,
} from './iifCanvasPointer'

const bounds = { width: 1000, height: 800 }

describe('iifCanvasPointer', () => {
  it('snapIIFCoord arrotonda alla griglia', () => {
    expect(snapIIFCoord(14, true)).toBe(10)
    expect(snapIIFCoord(14, false)).toBe(14)
  })

  it('rectFromDrawPoints normalizza il rettangolo', () => {
    expect(rectFromDrawPoints({ x: 100, y: 50 }, { x: 10, y: 5 })).toEqual({
      x: 10,
      y: 5,
      width: 90,
      height: 45,
    })
  })

  it('moveIIFRect sposta il rettangolo', () => {
    const startRect = { x: 10, y: 20, width: 30, height: 40 }
    const next = moveIIFRect(startRect, { x: 10, y: 20 }, { x: 25, y: 35 }, bounds, false)
    expect(next).toEqual({ x: 25, y: 35, width: 30, height: 40 })
  })

  it('resizeIIFRect ridimensiona dal handle se', () => {
    const startRect = { x: 10, y: 20, width: 30, height: 40 }
    const next = resizeIIFRect(startRect, 'se', { x: 60, y: 80 }, bounds, false)
    expect(next).toEqual({ x: 10, y: 20, width: 50, height: 60 })
  })

  it('cursorForIIFResizeHandle mappa i cursori CSS', () => {
    expect(cursorForIIFResizeHandle('nw')).toBe('nwse-resize')
    expect(cursorForIIFResizeHandle('e')).toBe('ew-resize')
    expect(cursorForIIFResizeHandle('n')).toBe('ns-resize')
  })

  it('resolveIIFOverlayCursor usa move sul rettangolo attivo', () => {
    const annotations = [{ Text: 'a', Rect: { x: 10, y: 10, width: 100, height: 80 } }]
    expect(
      resolveIIFOverlayCursor({ x: 50, y: 50 }, annotations, 0, false, bounds),
    ).toBe('move')
  })

  it('resolveIIFOverlayCursor usa cursori direzionali sui handle', () => {
    const annotations = [{ Text: 'a', Rect: { x: 10, y: 10, width: 100, height: 80 } }]
    expect(
      resolveIIFOverlayCursor({ x: 10, y: 10 }, annotations, 0, false, bounds),
    ).toBe('nwse-resize')
  })
})
