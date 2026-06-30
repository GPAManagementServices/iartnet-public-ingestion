import { RichTextField } from './RichTextField'

type Props = {
  value: string | null | undefined
  onChange: (caption: string | null) => void
}

export function StoryImageCaptionField({ value, onChange }: Props) {
  return (
    <RichTextField
      label="Caption (opz.)"
      value={value ?? ''}
      onChange={(next) => onChange(next === '' ? null : next)}
    />
  )
}
