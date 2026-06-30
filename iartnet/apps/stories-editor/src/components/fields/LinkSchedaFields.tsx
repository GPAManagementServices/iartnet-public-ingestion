import { Form } from 'react-bootstrap'
import { useId } from 'react'
import { createEmptyLinkScheda, type TStoryLinkSchedaType } from '../../types/story'
import { FORM_ADJACENT_ROW, FORM_LABEL, FORM_SELECT } from '../formStyles'

const LINK_SCHEDA_LAYOUTS: TStoryLinkSchedaType['Layout'][] = ['TopLeft', 'TopRight']

type Props = {
  value: TStoryLinkSchedaType | undefined
  onChange: (next: TStoryLinkSchedaType) => void
  showLayout?: boolean
}

export function LinkSchedaFields({ value, onChange, showLayout = true }: Props) {
  const merged = value ?? createEmptyLinkScheda()
  const baseId = useId()
  return (
    <fieldset className="border rounded p-2 mb-2 small">
      <legend className="float-none w-auto px-2 mb-0 small text-muted">LinkScheda</legend>
      <div className={showLayout ? FORM_ADJACENT_ROW : undefined}>
        {showLayout ? (
          <Form.Group className="mb-0 flex-shrink-0" controlId={`${baseId}-layout`}>
            <Form.Label className={FORM_LABEL}>Layout</Form.Label>
            <Form.Select
              className={FORM_SELECT}
              size="sm"
              value={merged.Layout}
              onChange={(e) =>
                onChange({
                  ...merged,
                  Layout: e.target.value as TStoryLinkSchedaType['Layout'],
                })
              }
            >
              {LINK_SCHEDA_LAYOUTS.map((l) => (
                <option key={l} value={l}>
                  {l}
                </option>
              ))}
            </Form.Select>
          </Form.Group>
        ) : null}
        <Form.Group className={`mb-0 ${showLayout ? 'flex-grow-1 min-w-0' : ''}`} controlId={`${baseId}-url`}>
          <Form.Label className={FORM_LABEL}>URL</Form.Label>
          <Form.Control
            size="sm"
            value={merged.URL}
            onChange={(e) => onChange({ ...merged, URL: e.target.value })}
          />
        </Form.Group>
      </div>
    </fieldset>
  )
}
