import { useEffect, useRef, useState } from 'react'
import type { TStoryIIFAnnotationType } from '../../types/story'
import type { IIFImageBounds } from '../../story/iiifImage'
import type { CanvasDisplaySize } from '../../story/iifCanvasViewport'
import { iifAnnotationLabelLayout } from '../../story/iifCanvasViewport'
import {
  canvasPointFromClient,
  cursorForIIFResizeHandle,
  hitTestHandle,
  moveIIFRect,
  pointInRect,
  rectFromDrawPoints,
  resizeIIFRect,
  resolveIIFOverlayCursor,
  type CanvasPoint,
  type IIFResizeHandle,
} from '../../story/iifCanvasPointer'
import { clampIIFRect, isDraftIIFRect, validateIIFRect } from '../../story/iifRect'

type DragState =
  | { mode: 'move'; index: number; start: CanvasPoint; startRect: TStoryIIFAnnotationType['Rect'] }
  | { mode: 'resize'; index: number; handle: IIFResizeHandle; startRect: TStoryIIFAnnotationType['Rect'] }
  | { mode: 'draw'; index: number; start: CanvasPoint }
  | null

type Props = {
  bounds: IIFImageBounds
  displaySize: CanvasDisplaySize
  annotations: TStoryIIFAnnotationType[]
  activeIndex: number | null
  hideInactiveAnnotations?: boolean
  canvasRef: React.RefObject<HTMLDivElement | null>
  onSelectIndex: (index: number) => void
  onRectChange: (index: number, rect: TStoryIIFAnnotationType['Rect']) => void
}

const HANDLES: IIFResizeHandle[] = ['nw', 'n', 'ne', 'e', 'se', 's', 'sw', 'w']

export function IIFCanvasOverlay({
  bounds,
  displaySize,
  annotations,
  activeIndex,
  hideInactiveAnnotations = false,
  canvasRef,
  onSelectIndex,
  onRectChange,
}: Props) {
  const dragRef = useRef<DragState>(null)
  const annotationsRef = useRef(annotations)
  annotationsRef.current = annotations
  const [drawPreview, setDrawPreview] = useState<TStoryIIFAnnotationType['Rect'] | null>(null)
  const [overlayCursor, setOverlayCursor] = useState('default')

  const applyCursor = (cursor: string) => {
    setOverlayCursor(cursor)
    document.body.style.cursor = cursor === 'default' ? '' : cursor
  }

  const cursorAtClient = (clientX: number, clientY: number, shiftKey = false, drag?: DragState) => {
    const canvas = canvasRef.current
    if (!canvas) return 'default'
    const point = canvasPointFromClient(clientX, clientY, canvas, bounds, false)
    return resolveIIFOverlayCursor(
      point,
      annotationsRef.current,
      activeIndex,
      hideInactiveAnnotations,
      bounds,
      {
        shiftKey,
        drag: drag
          ? {
              mode: drag.mode,
              handle: drag.mode === 'resize' ? drag.handle : undefined,
            }
          : undefined,
      },
    )
  }

  useEffect(() => {
    const onMove = (e: PointerEvent) => {
      const drag = dragRef.current
      const canvas = canvasRef.current
      if (!drag || !canvas) return

      const snap = !e.altKey
      const point = canvasPointFromClient(e.clientX, e.clientY, canvas, bounds, snap)

      if (drag.mode === 'move') {
        onRectChange(drag.index, moveIIFRect(drag.startRect, drag.start, point, bounds, snap))
      } else if (drag.mode === 'resize') {
        onRectChange(
          drag.index,
          resizeIIFRect(drag.startRect, drag.handle, point, bounds, snap),
        )
      } else if (drag.mode === 'draw') {
        const draft = rectFromDrawPoints(drag.start, point)
        setDrawPreview(
          clampIIFRect(
            {
              x: draft.x,
              y: draft.y,
              width: Math.max(0, draft.width),
              height: Math.max(0, draft.height),
            },
            bounds,
          ),
        )
      }

      applyCursor(cursorAtClient(e.clientX, e.clientY, e.shiftKey, drag))
    }

    const onUp = (e: PointerEvent) => {
      const drag = dragRef.current
      const canvas = canvasRef.current
      if (!drag || !canvas) return

      if (drag.mode === 'draw') {
        const snap = !e.altKey
        const point = canvasPointFromClient(e.clientX, e.clientY, canvas, bounds, snap)
        const drawn = rectFromDrawPoints(drag.start, point)
        if (drawn.width >= 1 && drawn.height >= 1) {
          onRectChange(drag.index, clampIIFRect(drawn, bounds))
        }
        setDrawPreview(null)
      }

      dragRef.current = null
      setDrawPreview(null)
      document.body.style.cursor = ''
      setOverlayCursor(cursorAtClient(e.clientX, e.clientY, e.shiftKey))
    }

    window.addEventListener('pointermove', onMove)
    window.addEventListener('pointerup', onUp)
    return () => {
      window.removeEventListener('pointermove', onMove)
      window.removeEventListener('pointerup', onUp)
      document.body.style.cursor = ''
    }
  }, [activeIndex, bounds, canvasRef, hideInactiveAnnotations, onRectChange])

  const onPointerDown = (e: React.PointerEvent<SVGSVGElement>) => {
    if (e.button !== 0) return
    const canvas = canvasRef.current
    if (!canvas) return

    const snap = !e.altKey
    const point = canvasPointFromClient(e.clientX, e.clientY, canvas, bounds, snap)

    if (e.shiftKey && activeIndex != null && activeIndex >= 0) {
      dragRef.current = { mode: 'draw', index: activeIndex, start: point }
      setDrawPreview({ x: point.x, y: point.y, width: 0, height: 0 })
      applyCursor('crosshair')
      e.preventDefault()
      return
    }

    if (activeIndex != null && activeIndex >= 0) {
      const activeRect = annotations[activeIndex]?.Rect
      if (activeRect && !isDraftIIFRect(activeRect)) {
        const handle = hitTestHandle(point, activeRect, bounds)
        if (handle) {
          dragRef.current = { mode: 'resize', index: activeIndex, handle, startRect: { ...activeRect } }
          applyCursor(cursorForIIFResizeHandle(handle))
          e.preventDefault()
          return
        }
      }
    }

    for (let i = annotations.length - 1; i >= 0; i--) {
      if (hideInactiveAnnotations && activeIndex != null && i !== activeIndex) continue
      const rect = annotations[i]!.Rect
      if (isDraftIIFRect(rect)) continue
      if (!pointInRect(point, rect)) continue
      onSelectIndex(i)
      dragRef.current = { mode: 'move', index: i, start: point, startRect: { ...rect } }
      applyCursor('move')
      e.preventDefault()
      return
    }
  }

  const onPointerMove = (e: React.PointerEvent<SVGSVGElement>) => {
    if (dragRef.current) return
    applyCursor(cursorAtClient(e.clientX, e.clientY, e.shiftKey))
  }

  const onPointerLeave = () => {
    if (dragRef.current) return
    applyCursor('default')
  }

  const strokeWidthInactive = 1.5
  const strokeWidthActive = 2.5
  const handleRadius = Math.max(5, bounds.width / 120)

  return (
    <svg
      className="iif-canvas-viewport__overlay iif-canvas-viewport__overlay--interactive"
      viewBox={`0 0 ${bounds.width} ${bounds.height}`}
      preserveAspectRatio="none"
      style={{ cursor: overlayCursor }}
      onPointerDown={onPointerDown}
      onPointerMove={onPointerMove}
      onPointerLeave={onPointerLeave}
    >
      {annotations.map((annotation, index) => {
        if (hideInactiveAnnotations && activeIndex != null && index !== activeIndex) {
          return null
        }
        const { Rect } = annotation
        const isActive = index === activeIndex
        const displayRect = isActive && drawPreview ? drawPreview : Rect
        const isDrawing = isActive && drawPreview != null
        if (isDraftIIFRect(displayRect) && !(isDrawing && (displayRect.width > 0 || displayRect.height > 0))) {
          return null
        }

        const v = validateIIFRect(displayRect, bounds)
        const isInvalid = !v.valid && !v.isDraft
        const stroke = isActive ? (isInvalid ? '#dc3545' : '#0a58ca') : isInvalid ? '#dc3545' : '#6c757d'
        const fill = isActive
          ? isInvalid
            ? 'rgba(220, 53, 69, 0.25)'
            : 'rgba(13, 110, 253, 0.22)'
          : isInvalid
            ? 'rgba(220, 53, 69, 0.12)'
            : 'rgba(108, 117, 125, 0.12)'
        const label =
          displayRect.width > 0 && displayRect.height > 0
            ? iifAnnotationLabelLayout(displayRect, bounds, displaySize)
            : null
        const labelFill = stroke

        return (
          <g key={index}>
            <rect
              x={displayRect.x}
              y={displayRect.y}
              width={Math.max(0, displayRect.width)}
              height={Math.max(0, displayRect.height)}
              fill={fill}
              stroke={stroke}
              strokeWidth={isActive ? strokeWidthActive : strokeWidthInactive}
              vectorEffect="non-scaling-stroke"
              className={isActive ? 'iif-canvas-rect--active' : undefined}
            />
            {label ? (
              <text
                className="iif-canvas-label"
                x={label.textX}
                y={label.textY}
                textAnchor="middle"
                dominantBaseline="middle"
                fontSize={label.fontSize}
                fill={labelFill}
                pointerEvents="none"
              >
                {index + 1}
              </text>
            ) : null}
            {isActive && !isDraftIIFRect(displayRect)
              ? HANDLES.map((handle) => {
                  const cx =
                    handle === 'nw' || handle === 'w' || handle === 'sw'
                      ? displayRect.x
                      : handle === 'ne' || handle === 'e' || handle === 'se'
                        ? displayRect.x + displayRect.width
                        : displayRect.x + displayRect.width / 2
                  const cy =
                    handle === 'nw' || handle === 'n' || handle === 'ne'
                      ? displayRect.y
                      : handle === 'sw' || handle === 's' || handle === 'se'
                        ? displayRect.y + displayRect.height
                        : displayRect.y + displayRect.height / 2
                  return (
                    <circle
                      key={handle}
                      cx={cx}
                      cy={cy}
                      r={handleRadius}
                      className="iif-canvas-handle"
                      vectorEffect="non-scaling-stroke"
                    />
                  )
                })
              : null}
          </g>
        )
      })}
    </svg>
  )
}
