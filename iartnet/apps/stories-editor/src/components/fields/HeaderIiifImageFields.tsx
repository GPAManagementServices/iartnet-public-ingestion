import type { ReactNode } from 'react'
import { useCallback, useEffect, useMemo, useRef, useState } from 'react'
import Alert from 'react-bootstrap/Alert'
import Form from 'react-bootstrap/Form'
import type { TStoryIIFAnnotationType, TStoryImageType } from '../../types/story'
import {
  buildIiifImageUrl,
  extractIiifBaseUri,
  fetchIiifImageInfo,
  IIIF_IMAGE_URL_DEFAULTS,
  iiifDeliveryUrl,
  iiifRectToRegion,
  iiifRegionToRect,
  isIiifImageRegionFull,
  parseIiifImageUrl,
  resolveIiifBaseUri,
  type IiifImageRegion,
  type IiifImageSize,
  type IIFImageBounds,
} from '../../story/iiifImage'
import { IconButton } from '../IconButton'
import { FORM_ADJACENT_ROW, FORM_COLOR_CONTROL, FORM_GROUP_GAP, FORM_LABEL } from '../formStyles'
import { IIFCanvasViewport } from './IIFCanvasViewport'
import { IIFRectFields } from './IIFRectFields'
import { StoryImageCaptionField } from './StoryImageCaptionField'
import { StoryImagePreview } from './StoryImagePreview'

const textControlStyle = { width: '100%' } as const
const INFO_JSON_DEBOUNCE_MS = 500

type Props = {
  prefix: string
  value: TStoryImageType
  onChange: (next: TStoryImageType) => void
  showCaption?: boolean
  showBgColor?: boolean
  /** Quando false (es. dentro accordion), il titolo è esterno al componente. */
  showLegend?: boolean
  legendAction?: ReactNode
}

function parseOptionalOutputDimension(raw: string): number | null {
  const trimmed = raw.trim()
  if (!trimmed) return null
  const n = Number(trimmed)
  if (!Number.isFinite(n) || n <= 0) return null
  return Math.round(n)
}

function formatOptionalOutputDimension(value: number | null | undefined): string {
  return value == null ? '' : String(value)
}

export function HeaderIiifImageFields({
  prefix,
  value,
  onChange,
  showCaption = true,
  showBgColor = true,
  showLegend = true,
  legendAction,
}: Props) {
  const lastEmittedUrlRef = useRef(value.URL)
  const valueRef = useRef(value)
  valueRef.current = value

  const initialParsed = useMemo(() => parseIiifImageUrl(value.URL), [])
  const [manualMode, setManualMode] = useState(() => initialParsed === null)
  const [baseUri, setBaseUri] = useState(initialParsed?.baseUri ?? '')
  const [region, setRegion] = useState<IiifImageRegion>(initialParsed?.region ?? 'full')
  const [size, setSize] = useState<IiifImageSize>(
    initialParsed?.size ?? { width: null, height: null, keyword: 'full' },
  )
  const [canvasBounds, setCanvasBounds] = useState<IIFImageBounds | null>(null)
  const [fetchError, setFetchError] = useState<string | null>(null)
  const [fetching, setFetching] = useState(false)
  const lastFetchedBaseRef = useRef<string | null>(null)

  const resolvedBaseUri = useMemo(() => resolveIiifBaseUri(baseUri), [baseUri])

  const buildParts = useCallback(
    (
      nextBaseUri: string,
      nextRegion: IiifImageRegion,
      nextSize: IiifImageSize,
    ) => ({
      baseUri: nextBaseUri.replace(/\/$/, ''),
      region: nextRegion,
      size: nextSize,
      ...IIIF_IMAGE_URL_DEFAULTS,
    }),
    [],
  )

  const emitIiifUrl = useCallback(
    (nextBaseUri: string, nextRegion: IiifImageRegion, nextSize: IiifImageSize) => {
      const trimmedBase = nextBaseUri.trim()
      if (!trimmedBase || !resolveIiifBaseUri(trimmedBase)) return
      const url = buildIiifImageUrl(buildParts(trimmedBase, nextRegion, nextSize))
      lastEmittedUrlRef.current = url
      onChange({ ...valueRef.current, URL: url })
    },
    [buildParts, onChange],
  )

  const applyIiifState = useCallback(
    (
      nextBaseUri: string,
      nextRegion: IiifImageRegion,
      nextSize: IiifImageSize,
      options?: { emit?: boolean },
    ) => {
      setBaseUri(nextBaseUri)
      setRegion(nextRegion)
      setSize(nextSize)
      if (options?.emit !== false) {
        emitIiifUrl(nextBaseUri, nextRegion, nextSize)
      }
    },
    [emitIiifUrl],
  )

  useEffect(() => {
    if (value.URL === lastEmittedUrlRef.current) return
    lastEmittedUrlRef.current = value.URL

    const parts = parseIiifImageUrl(value.URL)
    if (parts) {
      setManualMode(false)
      setBaseUri(parts.baseUri)
      setRegion(parts.region)
      setSize(parts.size)
      return
    }

    setManualMode(true)
    setBaseUri('')
    setRegion('full')
    setSize({ width: null, height: null, keyword: 'full' })
    setCanvasBounds(null)
  }, [value.URL])

  useEffect(() => {
    if (manualMode || !resolvedBaseUri) {
      setFetchError(null)
      setFetching(false)
      if (!resolvedBaseUri) setCanvasBounds(null)
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
          setCanvasBounds({ width: result.width, height: result.height })
          setFetchError(null)
        } else {
          lastFetchedBaseRef.current = null
          setCanvasBounds(null)
          setFetchError(result.error)
        }
        setFetching(false)
      })
    }, INFO_JSON_DEBOUNCE_MS)

    return () => {
      active = false
      window.clearTimeout(timer)
    }
  }, [manualMode, resolvedBaseUri])

  const cropRect = useMemo(
    () => iiifRegionToRect(region, canvasBounds),
    [region, canvasBounds],
  )

  const cropAnnotations = useMemo<TStoryIIFAnnotationType[]>(
    () => [{ Text: '', Rect: cropRect }],
    [cropRect],
  )

  const composedUrl = useMemo(() => {
    if (manualMode || !resolvedBaseUri) return value.URL
    return buildIiifImageUrl(buildParts(resolvedBaseUri, region, size))
  }, [manualMode, resolvedBaseUri, region, size, value.URL, buildParts])

  const previewUrl = useMemo(() => {
    if (manualMode || !resolvedBaseUri) return value.URL
    const parts = buildParts(resolvedBaseUri, region, size)
    return iiifDeliveryUrl(parts, 480)
  }, [manualMode, resolvedBaseUri, region, size, value.URL, buildParts])

  const commitBaseUri = (raw: string) => {
    const trimmed = raw.trim()
    const base = trimmed ? (extractIiifBaseUri(trimmed) ?? trimmed) : ''
    const baseChanged = base !== baseUri.trim()
    if (baseChanged) {
      lastFetchedBaseRef.current = null
      setCanvasBounds(null)
    }
    applyIiifState(base, baseChanged ? 'full' : region, size)
  }

  const normalizeOutputSize = (width: number | null, height: number | null): IiifImageSize => {
    if (width != null || height != null) {
      return { width, height }
    }
    return { width: null, height: null, keyword: 'full' }
  }

  const setOutputWidth = (raw: string) => {
    const width = parseOptionalOutputDimension(raw)
    applyIiifState(baseUri, region, normalizeOutputSize(width, size.height))
  }

  const setOutputHeight = (raw: string) => {
    const height = parseOptionalOutputDimension(raw)
    applyIiifState(baseUri, region, normalizeOutputSize(size.width, height))
  }

  const setCropRect = (rect: TStoryIIFAnnotationType['Rect']) => {
    const nextRegion = canvasBounds ? iiifRectToRegion(rect, canvasBounds) : rect
    applyIiifState(baseUri, nextRegion, size)
  }

  const useFullImage = () => {
    applyIiifState(baseUri, 'full', size)
  }

  const enterIiifMode = () => {
    setManualMode(false)
    lastFetchedBaseRef.current = null
    setCanvasBounds(null)
    if (!baseUri.trim()) {
      applyIiifState('', 'full', { width: null, height: null, keyword: 'full' }, { emit: false })
      return
    }
    applyIiifState(baseUri, region, size)
  }

  const enterManualMode = () => {
    setManualMode(true)
    lastEmittedUrlRef.current = value.URL
  }

  const sizeHint =
    size.keyword === 'max'
      ? 'Size IIIF: max (modifica Larg o Alt per sostituirla)'
      : size.keyword === 'full' && size.width == null && size.height == null
        ? 'Size IIIF: full (imposta Larg o Alt per dimensione finale)'
        : null

  const fields = (
    <>
      <div className="d-flex flex-wrap gap-2 mb-2">
        <Form.Check
          type="radio"
          id={`${prefix}-mode-iiif`}
          name={`${prefix}-image-mode`}
          className="small"
          label="IIIF"
          checked={!manualMode}
          onChange={enterIiifMode}
        />
        <Form.Check
          type="radio"
          id={`${prefix}-mode-manual`}
          name={`${prefix}-image-mode`}
          className="small"
          label="URL manuale"
          checked={manualMode}
          onChange={enterManualMode}
        />
      </div>

      <div className="d-flex gap-2 align-items-start story-image-fields-row">
        <div className="story-image-fields__inputs d-flex flex-column">
          {manualMode ? (
            <Form.Group className={FORM_GROUP_GAP} controlId={`${prefix}-url-manual`}>
              <Form.Label className={FORM_LABEL}>URL</Form.Label>
              <Form.Control
                size="sm"
                className="mw-100"
                style={textControlStyle}
                value={value.URL}
                title={value.URL}
                onChange={(e) => {
                  const url = e.target.value
                  lastEmittedUrlRef.current = url
                  const parts = parseIiifImageUrl(url)
                  if (parts) {
                    setManualMode(false)
                    setBaseUri(parts.baseUri)
                    setRegion(parts.region)
                    setSize(parts.size)
                  }
                  onChange({ ...value, URL: url })
                }}
              />
              <Form.Text className="text-muted">
                Incolla un URL IIIF completo per passare automaticamente alla modalità IIIF.
              </Form.Text>
            </Form.Group>
          ) : (
            <>
              <Form.Group className={FORM_GROUP_GAP} controlId={`${prefix}-base-uri`}>
                <Form.Label className={FORM_LABEL}>BaseURI</Form.Label>
                <Form.Control
                  size="sm"
                  className="mw-100 font-monospace"
                  style={textControlStyle}
                  value={baseUri}
                  title={baseUri}
                  placeholder="https://…/iiif/2/uuid.tif"
                  onChange={(e) => {
                    const next = e.target.value
                    if (resolveIiifBaseUri(next) !== resolveIiifBaseUri(baseUri)) {
                      lastFetchedBaseRef.current = null
                      setCanvasBounds(null)
                    }
                    setBaseUri(next)
                  }}
                  onBlur={(e) => commitBaseUri(e.target.value)}
                />
                <Form.Text className="text-muted">
                  Incolla il base IIIF o un URL completo: coordinate e anteprima si aggiornano da
                  info.json.
                </Form.Text>
              </Form.Group>

              <div className="d-flex flex-wrap gap-2 align-items-center mb-2">
                {fetching ? (
                  <span className="text-muted small">Caricamento info.json…</span>
                ) : canvasBounds ? (
                  <span className="text-muted small">
                    Canvas {canvasBounds.width} × {canvasBounds.height} px
                  </span>
                ) : resolvedBaseUri ? (
                  <span className="text-muted small">In attesa di info.json…</span>
                ) : (
                  <span className="text-muted small">Inserisci un BaseURI IIIF valido</span>
                )}
                {canvasBounds && !isIiifImageRegionFull(region) ? (
                  <IconButton
                    type="button"
                    variant="outline-secondary"
                    size="sm"
                    icon="image"
                    className="py-0 px-1"
                    onClick={useFullImage}
                  >
                    Immagine intera
                  </IconButton>
                ) : null}
              </div>

              <IIFRectFields
                rowId={`${prefix}-crop`}
                rect={cropRect}
                bounds={canvasBounds}
                onChange={setCropRect}
              />

              <Form.Group className={FORM_GROUP_GAP} controlId={`${prefix}-iiif-size`}>
                <Form.Label className={FORM_LABEL}>Dimensione finale</Form.Label>
                {sizeHint ? <div className="text-muted small mb-1">{sizeHint}</div> : null}
                <div className={`${FORM_ADJACENT_ROW} flex-wrap`}>
                  <Form.Group className="mb-0 flex-shrink-0" controlId={`${prefix}-iiif-larg`}>
                    <Form.Label className={FORM_LABEL}>Larg</Form.Label>
                    <Form.Control
                      size="sm"
                      type="number"
                      min={1}
                      step={1}
                      value={formatOptionalOutputDimension(size.width)}
                      onChange={(e) => setOutputWidth(e.target.value)}
                    />
                  </Form.Group>
                  <Form.Group className="mb-0 flex-shrink-0" controlId={`${prefix}-iiif-alt`}>
                    <Form.Label className={FORM_LABEL}>Alt</Form.Label>
                    <Form.Control
                      size="sm"
                      type="number"
                      min={1}
                      step={1}
                      value={formatOptionalOutputDimension(size.height)}
                      onChange={(e) => setOutputHeight(e.target.value)}
                    />
                  </Form.Group>
                </div>
                <Form.Text className="text-muted">
                  Segmento IIIF size: solo Larg → `w,`; solo Alt → `,h`; entrambi → `w,h`.
                </Form.Text>
              </Form.Group>

              <Form.Group className={FORM_GROUP_GAP} controlId={`${prefix}-url-composed`}>
                <Form.Label className={FORM_LABEL}>URL composto</Form.Label>
                <Form.Control
                  size="sm"
                  className="mw-100 font-monospace"
                  style={textControlStyle}
                  value={composedUrl}
                  readOnly
                  title={composedUrl}
                />
              </Form.Group>

              {fetchError ? (
                <Alert variant="warning" className="py-1 px-2 mb-2 small">
                  {fetchError}
                </Alert>
              ) : null}
            </>
          )}

          {showBgColor ? (
            <Form.Group className={showCaption ? FORM_GROUP_GAP : 'mb-0 w-auto'} controlId={`${prefix}-bg`}>
              <Form.Label className={FORM_LABEL}>bgColor (opz.)</Form.Label>
              <Form.Control
                size="sm"
                className={FORM_COLOR_CONTROL}
                style={{ maxWidth: 'min(100%, 14rem)', minWidth: '8ch' }}
                value={value.bgColor ?? ''}
                placeholder="#RRGGBB"
                onChange={(e) =>
                  onChange({
                    ...value,
                    bgColor: e.target.value === '' ? null : e.target.value,
                  })
                }
              />
            </Form.Group>
          ) : null}
          {showCaption ? (
            <StoryImageCaptionField
              value={value.Caption}
              onChange={(Caption) => onChange({ ...value, Caption })}
            />
          ) : null}
        </div>

        <div className="story-image-preview-col">
          {manualMode ? (
            <StoryImagePreview
              url={value.URL}
              caption={value.Caption}
              bgColor={value.bgColor}
            />
          ) : (
            <>
              <IIFCanvasViewport
                baseUri={resolvedBaseUri ?? baseUri}
                bounds={canvasBounds}
                bgColor={value.bgColor}
                annotations={cropAnnotations}
                activeAnnotationIndex={0}
                onActiveAnnotationIndexChange={() => {}}
                onAnnotationRectChange={(_index, rect) => setCropRect(rect)}
              />
              {composedUrl.trim() ? (
                <div className="mt-2">
                  <div className="text-muted small mb-1">Anteprima URL finale</div>
                  <StoryImagePreview
                    url={previewUrl}
                    caption={value.Caption}
                    bgColor={value.bgColor}
                  />
                </div>
              ) : null}
            </>
          )}
        </div>
      </div>
    </>
  )

  if (!showLegend) {
    return <div className="small">{fields}</div>
  }

  return (
    <fieldset className="border rounded p-2 mb-0 small">
      <legend className="float-none w-auto px-2 mb-0 small text-muted d-inline-flex align-items-center gap-1">
        <span>{prefix}: immagine</span>
        {legendAction}
      </legend>
      {fields}
    </fieldset>
  )
}
