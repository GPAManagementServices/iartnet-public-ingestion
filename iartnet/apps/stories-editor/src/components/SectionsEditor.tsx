import { useMemo } from 'react'
import { Accordion, Form } from 'react-bootstrap'
import type { SectionKind } from '../types/story'
import { SECTION_KIND_LABELS, changeSectionKind } from '../story/sectionKind'
import type { SectionRow } from '../story/sectionRow'
import { newSectionRow } from '../story/sectionRow'
import { useAccordionActiveKeys } from '../hooks/useAccordionActiveKeys'
import { IconButton } from './IconButton'
import { ListEditorToolbar } from './ListEditorToolbar'
import { SectionBaseFields } from './SectionBaseFields'
import { SectionBody } from './SectionBody'
import { FORM_SELECT } from './formStyles'

const ALL_KINDS = Object.keys(SECTION_KIND_LABELS) as SectionKind[]

type Props = {
  rows: SectionRow[]
  onRowsChange: (rows: SectionRow[]) => void
}

export function SectionsEditor({ rows, onRowsChange }: Props) {
  const rowIds = useMemo(() => rows.map((r) => r.id), [rows])
  const accordion = useAccordionActiveKeys(rowIds)

  const bump = (next: SectionRow[]) => {
    onRowsChange(next)
  }

  const setRow = (id: string, patch: Partial<SectionRow>) => {
    bump(rows.map((r) => (r.id === id ? { ...r, ...patch } : r)))
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
    const next = rows.filter((_, i) => i !== index)
    bump(next)
  }

  const addSection = () => {
    const newRow = newSectionRow('TextIntro')
    accordion.prevKeysRef.current ??= new Set(rows.map((r) => r.id))
    accordion.prevKeysRef.current.add(newRow.id)
    accordion.openKey(newRow.id)
    bump([...rows, newRow])
  }

  return (
    <div className="d-flex flex-column gap-3">
      <ListEditorToolbar
        addLabel="Aggiungi sezione"
        onAdd={addSection}
        allOpen={accordion.allOpen}
        onToggleAll={accordion.toggleAll}
        itemCount={rows.length}
        className="d-flex flex-wrap gap-2 align-items-center mb-0 w-100"
        middle="Ordina con Su/Giù. Il tipo imposta il template campi e il JSON risultante."
      />

      {rows.length > 0 ? (
        <Accordion
          alwaysOpen
          activeKey={accordion.activeKeys}
          onSelect={accordion.onSelect}
        >
          {rows.map((row, index) => (
            <Accordion.Item eventKey={row.id} key={row.id}>
              <Accordion.Header>
                <span className="fw-semibold me-2">Sezione #{index + 1}</span>
                <span className="text-muted small fw-normal">
                  {SECTION_KIND_LABELS[row.section.Kind]}
                  {!row.section.published ? (
                    <span className="ms-2 badge text-bg-secondary">non pubblicata</span>
                  ) : null}
                </span>
              </Accordion.Header>
              <Accordion.Body className="pt-2 pb-2">
                <div className="d-flex flex-wrap gap-2 align-items-center mb-2 pb-2 border-bottom">
                  <Form.Select
                    className={FORM_SELECT}
                    size="sm"
                    value={row.section.Kind}
                    aria-label="Tipo sezione"
                    onChange={(e) => {
                      const nextKind = e.target.value as SectionKind
                      if (nextKind === row.section.Kind) return
                      setRow(row.id, {
                        section: changeSectionKind(row.section, nextKind),
                      })
                    }}
                  >
                    {ALL_KINDS.map((k) => (
                      <option key={k} value={k}>
                        {SECTION_KIND_LABELS[k]}
                      </option>
                    ))}
                  </Form.Select>
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
                <SectionBaseFields
                  instanceId={row.id}
                  section={row.section}
                  onChange={(section) => setRow(row.id, { section })}
                />
                <SectionBody
                  section={row.section}
                  onChange={(section) => setRow(row.id, { section })}
                />
              </Accordion.Body>
            </Accordion.Item>
          ))}
        </Accordion>
      ) : null}

      {rows.length > 0 ? (
        <div>
          <IconButton
            type="button"
            variant="outline-secondary"
            size="sm"
            icon="plus-lg"
            onClick={addSection}
          >
            Aggiungi sezione
          </IconButton>
        </div>
      ) : null}

      {rows.length === 0 ? (
        <p className="text-muted mb-0">
          Nessuna sezione: aggiungine una o applica un JSON nell&apos;area inferiore.
        </p>
      ) : null}
    </div>
  )
}
