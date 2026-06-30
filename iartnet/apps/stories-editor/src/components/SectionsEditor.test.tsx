import { cleanup, render, screen, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { useState } from 'react'
import { afterEach, describe, expect, it } from 'vitest'
import type { SectionRow } from '../story/sectionRow'
import type { TStorySection } from '../types/story'
import { newSectionRow } from '../story/sectionRow'
import { SectionsEditor } from './SectionsEditor'

afterEach(() => cleanup())

function EditorWithState({ initialRows }: { initialRows: SectionRow[] }) {
  const [rows, setRows] = useState<SectionRow[]>(initialRows)
  return <SectionsEditor rows={rows} onRowsChange={setRows} />
}

describe('SectionsEditor', () => {
  it('Aggiungi sezione crea Sezione #2', async () => {
    const user = userEvent.setup()
    render(<EditorWithState initialRows={[newSectionRow('TextIntro')]} />)
    await user.click(screen.getAllByRole('button', { name: /Aggiungi sezione/i })[0]!)
    expect(screen.getByRole('button', { name: /Sezione #2/i })).toBeInTheDocument()
  })

  it('cambio tipo in SplitContent mostra LeftText e RightText', async () => {
    const user = userEvent.setup()
    render(<EditorWithState initialRows={[newSectionRow('TextIntro')]} />)

    const sectionHeader = screen.getByRole('button', { name: /Sezione #1/i })
    const panel = within(sectionHeader.closest('.accordion-item') as HTMLElement)
    await user.selectOptions(panel.getByLabelText(/Tipo sezione/i), 'SplitContent')

    expect(panel.getByRole('group', { name: 'LeftText' })).toBeInTheDocument()
    expect(panel.getByRole('group', { name: 'RightText' })).toBeInTheDocument()
  })

  it('Giù riordina ed Elimina rimuove la sezione', async () => {
    const user = userEvent.setup()
    render(
      <EditorWithState
        initialRows={[newSectionRow('TextIntro'), newSectionRow('TextIntro')]}
      />,
    )

    const firstPanel = within(
      screen.getAllByRole('button', { name: /Sezione #/i })[0]!.closest('.accordion-item') as HTMLElement,
    )
    await user.click(firstPanel.getByRole('button', { name: /Giù/i }))
    await user.click(
      within(
        screen.getByRole('button', { name: /Sezione #1/i }).closest('.accordion-item') as HTMLElement,
      ).getByRole('button', { name: /Elimina/i }),
    )
    expect(screen.queryByRole('button', { name: /Sezione #2/i })).not.toBeInTheDocument()
  })

  it('dopo Chiudi tutti, nuova sezione resta l’unica aperta', async () => {
    const user = userEvent.setup()
    render(
      <EditorWithState
        initialRows={[newSectionRow('TextIntro'), newSectionRow('TextIntro')]}
      />,
    )

    await user.click(screen.getByRole('button', { name: /Chiudi tutti/i }))
    const headersBefore = screen.getAllByRole('button', { name: /Sezione #/i })
    expect(headersBefore[0]).toHaveAttribute('aria-expanded', 'false')
    expect(headersBefore[1]).toHaveAttribute('aria-expanded', 'false')

    await user.click(screen.getAllByRole('button', { name: /Aggiungi sezione/i })[0]!)
    const headersAfter = screen.getAllByRole('button', { name: /Sezione #/i })
    expect(headersAfter).toHaveLength(3)
    expect(headersAfter[0]).toHaveAttribute('aria-expanded', 'false')
    expect(headersAfter[1]).toHaveAttribute('aria-expanded', 'false')
    expect(headersAfter[2]).toHaveAttribute('aria-expanded', 'true')
  })

  it('Apri tutti e Chiudi tutti gestiscono aria-expanded', async () => {
    const user = userEvent.setup()
    render(
      <EditorWithState
        initialRows={[newSectionRow('TextIntro'), newSectionRow('TextIntro')]}
      />,
    )

    await user.click(screen.getByRole('button', { name: /Chiudi tutti/i }))
    for (const h of screen.getAllByRole('button', { name: /Sezione #/i })) {
      expect(h).toHaveAttribute('aria-expanded', 'false')
    }

    await user.click(screen.getByRole('button', { name: /Apri tutti/i }))
    for (const h of screen.getAllByRole('button', { name: /Sezione #/i })) {
      expect(h).toHaveAttribute('aria-expanded', 'true')
    }
  })

  it('da TextIntro a InlineText mantiene il testo', async () => {
    const user = userEvent.setup()
    const row = newSectionRow('TextIntro')
    render(
      <EditorWithState
        initialRows={[
          { ...row, section: { ...row.section, Text: 'stesso testo' } as TStorySection },
        ]}
      />,
    )

    const sectionHeader = screen.getByRole('button', { name: /Sezione #1/i })
    const panel = within(sectionHeader.closest('.accordion-item') as HTMLElement)
    await user.selectOptions(panel.getByLabelText(/Tipo sezione/i), 'InlineText')
    expect(within(panel.getByRole('group', { name: 'Text' })).getByText('stesso testo')).toBeInTheDocument()
  })
})
