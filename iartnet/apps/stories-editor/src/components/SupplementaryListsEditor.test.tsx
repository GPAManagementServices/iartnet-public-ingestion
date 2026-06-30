import { cleanup, render, screen, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { useState } from 'react'
import { afterEach, describe, expect, it } from 'vitest'
import { createDefaultExtJson } from '../story/defaults'
import type { TStoriesExtJson } from '../types/story'
import { supplementaryMerge } from './supplementaryHelpers'
import { SupplementaryListsEditor } from './SupplementaryListsEditor'

afterEach(() => cleanup())

function Harness({ sections = [] as TStoriesExtJson['sections'] }: { sections?: TStoriesExtJson['sections'] }) {
  const [ext, setExt] = useState<TStoriesExtJson>(() => createDefaultExtJson())
  return (
    <SupplementaryListsEditor
      ext={ext}
      sections={sections}
      onMerge={(partial) => setExt((prev) => supplementaryMerge(prev, partial))}
    />
  )
}

function cardSection(title: string) {
  const card = screen.getByText(title).closest('.card')
  if (!card) throw new Error(`Card "${title}" non trovata`)
  return within(card as HTMLElement)
}

describe('SupplementaryListsEditor', () => {
  it('Crediti: aggiungi, modifica titolo, rimuovi', async () => {
    const user = userEvent.setup()
    render(<Harness />)
    const credits = cardSection('Crediti')
    expect(credits.getByText(/Nessun elemento/i)).toBeInTheDocument()

    await user.click(credits.getByRole('button', { name: /Aggiungi/i }))
    const voce = screen.getByRole('button', { name: /Voce #1/i })
    await user.click(voce)
    const panel = within(voce.closest('.accordion-item') as HTMLElement)
    await user.type(panel.getByLabelText(/^Title$/i, { selector: '#credits-title-0' }), 'Autore')

    expect(screen.getByRole('button', { name: /Autore/i })).toBeInTheDocument()
    await user.click(panel.getByRole('button', { name: /Rimuovi/i }))
    expect(credits.getByText(/Nessun elemento/i)).toBeInTheDocument()
  })

  it('Bibliografia: descrizione lunga troncata nel titolo accordion', async () => {
    const user = userEvent.setup()
    render(<Harness />)
    const bib = cardSection('Bibliografia')
    await user.click(bib.getByRole('button', { name: /Aggiungi/i }))

    const voce = screen.getByRole('button', { name: /Voce #1/i })
    await user.click(voce)
    const panel = within(voce.closest('.accordion-item') as HTMLElement)
    await user.type(panel.getByLabelText(/^Description$/i, { selector: '#bibliography-description-0' }), 'x'.repeat(60))

    expect(screen.getByRole('button', { name: /Voce #1/i }).textContent).toContain('…')
  })

  it('Sitografia: aggiungi e modifica titolo', async () => {
    const user = userEvent.setup()
    render(<Harness />)
    const sito = cardSection('Sitografia')
    await user.click(sito.getByRole('button', { name: /Aggiungi/i }))

    const voce = screen.getByRole('button', { name: /Voce #1/i })
    await user.click(voce)
    const panel = within(voce.closest('.accordion-item') as HTMLElement)
    await user.type(panel.getByLabelText(/^Title$/i, { selector: '#sitography-title-0' }), 'Sito ref')

    expect(screen.getByRole('button', { name: /Sito ref/i })).toBeInTheDocument()
  })

  it('Catalogo: modifica Title, Author e URL link', async () => {
    const user = userEvent.setup()
    render(<Harness />)
    const catalogo = cardSection('Catalogo opere citate')
    await user.click(catalogo.getByRole('button', { name: /Aggiungi/i }))

    const opera = screen.getByRole('button', { name: /Opera #1/i })
    await user.click(opera)
    const panel = within(opera.closest('.accordion-item') as HTMLElement)

    await user.type(panel.getByLabelText(/^Title$/i, { selector: '#catalogo-title-0' }), 'Tit opera')
    await user.type(panel.getByLabelText(/^Author$/i, { selector: '#catalogo-author-0' }), 'Autore X')

    const linkScheda = within(panel.getByRole('group', { name: /^LinkScheda$/i }))
    const url = linkScheda.getByLabelText(/^URL$/i)
    await user.clear(url)
    await user.type(url, 'https://link.test/scheda')
    expect(url).toHaveValue('https://link.test/scheda')
  })

  it('Catalogo: preload inserisce voci dalle sezioni con immagine', async () => {
    const user = userEvent.setup()
    render(
      <Harness
        sections={[
          {
            Kind: 'SplitImage',
            published: true,
            animazione: { Effetto: '' },
            Layout: 'Right',
            Text: 'testo',
            MediaType: 'Image',
            Image: { URL: 'https://img.test/a.jpg', Caption: 'Cap A' },
            LinkScheda: { Layout: 'TopLeft', URL: 'https://scheda.test/a' },
          },
          {
            Kind: 'TextIntro',
            published: true,
            animazione: { Effetto: '' },
            Text: 'solo testo',
          },
          {
            Kind: 'InlineImage',
            published: true,
            animazione: { Effetto: '' },
            Image: { URL: 'https://img.test/b.jpg' },
            LinkScheda: { Layout: 'TopRight', URL: 'https://scheda.test/b' },
          },
        ]}
      />,
    )
    const catalogo = cardSection('Catalogo opere citate')
    expect(catalogo.getByText(/Nessuna opera/i)).toBeInTheDocument()

    await user.click(catalogo.getByRole('button', { name: /^preload$/i }))

    expect(screen.getByRole('button', { name: /Opera #1/i })).toBeInTheDocument()
    expect(screen.getByRole('button', { name: /Opera #2/i })).toBeInTheDocument()

    await user.click(screen.getByRole('button', { name: /Opera #1/i }))
    const panel1 = within(screen.getByRole('button', { name: /Opera #1/i }).closest('.accordion-item') as HTMLElement)
    const imageFields = within(panel1.getByText(/Catalogo #1: immagine/i).closest('fieldset') as HTMLElement)
    expect(imageFields.getByLabelText(/^URL$/i)).toHaveValue('https://img.test/a.jpg')
    expect(imageFields.queryByLabelText(/Caption/i)).not.toBeInTheDocument()
    expect(imageFields.queryByLabelText(/bgColor/i)).not.toBeInTheDocument()
    const link1 = within(panel1.getByRole('group', { name: /^LinkScheda$/i }))
    expect(link1.queryByLabelText(/^Layout$/i)).not.toBeInTheDocument()
    expect(link1.getByLabelText(/^URL$/i)).toHaveValue('https://scheda.test/a')
  })
})
