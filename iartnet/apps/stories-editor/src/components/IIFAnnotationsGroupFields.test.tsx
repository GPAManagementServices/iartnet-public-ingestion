import { cleanup, render, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { useState } from 'react'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { createEmptyIIFAnnotationsGroup } from '../types/story'
import { IIFAnnotationsGroupFields } from './IIFAnnotationsGroupFields'

afterEach(() => cleanup())

function EditorWithState() {
  const [value, setValue] = useState(createEmptyIIFAnnotationsGroup())
  return <IIFAnnotationsGroupFields value={value} onChange={setValue} />
}

describe('IIFAnnotationsGroupFields', () => {
  beforeEach(() => {
    vi.stubGlobal(
      'fetch',
      vi.fn().mockResolvedValue({
        ok: true,
        json: async () => ({ width: 5153, height: 7064 }),
      }),
    )
  })

  it('Aggiungi annotazione crea Annotazione #1', async () => {
    const user = userEvent.setup()
    render(<EditorWithState />)
    await user.click(screen.getAllByRole('button', { name: /Aggiungi annotazione/i })[0]!)
    expect(screen.getByRole('button', { name: /Annotazione #1/i })).toBeInTheDocument()
  })

  it('dopo Chiudi tutti, nuova annotazione resta l’unica aperta', async () => {
    const user = userEvent.setup()
    render(<EditorWithState />)

    await user.click(screen.getAllByRole('button', { name: /Aggiungi annotazione/i })[0]!)
    await user.click(screen.getAllByRole('button', { name: /Aggiungi annotazione/i })[0]!)
    await user.click(screen.getByRole('button', { name: /Chiudi tutti/i }))

    const headersBefore = screen.getAllByRole('button', { name: /Annotazione #/i })
    expect(headersBefore[0]).toHaveAttribute('aria-expanded', 'false')
    expect(headersBefore[1]).toHaveAttribute('aria-expanded', 'false')

    await user.click(screen.getAllByRole('button', { name: /Aggiungi annotazione/i })[0]!)
    const headersAfter = screen.getAllByRole('button', { name: /Annotazione #/i })
    expect(headersAfter).toHaveLength(3)
    expect(headersAfter[0]).toHaveAttribute('aria-expanded', 'false')
    expect(headersAfter[1]).toHaveAttribute('aria-expanded', 'false')
    expect(headersAfter[2]).toHaveAttribute('aria-expanded', 'true')
  })

  it('Elimina rimuove l’annotazione', async () => {
    const user = userEvent.setup()
    render(<EditorWithState />)

    await user.click(screen.getAllByRole('button', { name: /Aggiungi annotazione/i })[0]!)
    const panel = within(
      screen.getByRole('button', { name: /Annotazione #1/i }).closest('.accordion-item') as HTMLElement,
    )
    await user.click(panel.getByRole('button', { name: /Elimina/i }))
    expect(screen.queryByRole('button', { name: /Annotazione #1/i })).not.toBeInTheDocument()
  })

  it('Caption usa editor ricco a livello sezione', () => {
    render(<EditorWithState />)

    expect(screen.getByRole('group', { name: 'Caption' })).toBeInTheDocument()
    expect(screen.getByRole('toolbar', { name: /Formattazione Caption/i })).toBeInTheDocument()
    expect(
      within(screen.getByRole('group', { name: 'Caption' })).getByRole('button', {
        name: 'Inserisci immagine',
      }),
    ).toBeInTheDocument()
    expect(within(screen.getByRole('group', { name: 'Caption' })).getByRole('textbox')).toBeInTheDocument()
  })

  it('switch Solo attiva nasconde le altre annotazioni sul canvas', async () => {
    const user = userEvent.setup()
    render(<EditorWithState />)

    const baseUri = screen.getByLabelText(/^BaseURI$/i)
    await user.click(baseUri)
    await user.paste('https://iiif.gpams.it/iiif/2/uuid.tif')

    await user.click(screen.getAllByRole('button', { name: /Aggiungi annotazione/i })[0]!)
    await user.click(screen.getAllByRole('button', { name: /Aggiungi annotazione/i })[0]!)

    await waitFor(() => {
      expect(screen.getByRole('checkbox', { name: /Solo attiva/i })).not.toBeDisabled()
    })

    const soloAttiva = screen.getByRole('checkbox', { name: /Solo attiva/i })
    await user.click(soloAttiva)
    expect(soloAttiva).toBeChecked()
  })
})
