import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { RichTextField } from './fields/RichTextField'

afterEach(() => cleanup())

describe('RichTextField', () => {
  it('mostra label e toolbar minima', async () => {
    render(<RichTextField label="Text" value="" onChange={() => {}} />)
    expect(screen.getByText('Text')).toBeInTheDocument()
    expect(screen.getByRole('toolbar', { name: /Formattazione Text/i })).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Grassetto' })).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Corsivo' })).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Link' })).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Sorgente HTML' })).toBeInTheDocument()
  })

  it('propaga HTML sanitizzato dopo digitazione', async () => {
    const user = userEvent.setup()
    const onChange = vi.fn()
    render(<RichTextField label="Text" value="" onChange={onChange} />)

    const editor = document.querySelector('.tiptap')
    expect(editor).toBeTruthy()
    await user.click(editor!)
    await user.keyboard('ciao')

    await waitFor(() => {
      expect(onChange).toHaveBeenCalled()
      const last = onChange.mock.calls.at(-1)?.[0] as string
      expect(last).toMatch(/ciao/)
    })
  })

  it('carica valore HTML iniziale', async () => {
    render(<RichTextField label="Body" value="<b>titolo</b>" onChange={() => {}} />)
    await waitFor(() => {
      expect(document.querySelector('.tiptap')?.innerHTML).toContain('titolo')
    })
  })

  it('toggle sorgente HTML mostra il markup persistito', async () => {
    const user = userEvent.setup()
    render(<RichTextField label="Text" value="<b>bold</b><br />riga" onChange={() => {}} />)

    await user.click(screen.getByRole('button', { name: 'Sorgente HTML' }))
    const source = screen.getByLabelText(/^Sorgente HTML Text$/i)
    expect(source).toHaveValue('<b>bold</b><br />riga')
    expect(screen.getByRole('button', { name: 'Sorgente HTML' })).toHaveAttribute('aria-pressed', 'true')
  })

  it('modifica in sorgente HTML propaga valore sanitizzato', async () => {
    const user = userEvent.setup()
    const onChange = vi.fn()
    render(<RichTextField label="Text" value="" onChange={onChange} />)

    await user.click(screen.getByRole('button', { name: 'Sorgente HTML' }))
    const source = screen.getByLabelText(/^Sorgente HTML Text$/i)
    fireEvent.change(source, { target: { value: '<b>ok</b><script>x</script>' } })

    expect(onChange).toHaveBeenLastCalledWith('<b>ok</b>')
  })

  it('allowImages: mostra pulsante immagine', () => {
    render(<RichTextField label="Cap" value="" onChange={() => {}} allowImages />)
    expect(screen.getByRole('button', { name: 'Inserisci immagine' })).toBeInTheDocument()
  })

  it('allowImages: inserisce img da prompt', async () => {
    const user = userEvent.setup()
    const onChange = vi.fn()
    vi.stubGlobal('prompt', vi.fn().mockReturnValue('https://ex.test/photo.jpg'))
    render(<RichTextField label="Cap" value="" onChange={onChange} allowImages />)

    await user.click(screen.getByRole('button', { name: 'Inserisci immagine' }))

    await waitFor(() => {
      expect(onChange).toHaveBeenCalled()
      const last = onChange.mock.calls.at(-1)?.[0] as string
      expect(last).toContain('https://ex.test/photo.jpg')
    })

    vi.unstubAllGlobals()
  })
})
