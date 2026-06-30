import { cleanup, fireEvent, render, screen, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { useState } from 'react'
import { afterEach, describe, expect, it } from 'vitest'
import type { TStoryHeaderType } from '../types/story'
import { DEFAULT_HEADER_FONT_COLOR } from '../story/defaults'
import { HeaderEditor } from './HeaderEditor'

afterEach(() => cleanup())

function Harness({ initial }: { initial?: Partial<TStoryHeaderType> }) {
  const [value, setValue] = useState<TStoryHeaderType>(() => ({
    Layout: 'None',
    Title: '',
    SubTitle: null,
    FontColor: DEFAULT_HEADER_FONT_COLOR,
    Chip: '',
    Image: null,
    IndexImage: null,
    SEO: null,
    HeaderLayoutTheme: 'Light',
    ...initial,
  }))
  return <HeaderEditor value={value} onChange={setValue} />
}

async function openHeaderImageAccordion(user: ReturnType<typeof userEvent.setup>) {
  await user.click(screen.getByRole('button', { name: /Header: immagine/i }))
}

async function openIndexImageAccordion(user: ReturnType<typeof userEvent.setup>) {
  await user.click(screen.getByRole('button', { name: /IndexImage: immagine/i }))
}

describe('HeaderEditor', () => {
  it('accordion immagini collassati di default', () => {
    render(
      <Harness
        initial={{
          Layout: 'ImageRight',
          Image: { URL: 'https://ex.test/a.png', Caption: null, bgColor: null },
        }}
      />,
    )
    expect(screen.getByRole('button', { name: /Header: immagine/i })).toHaveAttribute(
      'aria-expanded',
      'false',
    )
    expect(screen.getByRole('button', { name: /IndexImage: immagine/i })).toHaveAttribute(
      'aria-expanded',
      'false',
    )
  })

  it('passando a None azzera Image e nasconde i campi immagine header', async () => {
    const user = userEvent.setup()
    render(
      <Harness
        initial={{
          Layout: 'ImageRight',
          Image: { URL: 'https://ex.test/a.png', Caption: null, bgColor: null },
        }}
      />,
    )
    await openHeaderImageAccordion(user)
    const headerImage = screen.getByRole('group', { name: /Header: immagine/i })
    expect(within(headerImage).getByLabelText(/^URL$/i)).toBeInTheDocument()
    await user.selectOptions(screen.getByLabelText(/Layout/i), 'None')
    expect(screen.queryByRole('button', { name: /Header: immagine/i })).not.toBeInTheDocument()
    expect(screen.getByRole('button', { name: /IndexImage: immagine/i })).toBeInTheDocument()
  })

  it('da None a ImageRight crea Image e mostra URL header', async () => {
    const user = userEvent.setup()
    render(<Harness />)
    await user.selectOptions(screen.getByLabelText(/Layout/i), 'ImageRight')
    await openHeaderImageAccordion(user)
    const headerImage = screen.getByRole('group', { name: /Header: immagine/i })
    const url = within(headerImage).getByLabelText(/^URL$/i)
    expect(url).toHaveValue('')
    await user.type(url, 'https://img.test/x.png')
    expect(url).toHaveValue('https://img.test/x.png')
  })

  it('copia Image in IndexImage con il bottone dedicato', async () => {
    const user = userEvent.setup()
    render(
      <Harness
        initial={{
          Layout: 'ImageRight',
          Image: {
            URL: 'https://ex.test/header.png',
            Caption: 'header cap',
            bgColor: 'rgba(0, 0, 0, 1)',
          },
          IndexImage: null,
        }}
      />,
    )
    await openIndexImageAccordion(user)
    const copyButton = screen.getByRole('button', { name: /Copia da Image/i })
    expect(copyButton).toBeEnabled()
    await user.click(copyButton)
    const indexImage = screen.getByRole('group', { name: /IndexImage: immagine/i })
    expect(within(indexImage).getByLabelText(/^URL$/i)).toHaveValue('https://ex.test/header.png')
    expect(within(indexImage).getByLabelText(/Caption/i)).toHaveValue('header cap')
    expect(within(indexImage).getByLabelText(/bgColor/i)).toHaveValue('rgba(0, 0, 0, 1)')
  })

  it('bottone copia da Image disabilitato senza Image', async () => {
    const user = userEvent.setup()
    render(<Harness initial={{ Layout: 'None', Image: null }} />)
    await openIndexImageAccordion(user)
    expect(screen.getByRole('button', { name: /Copia da Image/i })).toBeDisabled()
  })

  it('IndexImage resta editabile con layout None', async () => {
    const user = userEvent.setup()
    render(<Harness />)
    await openIndexImageAccordion(user)
    const indexImage = screen.getByRole('group', { name: /IndexImage: immagine/i })
    const url = within(indexImage).getByLabelText(/^URL$/i)
    await user.type(url, 'https://index.test/thumb.png')
    expect(url).toHaveValue('https://index.test/thumb.png')
  })

  it('mostra FontColor con default tra Layout e Chip', () => {
    render(<Harness />)
    const layout = screen.getByLabelText(/Layout/i)
    const fontColor = screen.getByLabelText(/FontColor/i)
    expect(fontColor).toHaveValue(DEFAULT_HEADER_FONT_COLOR)
    const chip = screen.getByLabelText(/Chip/i)
    expect(layout.compareDocumentPosition(fontColor) & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy()
    expect(fontColor.compareDocumentPosition(chip) & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy()
  })

  it('FontColor vuoto torna al default', async () => {
    const user = userEvent.setup()
    render(
      <Harness
        initial={{
          FontColor: 'rgba(255, 0, 0, 1)',
        }}
      />,
    )
    const fontColor = screen.getByLabelText(/FontColor/i)
    await user.clear(fontColor)
    expect(fontColor).toHaveValue(DEFAULT_HEADER_FONT_COLOR)
  })

  it('SubTitle è textarea su riga sotto Titolo, max 2 righe', async () => {
    render(<Harness />)
    const title = screen.getByLabelText(/^Titolo$/i, { selector: '#header-title' })
    const subtitle = screen.getByLabelText(/SubTitle/i)
    expect(subtitle.tagName).toBe('TEXTAREA')
    expect(subtitle).toHaveAttribute('rows', '2')
    expect(title.compareDocumentPosition(subtitle) & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy()
    fireEvent.change(subtitle, { target: { value: 'Prima riga\nSeconda riga\nTerza riga' } })
    expect(subtitle).toHaveValue('Prima riga\nSeconda riga')
  })

  it('Titolo, SubTitle e Chip vuoti diventano null', async () => {
    const user = userEvent.setup()
    render(
      <Harness
        initial={{
          Title: 'Titolo',
          SubTitle: 'Sottotitolo',
          Chip: 'chip',
        }}
      />,
    )
    const title = screen.getByLabelText(/^Titolo$/i, { selector: '#header-title' })
    const subtitle = screen.getByLabelText(/SubTitle/i)
    const chip = screen.getByLabelText(/Chip/i)
    await user.clear(title)
    expect(title).toHaveValue('')
    await user.clear(subtitle)
    expect(subtitle).toHaveValue('')
    await user.clear(chip)
    expect(chip).toHaveValue('')
  })

  it('SEO è collassata di default e si apre a richiesta', async () => {
    const user = userEvent.setup()
    render(<Harness initial={{ Title: 'Mozart Magic Flute' }} />)
    const seoHeader = screen.getByRole('button', { name: /SEO/i })
    expect(seoHeader).toHaveAttribute('aria-expanded', 'false')
    await user.click(seoHeader)
    expect(seoHeader).toHaveAttribute('aria-expanded', 'true')
    const seoSlug = screen.getByLabelText(/^Slug$/i)
    await user.type(seoSlug, 'custom-slug')
    expect(seoSlug).toHaveValue('custom-slug')
  })

  it('Genera da Titolo popola lo slug SEO', async () => {
    const user = userEvent.setup()
    render(
      <Harness
        initial={{
          Title: 'Mozart and the Magic Flute',
        }}
      />,
    )
    await user.click(screen.getByRole('button', { name: /SEO/i }))
    await user.click(screen.getByRole('button', { name: /Genera da Titolo/i }))
    expect(screen.getByLabelText(/^Slug$/i)).toHaveValue('mozart-and-the-magic-flute')
  })

  it('Genera da Titolo disabilitato senza titolo', () => {
    render(<Harness />)
    expect(screen.getByRole('button', { name: /Genera da Titolo/i })).toBeDisabled()
  })

  it('slug vuoto dopo blur diventa null', async () => {
    const user = userEvent.setup()
    render(
      <Harness
        initial={{
          SEO: { slug: 'old-slug' },
        }}
      />,
    )
    await user.click(screen.getByRole('button', { name: /SEO/i }))
    const seoSlug = screen.getByLabelText(/^Slug$/i)
    await user.clear(seoSlug)
    await user.tab()
    expect(seoSlug).toHaveValue('')
  })

  it('switch Dark imposta HeaderLayoutTheme', async () => {
    const user = userEvent.setup()
    render(<Harness />)
    const themeSwitch = screen.getByRole('checkbox', { name: /Dark/i })
    expect(themeSwitch).not.toBeChecked()
    await user.click(themeSwitch)
    expect(themeSwitch).toBeChecked()
    await user.click(themeSwitch)
    expect(themeSwitch).not.toBeChecked()
  })

  it('carica header IIIF con coordinate e URL composto', async () => {
    const user = userEvent.setup()
    const iiifUrl =
      'https://iiif.gpams.it/iiif/2/7374bafe-152c-463a-b5b5-2f518b1c5e8a.tif/0,675,4961,2300/,1000/0/default.jpg'
    render(
      <Harness
        initial={{
          Layout: 'ImageRight',
          Image: { URL: iiifUrl, Caption: null, bgColor: null },
        }}
      />,
    )
    await openHeaderImageAccordion(user)
    const headerImage = screen.getByRole('group', { name: /Header: immagine/i })
    expect(within(headerImage).getByLabelText(/^URL composto$/i)).toHaveValue(iiifUrl)
    expect(within(headerImage).getByLabelText(/^y$/i)).toHaveValue(675)
    expect(within(headerImage).getByLabelText(/^Alt$/i)).toHaveValue(1000)
  })

  it('mostra hint se slug diverso dal titolo', async () => {
    const user = userEvent.setup()
    render(
      <Harness
        initial={{
          Title: 'Mozart and the Magic Flute',
          SEO: { slug: 'custom-slug' },
        }}
      />,
    )
    await user.click(screen.getByRole('button', { name: /SEO/i }))
    expect(screen.getByText(/Diverso dal titolo/i)).toBeInTheDocument()
  })
})
