import type { ReactNode } from 'react'
import { Form } from 'react-bootstrap'
import type { TStoryImageType } from '../../types/story'
import { FORM_COLOR_CONTROL, FORM_GROUP_GAP, FORM_LABEL } from '../formStyles'
import { StoryImageCaptionField } from './StoryImageCaptionField'
import { StoryImagePreview } from './StoryImagePreview'

const imageTextControlStyle = { width: '100%' } as const

type Props = {
  prefix: string
  value: TStoryImageType
  onChange: (next: TStoryImageType) => void
  showCaption?: boolean
  showBgColor?: boolean
  legendAction?: ReactNode
}

export function StoryImageFields({
  prefix,
  value,
  onChange,
  showCaption = true,
  showBgColor = true,
  legendAction,
}: Props) {
  return (
    <fieldset className="border rounded p-2 mb-0 small">
      <legend className="float-none w-auto px-2 mb-0 small text-muted d-inline-flex align-items-center gap-1">
        <span>{prefix}: immagine</span>
        {legendAction}
      </legend>
      <div className="d-flex gap-2 align-items-start story-image-fields-row">
        <div className="story-image-fields__inputs d-flex flex-column">
          <Form.Group className={FORM_GROUP_GAP} controlId={`${prefix}-url`}>
            <Form.Label className={FORM_LABEL}>URL</Form.Label>
            <Form.Control
              size="sm"
              className="mw-100"
              style={imageTextControlStyle}
              value={value.URL}
              title={value.URL}
              onChange={(e) => onChange({ ...value, URL: e.target.value })}
            />
          </Form.Group>
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
          <StoryImagePreview
            url={value.URL}
            caption={value.Caption}
            bgColor={value.bgColor}
          />
        </div>
      </div>
    </fieldset>
  )
}
