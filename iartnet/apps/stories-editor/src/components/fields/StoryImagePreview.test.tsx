import { cleanup, fireEvent, render, screen } from '@testing-library/react'
import { afterEach, describe, expect, it } from 'vitest'
import { StoryImagePreview } from './StoryImagePreview'

afterEach(() => cleanup())

describe('StoryImagePreview', () => {
  it('mostra placeholder senza URL', () => {
    render(<StoryImagePreview url="" />)
    expect(screen.getByText(/Nessuna immagine/i)).toBeInTheDocument()
    expect(screen.queryByRole('img')).not.toBeInTheDocument()
  })

  it('mostra img con src quando URL è valorizzato', () => {
    render(<StoryImagePreview url="https://img.test/a.png" caption="Didascalia" />)
    const img = screen.getByRole('img')
    expect(img).toHaveAttribute('src', 'https://img.test/a.png')
    expect(img).toHaveAttribute('alt', 'Didascalia')
  })

  it('applica bgColor sul contenitore', () => {
    const { container } = render(
      <StoryImagePreview url="" bgColor="#aabbcc" />,
    )
    const box = container.querySelector('.story-image-preview')
    expect(box).toHaveStyle({ backgroundColor: '#aabbcc' })
  })

  it('mostra fallback su errore caricamento', () => {
    const { container } = render(<StoryImagePreview url="https://img.test/broken.png" />)
    const img = container.querySelector('.story-image-preview__img')
    expect(img).toBeTruthy()
    fireEvent.error(img!)
    expect(screen.getByText(/Anteprima non disponibile/i)).toBeInTheDocument()
    expect(container.querySelector('.story-image-preview__img')).toBeNull()
  })
})
