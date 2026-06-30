import { cleanup, render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, describe, expect, it } from 'vitest'
import { IIFCanvasViewport } from './IIFCanvasViewport'

afterEach(() => cleanup())

describe('IIFCanvasViewport', () => {
  it('mostra toolbar zoom e immagine IIIF', () => {
    render(
      <IIFCanvasViewport
        baseUri="https://iiif.gpams.it/iiif/2/uuid.tif"
        bounds={{ width: 5153, height: 7064 }}
        annotations={[{ Text: 'a', Rect: { x: 10, y: 20, width: 100, height: 50 } }]}
      />,
    )
    expect(screen.getByLabelText('Canvas IIIF zoomabile')).toBeInTheDocument()
    expect(screen.getByText('100%')).toBeInTheDocument()
    const img = document.querySelector('.iif-canvas-viewport__img')
    expect(img?.getAttribute('src')).toMatch(/\/full\/\d+,\/0\/default\.jpg/)
  })

  it('zoom avanti aumenta risoluzione richiesta IIIF', async () => {
    const user = userEvent.setup()
    render(
      <IIFCanvasViewport
        baseUri="https://iiif.gpams.it/iiif/2/uuid.tif"
        bounds={{ width: 5153, height: 7064 }}
      />,
    )
    const img = document.querySelector('.iif-canvas-viewport__img') as HTMLImageElement
    const matchBefore = img.getAttribute('src')?.match(/\/full\/(\d+),\/0\//)
    const widthBefore = Number(matchBefore?.[1] ?? 0)
    await user.click(screen.getByLabelText('Zoom avanti'))
    const matchAfter = img.getAttribute('src')?.match(/\/full\/(\d+),\/0\//)
    const widthAfter = Number(matchAfter?.[1] ?? 0)
    expect(widthAfter).toBeGreaterThanOrEqual(widthBefore)
    expect(screen.getByText('125%')).toBeInTheDocument()
  })
})
