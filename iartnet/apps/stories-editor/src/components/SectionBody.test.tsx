import { cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { createEmptySection } from '../story/sectionKind'
import { SectionBody } from './SectionBody'

afterEach(() => cleanup())

describe('SectionBody', () => {
  it('SplitImage: cambia Layout e URL LinkScheda', async () => {
    const user = userEvent.setup()
    const onChange = vi.fn()
    render(
      <SectionBody section={createEmptySection('SplitImage')} onChange={onChange} />,
    )

    await user.selectOptions(screen.getByLabelText(/^Layout$/i, { selector: '#section-split-layout' }), 'LeftInline')
    expect(onChange).toHaveBeenCalledWith(expect.objectContaining({ Layout: 'LeftInline' }))

    onChange.mockClear()
    await user.selectOptions(screen.getByLabelText(/^MediaType$/i, { selector: '#section-split-media-type' }), 'Video')
    expect(onChange).toHaveBeenCalledWith(expect.objectContaining({ MediaType: 'Video' }))

    onChange.mockClear()
    const linkScheda = screen.getByRole('group', { name: /^LinkScheda$/i })
    fireEvent.change(within(linkScheda).getByLabelText(/^URL$/i), { target: { value: 'info' } })
    expect(onChange).toHaveBeenLastCalledWith(
      expect.objectContaining({
        LinkScheda: { Layout: 'TopLeft', URL: 'info' },
      }),
    )
  })

  it('ScrollReveal: aggiunge paragrafo', async () => {
    const user = userEvent.setup()
    const onChange = vi.fn()
    render(
      <SectionBody section={createEmptySection('ScrollReveal')} onChange={onChange} />,
    )
    await user.click(screen.getByRole('button', { name: /Aggiungi paragrafo/i }))
    expect(onChange).toHaveBeenCalledWith(
      expect.objectContaining({
        Paragraphs: expect.arrayContaining([
          expect.objectContaining({ Text: '' }),
          expect.objectContaining({ Text: '' }),
        ]),
      }),
    )
  })

  it('ScrollReveal: cambia URL LinkScheda del primo paragrafo', () => {
    const onChange = vi.fn()
    render(
      <SectionBody section={createEmptySection('ScrollReveal')} onChange={onChange} />,
    )
    const linkScheda = screen.getByRole('group', { name: /^LinkScheda$/i })
    fireEvent.change(within(linkScheda).getByLabelText(/^URL$/i), { target: { value: 'scheda' } })
    expect(onChange).toHaveBeenLastCalledWith(
      expect.objectContaining({
        Paragraphs: [
          expect.objectContaining({
            LinkScheda: { Layout: 'TopLeft', URL: 'scheda' },
          }),
        ],
      }),
    )
  })

  it('ImageFullScreen: cambia Position e Fit', async () => {
    const user = userEvent.setup()
    const onChange = vi.fn()
    render(
      <SectionBody section={createEmptySection('ImageFullScreen')} onChange={onChange} />,
    )
    await user.selectOptions(screen.getByLabelText(/^Position$/i, { selector: '#section-fs-position' }), 'TopRight')
    await user.selectOptions(screen.getByLabelText(/^Fit$/i, { selector: '#section-fs-fit' }), 'Contain')
    expect(onChange).toHaveBeenCalledWith(expect.objectContaining({ Position: 'TopRight' }))
    expect(onChange).toHaveBeenCalledWith(expect.objectContaining({ Fit: 'Contain' }))
  })

  it('TextIntro: aggiorna Text', async () => {
    const user = userEvent.setup()
    const onChange = vi.fn()
    render(
      <SectionBody section={createEmptySection('TextIntro')} onChange={onChange} />,
    )
    const editor = document.querySelector('.tiptap')
    expect(editor).toBeTruthy()
    await user.click(editor!)
    await user.keyboard('intro')
    await waitFor(() => {
      expect(onChange).toHaveBeenCalled()
      const last = onChange.mock.calls.at(-1)?.[0] as { Text: string }
      expect(last.Text).toMatch(/intro/)
    })
  })

  it('SplitContent: aggiorna LeftText e RightText', async () => {
    const user = userEvent.setup()
    const onChange = vi.fn()
    render(
      <SectionBody section={createEmptySection('SplitContent')} onChange={onChange} />,
    )
    const editors = document.querySelectorAll('.tiptap')
    expect(editors.length).toBeGreaterThanOrEqual(2)

    await user.click(editors[0]!)
    await user.keyboard('L')
    await waitFor(() => {
      expect(onChange).toHaveBeenCalledWith(expect.objectContaining({ LeftText: expect.stringMatching(/L/) }))
    })

    onChange.mockClear()
    await user.click(editors[1]!)
    await user.keyboard('R')
    await waitFor(() => {
      expect(onChange).toHaveBeenCalledWith(expect.objectContaining({ RightText: expect.stringMatching(/R/) }))
    })
  })

  it('IIFAnnotationsGroup: mostra toolbar annotazioni', async () => {
    const user = userEvent.setup()
    render(
      <SectionBody section={createEmptySection('IIFAnnotationsGroup')} onChange={() => {}} />,
    )
    await user.click(screen.getAllByRole('button', { name: /Aggiungi annotazione/i })[0]!)
    expect(screen.getByRole('button', { name: /Annotazione #1/i })).toBeInTheDocument()
  })

  it('InlineImage: aggiorna URL immagine (fieldset Inline)', () => {
    const onChange = vi.fn()
    render(
      <SectionBody section={createEmptySection('InlineImage')} onChange={onChange} />,
    )
    const imageFieldset = screen.getByRole('group', { name: /Inline: immagine/i })
    fireEvent.change(within(imageFieldset).getByLabelText(/^URL$/i), {
      target: { value: 'https://inline.test/p.png' },
    })
    expect(onChange).toHaveBeenLastCalledWith(
      expect.objectContaining({
        Image: expect.objectContaining({ URL: 'https://inline.test/p.png' }),
      }),
    )
  })
})
