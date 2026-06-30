import { Accordion, Form } from 'react-bootstrap'
import type { TStoryImageType, TStorySection } from '../types/story'
import { isSectionBgImageEmpty } from '../story/sectionBase'
import { useSingleAccordionActiveKey } from '../hooks/useAccordionActiveKeys'
import { FORM_COLOR_CONTROL, FORM_LABEL } from './formStyles'
import { StoryImageFields } from './fields/StoryImageFields'

type Props = {
  instanceId: string
  section: TStorySection
  onChange: (next: TStorySection) => void
}

function sectionAppearanceSubtitle(section: TStorySection): string {
  const parts: string[] = []
  const foreColor = section.foreColor?.trim()
  if (foreColor) parts.push(foreColor)
  const bgColor = section.bgColor?.trim()
  if (bgColor) parts.push(bgColor)
  const url = section.bgImage?.URL?.trim()
  if (url) {
    parts.push(url.length <= 48 ? url : `${url.slice(0, 45)}…`)
  }
  return parts.length > 0 ? parts.join(' · ') : '(predefinito)'
}

export function SectionBaseFields({ instanceId, section, onChange }: Props) {
  const backgroundEventKey = `section-bg-${instanceId}`
  const backgroundAccordion = useSingleAccordionActiveKey(backgroundEventKey, false)
  const bgImage =
    section.bgImage ??
    ({
      URL: '',
      Caption: null,
      bgColor: null,
    } satisfies TStoryImageType)

  return (
    <div className="border rounded p-2 mb-3 bg-light-subtle">
      <div className="small fw-semibold text-muted mb-2">Impostazioni sezione</div>
      <Form.Group
        className="mb-0 d-flex align-items-end"
        controlId={`section-published-${instanceId}`}
      >
        <Form.Check
          type="switch"
          className="small mb-1"
          id={`section-published-check-${instanceId}`}
          label="Pubblicata"
          checked={section.published}
          onChange={(e) => onChange({ ...section, published: e.target.checked })}
        />
      </Form.Group>
      <Accordion
        className="mt-2 mb-0 story-accordion--nested"
        activeKey={backgroundAccordion.activeKey}
        onSelect={backgroundAccordion.onSelect}
      >
        <Accordion.Item eventKey={backgroundEventKey}>
          <Accordion.Header className="py-1">
            <span className={`${FORM_LABEL} me-2`}>Aspetto sezione</span>
            <span className={`${FORM_LABEL} fw-normal`}>
              {sectionAppearanceSubtitle(section)}
            </span>
          </Accordion.Header>
          <Accordion.Body className="pt-2 pb-2">
            <Form.Group className="mb-2 w-auto" controlId={`section-fore-color-${instanceId}`}>
              <Form.Label className={FORM_LABEL}>foreColor sezione (opz.)</Form.Label>
              <Form.Control
                size="sm"
                className={FORM_COLOR_CONTROL}
                style={{ maxWidth: 'min(100%, 14rem)', minWidth: '8ch' }}
                value={section.foreColor ?? ''}
                placeholder="#RRGGBB"
                onChange={(e) =>
                  onChange({
                    ...section,
                    foreColor: e.target.value === '' ? null : e.target.value,
                  })
                }
              />
            </Form.Group>
            <Form.Group className="mb-2 w-auto" controlId={`section-bg-color-${instanceId}`}>
              <Form.Label className={FORM_LABEL}>bgColor sezione (opz.)</Form.Label>
              <Form.Control
                size="sm"
                className={FORM_COLOR_CONTROL}
                style={{ maxWidth: 'min(100%, 14rem)', minWidth: '8ch' }}
                value={section.bgColor ?? ''}
                placeholder="#RRGGBB"
                onChange={(e) =>
                  onChange({
                    ...section,
                    bgColor: e.target.value === '' ? null : e.target.value,
                  })
                }
              />
            </Form.Group>
            <StoryImageFields
              prefix="bgImage"
              value={bgImage}
              onChange={(next) =>
                onChange({
                  ...section,
                  bgImage: isSectionBgImageEmpty(next) ? null : next,
                })
              }
            />
          </Accordion.Body>
        </Accordion.Item>
      </Accordion>
    </div>
  )
}
