import { useEffect, useMemo, useRef, useState } from 'react'
import Alert from 'react-bootstrap/Alert'
import Form from 'react-bootstrap/Form'
import type { TStoryIIFAnnotationType, TStoryIIFImageType } from '../../types/story'
import {
  extractIiifBaseUri,
  fetchIiifImageInfo,
  iifImageBounds,
  resolveIiifBaseUri,
} from '../../story/iiifImage'
import { FORM_COLOR_CONTROL, FORM_GROUP_GAP, FORM_LABEL } from '../formStyles'
import { RichTextField } from './RichTextField'
import { IIFCanvasViewport } from './IIFCanvasViewport'

const textControlStyle = { width: '100%' } as const
const INFO_JSON_DEBOUNCE_MS = 500

type Props = {
  value: TStoryIIFImageType
  onChange: (next: TStoryIIFImageType) => void
  caption?: string | null
  onCaptionChange?: (caption: string | null) => void
  /** Rettangoli annotazioni disegnati sull'anteprima (coordinate canvas). */
  annotations?: TStoryIIFAnnotationType[]
  activeAnnotationIndex?: number | null
  hideInactiveAnnotations?: boolean
  onHideInactiveAnnotationsChange?: (value: boolean) => void
  scrollToActiveTrigger?: number
  onActiveAnnotationIndexChange?: (index: number) => void
  onAnnotationRectChange?: (index: number, rect: TStoryIIFAnnotationType['Rect']) => void
}

function resolveBaseUri(raw: string): string | null {
  return resolveIiifBaseUri(raw)
}

export function IIFImageFields({
  value,
  onChange,
  caption = null,
  onCaptionChange,
  annotations = [],
  activeAnnotationIndex = null,
  hideInactiveAnnotations = false,
  onHideInactiveAnnotationsChange,
  scrollToActiveTrigger = 0,
  onActiveAnnotationIndexChange,
  onAnnotationRectChange,
}: Props) {
  const [fetchError, setFetchError] = useState<string | null>(null)
  const [fetching, setFetching] = useState(false)
  const lastFetchedBaseRef = useRef<string | null>(null)
  const valueRef = useRef(value)
  const onChangeRef = useRef(onChange)
  valueRef.current = value
  onChangeRef.current = onChange

  const resolvedBaseUri = useMemo(() => resolveBaseUri(value.BaseURI), [value.BaseURI])
  const bounds = iifImageBounds(value)

  const setImage = (patch: Partial<TStoryIIFImageType>) => {
    onChange({ ...value, ...patch })
  }

  const commitBaseUri = (raw: string) => {
    const trimmed = raw.trim()
    const base = trimmed ? (extractIiifBaseUri(trimmed) ?? trimmed) : ''
    const baseChanged = base !== value.BaseURI.trim()
    if (baseChanged) {
      lastFetchedBaseRef.current = null
    }
    setImage({
      BaseURI: base,
      ...(baseChanged ? { Width: null, Height: null } : {}),
    })
  }

  useEffect(() => {
    if (!resolvedBaseUri) {
      setFetchError(null)
      setFetching(false)
      return
    }

    const current = valueRef.current
    if (
      current.BaseURI.trim() === resolvedBaseUri &&
      current.Width != null &&
      current.Height != null
    ) {
      lastFetchedBaseRef.current = resolvedBaseUri
      setFetchError(null)
      setFetching(false)
      return
    }

    if (lastFetchedBaseRef.current === resolvedBaseUri) {
      return
    }

    setFetching(true)
    setFetchError(null)

    let active = true
    const timer = window.setTimeout(() => {
      void fetchIiifImageInfo(resolvedBaseUri).then((result) => {
        if (!active) return
        if (result.ok) {
          lastFetchedBaseRef.current = resolvedBaseUri
          onChangeRef.current({
            ...valueRef.current,
            BaseURI: resolvedBaseUri,
            Width: result.width,
            Height: result.height,
          })
          setFetchError(null)
        } else {
          lastFetchedBaseRef.current = null
          setFetchError(result.error)
        }
        setFetching(false)
      })
    }, INFO_JSON_DEBOUNCE_MS)

    return () => {
      active = false
      window.clearTimeout(timer)
    }
  }, [resolvedBaseUri])

  return (
    <fieldset className="border rounded p-2 mb-0 small">
      <legend className="float-none w-auto px-2 mb-0 small text-muted">IIIF: immagine</legend>
      <div className="d-flex gap-2 align-items-start story-image-fields-row">
        <div className="story-image-fields__inputs d-flex flex-column">
          <Form.Group className={FORM_GROUP_GAP} controlId="iif-base-uri">
            <Form.Label className={FORM_LABEL}>BaseURI</Form.Label>
            <Form.Control
              size="sm"
              className="mw-100 font-monospace"
              style={textControlStyle}
              value={value.BaseURI}
              title={value.BaseURI}
              placeholder="https://…/iiif/2/uuid.tif"
              onChange={(e) => {
                const next = e.target.value
                if (resolveBaseUri(next) !== resolveBaseUri(value.BaseURI)) {
                  lastFetchedBaseRef.current = null
                }
                setImage({ BaseURI: next })
              }}
              onBlur={(e) => commitBaseUri(e.target.value)}
            />
            <Form.Text className="text-muted">
              Incolla il base IIIF o un URL completo: dimensioni e anteprima si aggiornano automaticamente.
            </Form.Text>
          </Form.Group>

          <div className="d-flex flex-wrap gap-2 align-items-center mb-0">
            {fetching ? (
              <span className="text-muted small">Caricamento info.json…</span>
            ) : bounds ? (
              <span className="text-muted small">
                Canvas {bounds.width} × {bounds.height} px
              </span>
            ) : resolvedBaseUri ? (
              <span className="text-muted small">In attesa di info.json…</span>
            ) : (
              <span className="text-muted small">Inserisci un BaseURI IIIF valido</span>
            )}
          </div>

          {onCaptionChange ? (
            <RichTextField
              label="Caption"
              value={caption ?? ''}
              allowImages
              onChange={(next) => onCaptionChange(next === '' ? null : next)}
            />
          ) : null}

          <Form.Group className="mb-0 w-auto" controlId="iif-bg-color">
            <Form.Label className={FORM_LABEL}>bgColor (opz.)</Form.Label>
            <Form.Control
              size="sm"
              className={FORM_COLOR_CONTROL}
              style={{ maxWidth: 'min(100%, 14rem)', minWidth: '8ch' }}
              value={value.bgColor ?? ''}
              placeholder="#RRGGBB"
              onChange={(e) =>
                setImage({
                  bgColor: e.target.value === '' ? null : e.target.value,
                })
              }
            />
          </Form.Group>

          {fetchError ? (
            <Alert variant="warning" className="py-1 px-2 mb-0 small">
              {fetchError}
            </Alert>
          ) : null}
        </div>
        <div className="story-image-preview-col">
          <IIFCanvasViewport
            baseUri={resolvedBaseUri ?? value.BaseURI}
            bounds={bounds}
            bgColor={value.bgColor}
            annotations={annotations}
            activeAnnotationIndex={activeAnnotationIndex}
            hideInactiveAnnotations={hideInactiveAnnotations}
            onHideInactiveAnnotationsChange={onHideInactiveAnnotationsChange}
            scrollToActiveTrigger={scrollToActiveTrigger}
            onActiveAnnotationIndexChange={onActiveAnnotationIndexChange}
            onAnnotationRectChange={onAnnotationRectChange}
          />
        </div>
      </div>
    </fieldset>
  )
}
