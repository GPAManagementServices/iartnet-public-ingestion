import { useCallback, useEffect, useMemo, useRef, useState } from 'react'
import Form from 'react-bootstrap/Form'
import Modal from 'react-bootstrap/Modal'
import type { TStoryIIFAnnotationType } from '../../types/story'
import type { IIFImageBounds } from '../../story/iiifImage'
import { iiifPreviewUrl, iiifPreviewUrlForZoom } from '../../story/iiifImage'
import {
  computeCanvasDisplaySize,
  computeViewportScrollToRect,
  IIF_VIEWPORT_FIT_WIDTH_DEFAULT,
  IIF_VIEWPORT_FIT_WIDTH_FULLSCREEN,
  IIF_VIEWPORT_ZOOM_MAX,
  IIF_VIEWPORT_ZOOM_MAX_FULLSCREEN,
  IIF_VIEWPORT_ZOOM_MIN,
  measureViewportFitWidth,
  stepIifViewportZoom,
} from '../../story/iifCanvasViewport'
import { isDraftIIFRect } from '../../story/iifRect'
import { IconButton } from '../IconButton'
import { IIFCanvasOverlay } from './IIFCanvasOverlay'

type ViewportLayout = 'embedded' | 'fullscreen'

type ViewportCoreProps = {
  baseUri: string
  bounds: IIFImageBounds | null
  bgColor?: string | null
  annotations: TStoryIIFAnnotationType[]
  layout: ViewportLayout
  activeAnnotationIndex: number | null
  hideInactiveAnnotations?: boolean
  onHideInactiveAnnotationsChange?: (value: boolean) => void
  scrollToActiveTrigger?: number
  onActiveAnnotationIndexChange?: (index: number) => void
  onAnnotationRectChange?: (index: number, rect: TStoryIIFAnnotationType['Rect']) => void
  toolbarExtra?: React.ReactNode
}

function IIFCanvasViewportCore({
  baseUri,
  bounds,
  bgColor,
  annotations,
  layout,
  activeAnnotationIndex,
  hideInactiveAnnotations = false,
  onHideInactiveAnnotationsChange,
  scrollToActiveTrigger = 0,
  onActiveAnnotationIndexChange,
  onAnnotationRectChange,
  toolbarExtra,
}: ViewportCoreProps) {
  const scrollRef = useRef<HTMLDivElement>(null)
  const canvasRef = useRef<HTMLDivElement>(null)
  const annotationsRef = useRef(annotations)
  annotationsRef.current = annotations
  const [zoom, setZoom] = useState(1)
  const [fitWidth, setFitWidth] = useState(IIF_VIEWPORT_FIT_WIDTH_DEFAULT)
  const [imageFailed, setImageFailed] = useState(false)

  const maxZoom = layout === 'fullscreen' ? IIF_VIEWPORT_ZOOM_MAX_FULLSCREEN : IIF_VIEWPORT_ZOOM_MAX
  const maxFit =
    layout === 'fullscreen' ? IIF_VIEWPORT_FIT_WIDTH_FULLSCREEN : IIF_VIEWPORT_FIT_WIDTH_DEFAULT

  const trimmedBase = baseUri.trim()
  const canvasBackground = bgColor?.trim() ? bgColor.trim() : undefined
  const canManageAnnotations = Boolean(onActiveAnnotationIndexChange && onAnnotationRectChange)
  const interactive = Boolean(bounds && canManageAnnotations)

  useEffect(() => {
    setImageFailed(false)
  }, [trimmedBase, bounds?.width, bounds?.height, zoom, fitWidth])

  useEffect(() => {
    const el = scrollRef.current
    if (!el) return

    const update = () => {
      setFitWidth(measureViewportFitWidth(el.clientWidth, 16, maxFit))
    }
    update()

    const ro = new ResizeObserver(update)
    ro.observe(el)
    return () => ro.disconnect()
  }, [maxFit, layout])

  const displaySize = useMemo(() => {
    if (!bounds) return null
    return computeCanvasDisplaySize(bounds, fitWidth, zoom, maxZoom)
  }, [bounds, fitWidth, zoom, maxZoom])

  const imageUrl = useMemo(() => {
    if (!trimmedBase) return ''
    if (!bounds) return iiifPreviewUrl(trimmedBase)
    return iiifPreviewUrlForZoom(trimmedBase, bounds, fitWidth, zoom, 2400, maxZoom)
  }, [trimmedBase, bounds, fitWidth, zoom, maxZoom])

  const resetView = useCallback(() => {
    setZoom(1)
    scrollRef.current?.scrollTo({ left: 0, top: 0 })
  }, [])

  useEffect(() => {
    if (scrollToActiveTrigger === 0) return
    if (activeAnnotationIndex == null || !bounds || !displaySize) return

    const annotation = annotationsRef.current[activeAnnotationIndex]
    if (!annotation || isDraftIIFRect(annotation.Rect)) return

    const raf = requestAnimationFrame(() => {
      const scrollEl = scrollRef.current
      const canvasEl = canvasRef.current
      if (!scrollEl || !canvasEl) return

      const target = computeViewportScrollToRect(
        scrollEl,
        canvasEl,
        annotation.Rect,
        bounds,
        displaySize,
      )
      if (target) {
        scrollEl.scrollTo({ left: target.left, top: target.top, behavior: 'smooth' })
      }
    })

    return () => cancelAnimationFrame(raf)
  }, [scrollToActiveTrigger, activeAnnotationIndex, bounds, displaySize])

  const zoomLabel = `${Math.round(zoom * 100)}%`

  return (
    <div className={`iif-canvas-viewport ${layout === 'fullscreen' ? 'iif-canvas-viewport--fullscreen' : ''}`}>
      <div className="iif-canvas-viewport__toolbar d-flex flex-wrap gap-1 align-items-center mb-1">
        <IconButton
          type="button"
          variant="outline-secondary"
          size="sm"
          icon="dash-lg"
          aria-label="Zoom indietro"
          disabled={!bounds || zoom <= IIF_VIEWPORT_ZOOM_MIN}
          onClick={() => setZoom((z) => stepIifViewportZoom(z, -1, maxZoom))}
        />
        <span className="text-muted small user-select-none" aria-live="polite">
          {zoomLabel}
        </span>
        <IconButton
          type="button"
          variant="outline-secondary"
          size="sm"
          icon="plus-lg"
          aria-label="Zoom avanti"
          disabled={!bounds}
          onClick={() => setZoom((z) => stepIifViewportZoom(z, 1, maxZoom))}
        />
        <IconButton
          type="button"
          variant="outline-secondary"
          size="sm"
          icon="aspect-ratio"
          disabled={!bounds}
          onClick={resetView}
        >
          Adatta
        </IconButton>
        {canManageAnnotations && onHideInactiveAnnotationsChange ? (
          <Form.Check
            type="switch"
            id={`iif-hide-inactive-${layout}`}
            className="small ms-1 mb-0 d-inline-flex align-items-center gap-1"
            label="Solo attiva"
            checked={hideInactiveAnnotations}
            disabled={activeAnnotationIndex == null}
            onChange={(e) => onHideInactiveAnnotationsChange(e.target.checked)}
          />
        ) : null}
        {interactive ? (
          <span className="text-muted small ms-1 d-none d-lg-inline">
            Trascina per spostare · Shift+trascina per disegnare · Alt: no griglia
          </span>
        ) : null}
        {toolbarExtra}
        {!bounds ? (
          <span className="text-muted small ms-auto">Attendi dimensioni canvas per lo zoom</span>
        ) : null}
      </div>

      <div
        ref={scrollRef}
        className={`iif-canvas-viewport__scroll ${layout === 'fullscreen' ? 'iif-canvas-viewport__scroll--fullscreen' : ''}`}
        aria-label="Canvas IIIF zoomabile"
      >
        {!bounds && trimmedBase ? (
          <div
            className="iif-canvas-viewport__canvas iif-canvas-viewport__canvas--pending"
            style={canvasBackground ? { backgroundColor: canvasBackground } : undefined}
          >
            <img
              src={imageUrl}
              alt=""
              className="iif-canvas-viewport__img"
              draggable={false}
              onError={() => setImageFailed(true)}
            />
          </div>
        ) : null}
        {displaySize && bounds && !imageFailed ? (
          <div
            ref={canvasRef}
            className="iif-canvas-viewport__canvas"
            style={{
              width: displaySize.width,
              height: displaySize.height,
              ...(canvasBackground ? { backgroundColor: canvasBackground } : {}),
            }}
          >
            <img
              src={imageUrl}
              alt=""
              className="iif-canvas-viewport__img"
              draggable={false}
              onError={() => setImageFailed(true)}
            />
            {interactive ? (
              <IIFCanvasOverlay
                bounds={bounds}
                displaySize={displaySize}
                annotations={annotations}
                activeIndex={activeAnnotationIndex}
                hideInactiveAnnotations={hideInactiveAnnotations}
                canvasRef={canvasRef}
                onSelectIndex={onActiveAnnotationIndexChange!}
                onRectChange={onAnnotationRectChange!}
              />
            ) : null}
          </div>
        ) : bounds && imageFailed ? (
          <div className="iif-canvas-viewport__placeholder text-muted">Anteprima non disponibile</div>
        ) : bounds ? (
          <div className="iif-canvas-viewport__placeholder text-muted">Caricamento canvas…</div>
        ) : null}
      </div>
    </div>
  )
}

type Props = {
  baseUri: string
  bounds: IIFImageBounds | null
  bgColor?: string | null
  annotations?: TStoryIIFAnnotationType[]
  activeAnnotationIndex?: number | null
  hideInactiveAnnotations?: boolean
  onHideInactiveAnnotationsChange?: (value: boolean) => void
  scrollToActiveTrigger?: number
  onActiveAnnotationIndexChange?: (index: number) => void
  onAnnotationRectChange?: (index: number, rect: TStoryIIFAnnotationType['Rect']) => void
}

export function IIFCanvasViewport({
  baseUri,
  bounds,
  bgColor,
  annotations = [],
  activeAnnotationIndex = null,
  hideInactiveAnnotations = false,
  onHideInactiveAnnotationsChange,
  scrollToActiveTrigger = 0,
  onActiveAnnotationIndexChange,
  onAnnotationRectChange,
}: Props) {
  const [expanded, setExpanded] = useState(false)

  if (!baseUri.trim()) {
    return (
      <div className="iif-canvas-viewport">
        <div className="iif-canvas-viewport__placeholder text-muted">Nessuna immagine</div>
      </div>
    )
  }

  const coreProps = {
    baseUri,
    bounds,
    bgColor,
    annotations,
    activeAnnotationIndex,
    hideInactiveAnnotations,
    onHideInactiveAnnotationsChange,
    scrollToActiveTrigger,
    onActiveAnnotationIndexChange,
    onAnnotationRectChange,
  }

  return (
    <>
      <IIFCanvasViewportCore
        {...coreProps}
        layout="embedded"
        toolbarExtra={
          bounds ? (
            <IconButton
              type="button"
              variant="outline-secondary"
              size="sm"
              icon="arrows-fullscreen"
              className="ms-auto"
              onClick={() => setExpanded(true)}
            >
              Espandi
            </IconButton>
          ) : null
        }
      />

      <Modal show={expanded} onHide={() => setExpanded(false)} fullscreen scrollable>
        <Modal.Header closeButton className="py-2">
          <Modal.Title className="fs-6">Canvas IIIF · annotazioni</Modal.Title>
        </Modal.Header>
        <Modal.Body className="p-2">
          <IIFCanvasViewportCore {...coreProps} layout="fullscreen" />
        </Modal.Body>
      </Modal>
    </>
  )
}
