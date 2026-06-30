import { useMemo } from 'react'
import { Accordion, Button, Card, Form } from 'react-bootstrap'
import type { TStoriesExtJson, TStoryCatalogoOpereCitateType, TStorySection } from '../types/story'
import { catalogoItemsFromSections } from '../story/catalogoPreload'
import { createEmptyCatalogoItem } from '../story/sectionKind'
import { useAccordionActiveKeys } from '../hooks/useAccordionActiveKeys'
import { IconButton } from './IconButton'
import { ListEditorToolbar } from './ListEditorToolbar'
import { LinkSchedaFields } from './fields/LinkSchedaFields'
import { StoryImageFields } from './fields/StoryImageFields'
import { FORM_GROUP_GAP, FORM_LABEL } from './formStyles'

type TitleDescRow = { Title: string; Description: string }

const EMPTY_TITLE_DESC_ROWS: TitleDescRow[] = []
const EMPTY_CATALOGO: TStoryCatalogoOpereCitateType[] = []

type Props = {
  ext: TStoriesExtJson
  sections: TStorySection[]
  onMerge: (partial: Partial<TStoriesExtJson>) => void
}

function previewTitleDesc(row: { Title: string; Description: string }) {
  const t = row.Title.trim()
  if (t) return t
  const d = row.Description.trim()
  if (d) return d.length > 48 ? `${d.slice(0, 48)}…` : d
  return '(senza titolo)'
}

function TitleDescListCard<K extends 'bibliography' | 'sitography' | 'credits'>({
  listKey,
  title,
  rows,
  onMerge,
}: {
  listKey: K
  title: string
  rows: TitleDescRow[]
  onMerge: (partial: Partial<TStoriesExtJson>) => void
}) {
  const eventKeys = useMemo(
    () => rows.map((_, i) => `${listKey}-${i}`),
    [rows, listKey],
  )
  const accordion = useAccordionActiveKeys(eventKeys)

  return (
    <Card className="mb-3">
      <Card.Header className="small py-2">{title}</Card.Header>
      <Card.Body className="py-2">
        <ListEditorToolbar
          addLabel="Aggiungi"
          onAdd={() =>
            onMerge({
              [listKey]: [...rows, { Title: '', Description: '' }],
            } as Partial<TStoriesExtJson>)
          }
          allOpen={accordion.allOpen}
          onToggleAll={accordion.toggleAll}
          itemCount={rows.length}
        />
        {rows.length > 0 ? (
          <Accordion
            alwaysOpen
            activeKey={accordion.activeKeys}
            onSelect={accordion.onSelect}
          >
            {rows.map((row, i) => (
              <Accordion.Item eventKey={`${listKey}-${i}`} key={`${listKey}-${i}`}>
                <Accordion.Header>
                  <span className="fw-semibold me-2">Voce #{i + 1}</span>
                  <span className="text-muted small fw-normal">
                    {previewTitleDesc(row)}
                  </span>
                </Accordion.Header>
                <Accordion.Body className="pt-2 pb-2">
                  <div className="d-flex justify-content-end mb-2 pb-2 border-bottom">
                    <IconButton
                      type="button"
                      size="sm"
                      variant="outline-danger"
                      icon="trash"
                      onClick={() =>
                        onMerge({
                          [listKey]: rows.filter((_, j) => j !== i),
                        } as Partial<TStoriesExtJson>)
                      }
                    >
                      Rimuovi
                    </IconButton>
                  </div>
                  <div className="row g-2">
                    <div className="col-md-4 col-lg-3">
                      <Form.Group className="mb-0" controlId={`${listKey}-title-${i}`}>
                        <Form.Label className={FORM_LABEL}>Title</Form.Label>
                        <Form.Control
                          size="sm"
                          value={row.Title}
                          onChange={(e) => {
                            const next = [...rows]
                            next[i] = { ...row, Title: e.target.value }
                            onMerge({ [listKey]: next } as Partial<TStoriesExtJson>)
                          }}
                        />
                      </Form.Group>
                    </div>
                    <div className="col-md-8 col-lg-9">
                      <Form.Group className="mb-0" controlId={`${listKey}-description-${i}`}>
                        <Form.Label className={FORM_LABEL}>Description</Form.Label>
                        <Form.Control
                          size="sm"
                          as="textarea"
                          rows={2}
                          value={row.Description}
                          onChange={(e) => {
                            const next = [...rows]
                            next[i] = { ...row, Description: e.target.value }
                            onMerge({ [listKey]: next } as Partial<TStoriesExtJson>)
                          }}
                        />
                      </Form.Group>
                    </div>
                  </div>
                </Accordion.Body>
              </Accordion.Item>
            ))}
          </Accordion>
        ) : (
          <p className="text-muted mb-0">Nessun elemento.</p>
        )}
      </Card.Body>
    </Card>
  )
}

export function SupplementaryListsEditor({ ext, sections, onMerge }: Props) {
  const bibliography = ext.bibliography ?? EMPTY_TITLE_DESC_ROWS
  const sitography = ext.sitography ?? EMPTY_TITLE_DESC_ROWS
  const credits = ext.credits ?? EMPTY_TITLE_DESC_ROWS
  const catalogo = ext.catalogoOpereCitate ?? EMPTY_CATALOGO

  const catalogKeys = useMemo(
    () => catalogo.map((_, i) => `catalogo-${i}`),
    [catalogo],
  )
  const catalogAccordion = useAccordionActiveKeys(catalogKeys)

  function previewCatalogo(item: (typeof catalogo)[number]) {
    const t = item.Title.trim()
    if (t) return t
    const a = item.Author.trim()
    if (a) return a
    const u = item.Image.URL.trim()
    if (u) return u.length > 40 ? `${u.slice(0, 40)}…` : u
    return '(senza titolo)'
  }

  return (
    <div>
      <h5 className="h6 text-muted mt-2 mb-3">Liste opzionali</h5>
      <TitleDescListCard
        listKey="credits"
        title="Crediti"
        rows={credits}
        onMerge={onMerge}
      />

      <Card className="mb-4">
        <Card.Header className="small py-2 d-flex align-items-center justify-content-between gap-2">
          <span>Catalogo opere citate</span>
          <Button
            type="button"
            variant="outline-secondary"
            size="sm"
            onClick={() =>
              onMerge({
                catalogoOpereCitate: [...catalogo, ...catalogoItemsFromSections(sections)],
              })
            }
          >
            preload
          </Button>
        </Card.Header>
        <Card.Body className="py-2">
          <ListEditorToolbar
            addLabel="Aggiungi"
            onAdd={() =>
              onMerge({
                catalogoOpereCitate: [...catalogo, createEmptyCatalogoItem()],
              })
            }
            allOpen={catalogAccordion.allOpen}
            onToggleAll={catalogAccordion.toggleAll}
            itemCount={catalogo.length}
          />
          {catalogo.length > 0 ? (
            <Accordion
              alwaysOpen
              activeKey={catalogAccordion.activeKeys}
              onSelect={catalogAccordion.onSelect}
            >
              {catalogo.map((item, i) => (
                <Accordion.Item eventKey={`catalogo-${i}`} key={`catalogo-${i}`}>
                  <Accordion.Header>
                    <span className="fw-semibold me-2">Opera #{i + 1}</span>
                    <span className="text-muted small fw-normal">
                      {previewCatalogo(item)}
                    </span>
                  </Accordion.Header>
                  <Accordion.Body className="pt-2 pb-2">
                    <div className="d-flex justify-content-end mb-2 pb-2 border-bottom">
                      <IconButton
                        type="button"
                        size="sm"
                        variant="outline-danger"
                        icon="trash"
                        onClick={() =>
                          onMerge({
                            catalogoOpereCitate: catalogo.filter((_, j) => j !== i),
                          })
                        }
                      >
                        Rimuovi
                      </IconButton>
                    </div>
                    <StoryImageFields
                      prefix={`Catalogo #${i + 1}`}
                      value={item.Image}
                      showCaption={false}
                      showBgColor={false}
                      onChange={(Image) => {
                        const next = [...catalogo]
                        next[i] = { ...item, Image }
                        onMerge({ catalogoOpereCitate: next })
                      }}
                    />
                    <Form.Group className={`${FORM_GROUP_GAP} mt-1`} controlId={`catalogo-title-${i}`}>
                      <Form.Label className={FORM_LABEL}>Title</Form.Label>
                      <Form.Control
                        size="sm"
                        value={item.Title}
                        onChange={(e) => {
                          const next = [...catalogo]
                          next[i] = { ...item, Title: e.target.value }
                          onMerge({ catalogoOpereCitate: next })
                        }}
                      />
                    </Form.Group>
                    <Form.Group className={FORM_GROUP_GAP} controlId={`catalogo-author-${i}`}>
                      <Form.Label className={FORM_LABEL}>Author</Form.Label>
                      <Form.Control
                        size="sm"
                        value={item.Author}
                        onChange={(e) => {
                          const next = [...catalogo]
                          next[i] = { ...item, Author: e.target.value }
                          onMerge({ catalogoOpereCitate: next })
                        }}
                      />
                    </Form.Group>
                    <Form.Group className={FORM_GROUP_GAP}>
                      <Form.Label className={FORM_LABEL}>Tags (virgola)</Form.Label>
                      <Form.Control
                        size="sm"
                        value={item.Tags.join(', ')}
                        onChange={(e) => {
                          const tags = e.target.value
                            .split(',')
                            .map((t) => t.trim())
                            .filter(Boolean)
                          const next = [...catalogo]
                          next[i] = { ...item, Tags: tags }
                          onMerge({ catalogoOpereCitate: next })
                        }}
                      />
                    </Form.Group>
                    <Form.Group className="mb-0">
                      <LinkSchedaFields
                        value={item.LinkScheda}
                        showLayout={false}
                        onChange={(LinkScheda) => {
                          const next = [...catalogo]
                          next[i] = { ...item, LinkScheda }
                          onMerge({ catalogoOpereCitate: next })
                        }}
                      />
                    </Form.Group>
                  </Accordion.Body>
                </Accordion.Item>
              ))}
            </Accordion>
          ) : (
            <p className="text-muted mb-0">Nessuna opera.</p>
          )}
        </Card.Body>
      </Card>
      <TitleDescListCard
        listKey="bibliography"
        title="Bibliografia"
        rows={bibliography}
        onMerge={onMerge}
      />
      <TitleDescListCard
        listKey="sitography"
        title="Sitografia"
        rows={sitography}
        onMerge={onMerge}
      />
    </div>
  )
}
