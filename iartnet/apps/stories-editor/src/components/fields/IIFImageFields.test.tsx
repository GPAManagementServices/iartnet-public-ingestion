import { cleanup, render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { useState } from 'react'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { createEmptyIIFImage, type TStoryIIFImageType } from '../../types/story'
import { IIFImageFields } from './IIFImageFields'

afterEach(() => cleanup())

function EditorWithState() {
  const [value, setValue] = useState(createEmptyIIFImage())
  return <IIFImageFields value={value} onChange={setValue} />
}

describe('IIFImageFields', () => {
  beforeEach(() => {
    vi.stubGlobal(
      'fetch',
      vi.fn().mockResolvedValue({
        ok: true,
        json: async () => ({ width: 5153, height: 7064 }),
      }),
    )
  })

  it('carica dimensioni da info.json dopo inserimento BaseURI IIIF', async () => {
    const user = userEvent.setup()
    render(<EditorWithState />)

    const input = screen.getByLabelText(/^BaseURI$/i)
    await user.type(
      input,
      'https://iiif.gpams.it/iiif/2/uuid.tif/full/max/0/default.jpg',
    )

    await waitFor(
      () => {
        expect(screen.getByText(/Canvas 5153 × 7064 px/i)).toBeInTheDocument()
      },
      { timeout: 2000 },
    )

    expect(screen.queryByLabelText(/^Width$/i)).not.toBeInTheDocument()
    expect(screen.queryByLabelText(/^Height$/i)).not.toBeInTheDocument()
    const img = document.querySelector('.iif-canvas-viewport__img')
    expect(img).toHaveAttribute(
      'src',
      'https://iiif.gpams.it/iiif/2/uuid.tif/full/800,/0/default.jpg',
    )
  })

  it('mostra viewport non appena BaseURI IIIF è riconosciuto', async () => {
    const user = userEvent.setup()
    render(<EditorWithState />)

    const input = screen.getByLabelText(/^BaseURI$/i)
    await user.click(input)
    await user.paste('https://iiif.gpams.it/iiif/2/uuid.tif/full/max/0/default.jpg')

    await waitFor(() => {
      expect(screen.getByLabelText('Canvas IIIF zoomabile')).toBeInTheDocument()
      const img = document.querySelector('.iif-canvas-viewport__img')
      expect(img).toHaveAttribute(
        'src',
        'https://iiif.gpams.it/iiif/2/uuid.tif/full/800,/0/default.jpg',
      )
    })
  })

  it('aggiorna bgColor e lo applica al canvas', async () => {
    const user = userEvent.setup()

    function EditorWithIiif() {
      const [value, setValue] = useState<TStoryIIFImageType>({
        ...createEmptyIIFImage(),
        BaseURI: 'https://iiif.gpams.it/iiif/2/uuid.tif',
        Width: 800,
        Height: 600,
      })
      return <IIFImageFields value={value} onChange={setValue} />
    }

    render(<EditorWithIiif />)

    await user.type(screen.getByLabelText(/bgColor/i), '#aabbcc')

    await waitFor(() => {
      const canvas = document.querySelector('.iif-canvas-viewport__canvas')
      expect(canvas).toHaveStyle({ backgroundColor: '#aabbcc' })
    })
  })
})
