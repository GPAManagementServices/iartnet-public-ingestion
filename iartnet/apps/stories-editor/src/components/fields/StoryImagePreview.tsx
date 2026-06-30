import { useEffect, useState } from 'react'

type Props = {
  url: string
  caption?: string | null
  bgColor?: string | null
}

export function StoryImagePreview({ url, caption, bgColor }: Props) {
  const [failed, setFailed] = useState(false)
  const trimmed = url.trim()

  useEffect(() => {
    setFailed(false)
  }, [trimmed])

  const background = bgColor?.trim() ? bgColor.trim() : undefined

  return (
    <div
      className="story-image-preview"
      style={background ? { backgroundColor: background } : undefined}
      aria-label="Anteprima immagine"
    >
      {!trimmed ? (
        <span className="story-image-preview__placeholder text-muted">Nessuna immagine</span>
      ) : failed ? (
        <span className="story-image-preview__placeholder text-muted">Anteprima non disponibile</span>
      ) : (
        <img
          src={trimmed}
          alt={caption?.trim() ? caption.trim() : ''}
          className="story-image-preview__img"
          onError={() => setFailed(true)}
        />
      )}
    </div>
  )
}
