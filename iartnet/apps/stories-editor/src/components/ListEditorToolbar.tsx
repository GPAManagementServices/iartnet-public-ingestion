import type { ReactNode } from 'react'
import { AccordionToggleButtons } from './AccordionToggleButtons'
import { IconButton } from './IconButton'

type Props = {
  addLabel: string
  onAdd: () => void
  allOpen: boolean
  onToggleAll: () => void
  itemCount: number
  className?: string
  middle?: ReactNode
}

/** Barra azioni lista: aggiungi a sinistra, apri/chiudi tutti a destra. */
export function ListEditorToolbar({
  addLabel,
  onAdd,
  allOpen,
  onToggleAll,
  itemCount,
  className = 'd-flex flex-wrap gap-2 align-items-center mb-2 w-100',
  middle,
}: Props) {
  return (
    <div className={className}>
      <IconButton type="button" variant="outline-secondary" size="sm" icon="plus-lg" onClick={onAdd}>
        {addLabel}
      </IconButton>
      {middle ? <div className="text-muted small flex-grow-1">{middle}</div> : null}
      <div className="ms-auto">
        <AccordionToggleButtons
          allOpen={allOpen}
          disabled={itemCount === 0}
          onToggle={onToggleAll}
        />
      </div>
    </div>
  )
}
