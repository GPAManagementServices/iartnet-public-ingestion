import { useCallback, useEffect, useMemo, useRef, useState } from 'react'
import { Accordion } from 'react-bootstrap'
import {
  type TStoryIIFAnnotationType,
  type TStoryIIFAnnotationsGroupType,
} from '../types/story'
import {
  annotationsFromRows,
  newIIFAnnotationRow,
  rowsFromAnnotations,
  type IIFAnnotationRow,
} from '../story/iifAnnotationRow'
import { useAccordionActiveKeys } from '../hooks/useAccordionActiveKeys'
import { IconButton } from './IconButton'
import { ListEditorToolbar } from './ListEditorToolbar'
import { RichTextField } from './fields/RichTextField'
import { IIFImageFields } from './fields/IIFImageFields'
import { IIFRectFields, rectHeaderHint } from './fields/IIFRectFields'
import { iifImageBounds } from '../story/iiifImage'
import { richTextPlainPreview, richTextToPlainText } from '../story/richText'

type Props = {
  value: TStoryIIFAnnotationsGroupType
  onChange: (next: TStoryIIFAnnotationsGroupType) => void
}

function previewAnnotationText(text: TStoryIIFAnnotationType['Text']) {
  return richTextPlainPreview(text)
}

function previewAnnotationTitle(text: TStoryIIFAnnotationType['Text']) {
  const plain = richTextToPlainText(text)
  return plain || undefined
}

function useAnnotationRows(
  annotations: TStoryIIFAnnotationType[],
  onAnnotationsChange: (next: TStoryIIFAnnotationType[]) => void,
) {
  const [rows, setRows] = useState<IIFAnnotationRow[]>(() =>
    rowsFromAnnotations(annotations),
  )
  const lastLocalKeyRef = useRef<string | null>(JSON.stringify(annotations))

  useEffect(() => {
    const incoming = JSON.stringify(annotations)
    if (incoming === lastLocalKeyRef.current) return
    lastLocalKeyRef.current = incoming
    setRows((prev) => rowsFromAnnotations(annotations, prev))
  }, [annotations])

  const bump = useCallback(
    (nextRows: IIFAnnotationRow[]) => {
      setRows(nextRows)
      const nextAnnotations = annotationsFromRows(nextRows)
      lastLocalKeyRef.current = JSON.stringify(nextAnnotations)
      onAnnotationsChange(nextAnnotations)
    },
    [onAnnotationsChange],
  )

  return { rows, bump }
}

export function IIFAnnotationsGroupFields({ value, onChange }: Props) {
  const valueRef = useRef(value)
  valueRef.current = value

  const onAnnotationsChange = useCallback(
    (Annotations: TStoryIIFAnnotationType[]) => {
      onChange({ ...valueRef.current, Annotations })
    },
    [onChange],
  )

  const { rows, bump } = useAnnotationRows(value.Annotations, onAnnotationsChange)
  const rowIds = useMemo(() => rows.map((r) => r.id), [rows])
  const accordion = useAccordionActiveKeys(rowIds)
  const canvasBounds = iifImageBounds(value.Image)
  const [selectedRowId, setSelectedRowId] = useState<string | null>(null)
  const [scrollToActiveTrigger, setScrollToActiveTrigger] = useState(0)
  const [hideInactiveAnnotations, setHideInactiveAnnotations] = useState(false)

  const canvasAnnotations = useMemo(() => annotationsFromRows(rows), [rows])
  const rowIdsKey = useMemo(() => rows.map((r) => r.id).join('\0'), [rows])

  useEffect(() => {
    if (rows.length === 0) {
      setSelectedRowId(null)
      return
    }
    setSelectedRowId((prev) => {
      if (prev && rows.some((r) => r.id === prev)) return prev
      return rows[0]!.id
    })
  }, [rowIdsKey, rows.length])

  const activeAnnotationIndex = useMemo(() => {
    if (!selectedRowId) return null
    const index = rows.findIndex((r) => r.id === selectedRowId)
    return index >= 0 ? index : null
  }, [rows, selectedRowId])

  const onActiveAnnotationIndexChange = useCallback(
    (index: number) => {
      const row = rows[index]
      if (!row) return
      setSelectedRowId(row.id)
      accordion.openKey(row.id)
    },
    [rows, accordion.openKey],
  )

  const onAnnotationRectChange = useCallback(
    (index: number, Rect: TStoryIIFAnnotationType['Rect']) => {
      const row = rows[index]
      if (!row) return
      bump(
        rows.map((r) =>
          r.id === row.id ? { ...r, annotation: { ...r.annotation, Rect } } : r,
        ),
      )
    },
    [rows, bump],
  )

  const setRowAt = (id: string, patch: Partial<TStoryIIFAnnotationType>) => {
    bump(
      rows.map((row) =>
        row.id === id ? { ...row, annotation: { ...row.annotation, ...patch } } : row,
      ),
    )
  }

  const reorder = (from: number, delta: number) => {
    const to = from + delta
    if (to < 0 || to >= rows.length) return
    const next = [...rows]
    const [it] = next.splice(from, 1)
    next.splice(to, 0, it!)
    bump(next)
  }

  const removeAt = (index: number) => {
    const removedId = rows[index]?.id
    bump(rows.filter((_, i) => i !== index))
    if (removedId && removedId === selectedRowId) {
      const next = rows[index + 1] ?? rows[index - 1]
      setSelectedRowId(next?.id ?? null)
    }
  }

  const addAnnotation = () => {
    const newRow = newIIFAnnotationRow()
    accordion.prevKeysRef.current ??= new Set(rowIds)
    accordion.prevKeysRef.current.add(newRow.id)
    accordion.openKey(newRow.id)
    bump([...rows, newRow])
  }

  return (
    <>
      <IIFImageFields
        value={value.Image}
        caption={value.Caption}
        onCaptionChange={(Caption) => onChange({ ...value, Caption })}
        annotations={canvasAnnotations}
        activeAnnotationIndex={activeAnnotationIndex}
        hideInactiveAnnotations={hideInactiveAnnotations}
        onHideInactiveAnnotationsChange={setHideInactiveAnnotations}
        scrollToActiveTrigger={scrollToActiveTrigger}
        onActiveAnnotationIndexChange={onActiveAnnotationIndexChange}
        onAnnotationRectChange={onAnnotationRectChange}
        onChange={(Image) => onChange({ ...value, Image })}
      />

      <div className="mt-3 pt-3 border-top">
        <ListEditorToolbar
          addLabel="Aggiungi annotazione"
          onAdd={addAnnotation}
          allOpen={accordion.allOpen}
          onToggleAll={accordion.toggleAll}
          itemCount={rows.length}
          className="d-flex flex-wrap gap-2 align-items-center mb-2 w-100"
          middle="Ordina con Su/Giù. Ogni annotazione definisce testo e rettangolo sulla immagine."
        />

        {rows.length > 0 ? (
          <Accordion
            className="story-accordion--nested"
            alwaysOpen
            activeKey={accordion.activeKeys}
            onSelect={accordion.onSelect}
          >
            {rows.map((row, index) => {
              const rectHint = rectHeaderHint(row.annotation.Rect, canvasBounds)
              return (
              <Accordion.Item eventKey={row.id} key={row.id}>
                <Accordion.Header
                  onClick={() => {
                    setSelectedRowId(row.id)
                    setScrollToActiveTrigger((t) => t + 1)
                  }}
                >
                  <span className="fw-semibold me-2">
                    Annotazione #{index + 1}
                    {row.id === selectedRowId ? (
                      <span className="badge text-bg-primary ms-1 align-middle">canvas</span>
                    ) : null}
                  </span>
                  <span
                    className="text-muted small fw-normal"
                    title={previewAnnotationTitle(row.annotation.Text)}
                  >
                    {previewAnnotationText(row.annotation.Text)}
                    {rectHint ? <span className="text-danger">{rectHint}</span> : null}
                  </span>
                </Accordion.Header>
                <Accordion.Body className="pt-2 pb-2">
                  <div className="d-flex flex-wrap gap-2 align-items-center mb-2 pb-2 border-bottom">
                    <IconButton
                      type="button"
                      variant="outline-secondary"
                      size="sm"
                      icon="arrow-up"
                      onClick={() => reorder(index, -1)}
                      disabled={index === 0}
                    >
                      Su
                    </IconButton>
                    <IconButton
                      type="button"
                      variant="outline-secondary"
                      size="sm"
                      icon="arrow-down"
                      onClick={() => reorder(index, 1)}
                      disabled={index === rows.length - 1}
                    >
                      Giù
                    </IconButton>
                    <IconButton
                      type="button"
                      variant="outline-danger"
                      size="sm"
                      icon="trash"
                      onClick={() => removeAt(index)}
                    >
                      Elimina
                    </IconButton>
                  </div>

                  <RichTextField
                    label="Text"
                    value={row.annotation.Text}
                    allowImages
                    onChange={(Text) => setRowAt(row.id, { Text })}
                  />

                  <IIFRectFields
                    rowId={row.id}
                    rect={row.annotation.Rect}
                    bounds={canvasBounds}
                    onChange={(Rect) => setRowAt(row.id, { Rect })}
                  />
                </Accordion.Body>
              </Accordion.Item>
            )})}
          </Accordion>
        ) : (
          <p className="text-muted mb-0">Nessuna annotazione.</p>
        )}

        {rows.length > 0 ? (
          <div className="mt-2">
            <IconButton
              type="button"
              variant="outline-secondary"
              size="sm"
              icon="plus-lg"
              onClick={addAnnotation}
            >
              Aggiungi annotazione
            </IconButton>
          </div>
        ) : null}
      </div>
    </>
  )
}
