import { Accordion, Form } from 'react-bootstrap'
import type { TStoriesTypeData } from '../types/story'
import { IconButton } from './IconButton'
import { FORM_LABEL } from './formStyles'

type Props = {
  story: TStoriesTypeData
  onChange: (next: TStoriesTypeData) => void
}

function metadataSubtitle(s: TStoriesTypeData): string {
  const n = s.name.trim()
  if (n) return n
  const id = s.id.trim()
  if (id) return `id: ${id}`
  return '(senza nome)'
}

export function MetadataCard({ story, onChange }: Props) {
  const state = story.publish_state.trim() || '—'

  return (
    <Accordion className="mb-3">
      <Accordion.Item eventKey="metadata">
        <Accordion.Header>
          <span className="fw-semibold me-2">Metadati record</span>
          <span className="text-muted small fw-normal">
            {metadataSubtitle(story)} · {state}
          </span>
        </Accordion.Header>
        <Accordion.Body className="pt-2 pb-2">
          <div className="row row-cols-1 row-cols-md-5 g-2 small">
            <div className="col">
              <Form.Group className="mb-0" controlId="metadata-id">
                <Form.Label className={FORM_LABEL}>id</Form.Label>
                <Form.Control
                  size="sm"
                  value={story.id}
                  onChange={(e) => onChange({ ...story, id: e.target.value })}
                />
              </Form.Group>
            </div>
            <div className="col">
              <Form.Group className="mb-0" controlId="metadata-name">
                <Form.Label className={FORM_LABEL}>name</Form.Label>
                <Form.Control
                  size="sm"
                  value={story.name}
                  onChange={(e) => onChange({ ...story, name: e.target.value })}
                />
              </Form.Group>
            </div>
            <div className="col">
              <Form.Group className="mb-0" controlId="metadata-publish-state">
                <Form.Label className={FORM_LABEL}>publish_state</Form.Label>
                <Form.Control
                  size="sm"
                  value={story.publish_state}
                  onChange={(e) =>
                    onChange({
                      ...story,
                      publish_state: e.target.value,
                    })
                  }
                />
              </Form.Group>
            </div>
            <div className="col">
              <Form.Group className="mb-0" controlId="metadata-created-at">
                <Form.Label className={FORM_LABEL}>created_at</Form.Label>
                <Form.Control
                  size="sm"
                  value={story.created_at}
                  onChange={(e) =>
                    onChange({ ...story, created_at: e.target.value })
                  }
                />
              </Form.Group>
            </div>
            <div className="col">
              <Form.Group className="mb-0" controlId="metadata-updated-at">
                <Form.Label className={FORM_LABEL}>updated_at</Form.Label>
                <Form.Control
                  size="sm"
                  value={story.updated_at}
                  onChange={(e) =>
                    onChange({ ...story, updated_at: e.target.value })
                  }
                />
              </Form.Group>
            </div>
          </div>
          <div className="row g-2 small mt-1">
            <div className="col-12">
              <Form.Group className="mb-0" controlId="metadata-description">
                <Form.Label className={FORM_LABEL}>description</Form.Label>
                <Form.Control
                  size="sm"
                  as="textarea"
                  rows={2}
                  value={story.description}
                  onChange={(e) =>
                    onChange({
                      ...story,
                      description: e.target.value,
                    })
                  }
                />
              </Form.Group>
            </div>
            <div className="col-12 d-flex justify-content-end pt-1">
              <IconButton
                type="button"
                variant="outline-secondary"
                size="sm"
                icon="clock-history"
                onClick={() => {
                  const now = new Date().toISOString()
                  onChange({ ...story, updated_at: now })
                }}
              >
                Aggiorna updated_at
              </IconButton>
            </div>
          </div>
        </Accordion.Body>
      </Accordion.Item>
    </Accordion>
  )
}
