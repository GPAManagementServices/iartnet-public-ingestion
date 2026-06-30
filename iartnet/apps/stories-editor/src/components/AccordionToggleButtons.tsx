import { IconButton } from './IconButton'

type Props = {
  allOpen: boolean
  disabled?: boolean
  onToggle: () => void
}

export function AccordionToggleButtons({ allOpen, disabled, onToggle }: Props) {
  return (
    <IconButton
      type="button"
      variant="outline-secondary"
      size="sm"
      icon={allOpen ? 'arrows-collapse' : 'arrows-expand'}
      disabled={disabled}
      onClick={onToggle}
    >
      {allOpen ? 'Chiudi tutti' : 'Apri tutti'}
    </IconButton>
  )
}
