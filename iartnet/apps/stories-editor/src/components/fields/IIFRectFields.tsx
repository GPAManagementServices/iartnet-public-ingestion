import Form from 'react-bootstrap/Form'
import type { TStoryIIFAnnotationType } from '../../types/story'
import type { IIFImageBounds } from '../../story/iiifImage'
import {
  clampIIFRect,
  parseIIFRectCoordinate,
  validateIIFRect,
  type IIFRectField,
} from '../../story/iifRect'
import { IconButton } from '../IconButton'
import { FORM_ADJACENT_ROW, FORM_GROUP_GAP, FORM_LABEL } from '../formStyles'

type Props = {
  rowId: string
  rect: TStoryIIFAnnotationType['Rect']
  bounds: IIFImageBounds | null
  onChange: (rect: TStoryIIFAnnotationType['Rect']) => void
}

const RECT_FIELDS: IIFRectField[] = ['x', 'y', 'width', 'height']

export function IIFRectFields({ rowId, rect, bounds, onChange }: Props) {
  const validation = validateIIFRect(rect, bounds)
  const canClamp = Boolean(bounds && !validation.valid && !validation.isDraft)

  const setField = (field: IIFRectField, raw: string) => {
    onChange({
      ...rect,
      [field]: parseIIFRectCoordinate(raw),
    })
  }

  const clampToCanvas = () => {
    if (!bounds) return
    onChange(clampIIFRect(rect, bounds))
  }

  return (
    <Form.Group className={FORM_GROUP_GAP} controlId={`iif-rect-${rowId}`}>
      <Form.Label className={FORM_LABEL}>Rect</Form.Label>
      {validation.summary ? (
        <div
          className={`small mb-1 ${validation.valid || validation.isDraft ? 'text-muted' : 'text-danger'}`}
        >
          {validation.summary}
        </div>
      ) : null}
      <div className={`${FORM_ADJACENT_ROW} flex-wrap`}>
        {RECT_FIELDS.map((field) => {
          const error = validation.fieldErrors[field]
          return (
            <Form.Group
              key={field}
              className="mb-0 flex-shrink-0"
              controlId={`iif-rect-${rowId}-${field}`}
            >
              <Form.Label className={`${FORM_LABEL} text-capitalize`}>{field}</Form.Label>
              <Form.Control
                size="sm"
                type="number"
                step={1}
                isInvalid={Boolean(error)}
                value={rect[field]}
                onChange={(e) => setField(field, e.target.value)}
              />
              {error ? (
                <Form.Control.Feedback type="invalid" className="d-block small">
                  {error}
                </Form.Control.Feedback>
              ) : null}
            </Form.Group>
          )
        })}
      </div>
      {!bounds ? (
        <Form.Text className="text-warning">
          Validazione sul canvas disabilitata: attendi il caricamento di Width/Height da info.json
          (sotto BaseURI).
        </Form.Text>
      ) : null}
      {canClamp ? (
        <div className="mt-1">
          <IconButton
            type="button"
            variant="outline-danger"
            size="sm"
            icon="arrow-counterclockwise"
            onClick={clampToCanvas}
          >
            Correggi al canvas
          </IconButton>
        </div>
      ) : null}
    </Form.Group>
  )
}

export function rectHeaderHint(
  rect: TStoryIIFAnnotationType['Rect'],
  bounds: IIFImageBounds | null,
): string | null {
  const v = validateIIFRect(rect, bounds)
  if (v.valid) return null
  if (v.isDraft && v.valid) return null
  return ' · non valido'
}
