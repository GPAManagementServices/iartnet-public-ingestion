import { cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, describe, expect, it, vi } from 'vitest'
import type { TStoryImageType } from '../../types/story'
import { StoryImageFields } from './StoryImageFields'

afterEach(() => cleanup())

describe('StoryImageFields', () => {
  it('aggiorna URL e azzera Caption/bgColor quando i campi sono svuotati', async () => {
    const user = userEvent.setup()
    const onChange = vi.fn()
    const value: TStoryImageType = {
      URL: 'https://img.test/a.png',
      Caption: 'didascalia',
      bgColor: '#aabbcc',
    }
    render(<StoryImageFields prefix="Test" value={value} onChange={onChange} />)

    fireEvent.change(screen.getByLabelText(/^URL$/i), {
      target: { value: 'https://img.test/b.png' },
    })
    expect(onChange).toHaveBeenLastCalledWith(
      expect.objectContaining({ URL: 'https://img.test/b.png' }),
    )

    onChange.mockClear()
    await user.click(screen.getByRole('button', { name: 'Sorgente HTML' }))
    fireEvent.change(screen.getByLabelText(/^Sorgente HTML Caption \(opz\.\)$/i), {
      target: { value: '' },
    })
    expect(onChange).toHaveBeenLastCalledWith(
      expect.objectContaining({ Caption: null }),
    )

    onChange.mockClear()
    await user.clear(screen.getByLabelText(/bgColor/i))
    expect(onChange).toHaveBeenLastCalledWith(
      expect.objectContaining({ bgColor: null }),
    )
  })

  it('mostra anteprima con URL valorizzato', () => {
    render(
      <StoryImageFields
        prefix="Test"
        value={{ URL: 'https://img.test/a.png', Caption: 'cap' }}
        onChange={vi.fn()}
      />,
    )
    expect(screen.getByRole('img')).toHaveAttribute('src', 'https://img.test/a.png')
  })

  it('Caption usa editor ricco TipTap', () => {
    render(
      <StoryImageFields
        prefix="Test"
        value={{ URL: '', Caption: 'didascalia' }}
        onChange={vi.fn()}
      />,
    )
    expect(screen.getByRole('group', { name: 'Caption (opz.)' })).toBeInTheDocument()
    expect(
      screen.getByRole('toolbar', { name: /Formattazione Caption \(opz\.\)/i }),
    ).toBeInTheDocument()
  })

  it('nasconde Caption quando showCaption è false', () => {
    render(
      <StoryImageFields
        prefix="Test"
        value={{ URL: '', Caption: 'nascosta' }}
        showCaption={false}
        onChange={vi.fn()}
      />,
    )
    expect(screen.queryByRole('group', { name: 'Caption (opz.)' })).not.toBeInTheDocument()
  })

  it('carica caption plain nell’editor', async () => {
    render(
      <StoryImageFields
        prefix="Test"
        value={{ URL: '', Caption: 'plain cap' }}
        onChange={vi.fn()}
      />,
    )
    await waitFor(() => {
      expect(document.querySelector('.tiptap')?.textContent).toContain('plain cap')
    })
  })

  it('Caption è nella colonna sinistra sotto URL e bgColor', () => {
    const { container } = render(
      <StoryImageFields
        prefix="Test"
        value={{ URL: 'https://img.test/a.png', Caption: 'cap', bgColor: '#abc' }}
        onChange={vi.fn()}
      />,
    )
    const inputs = container.querySelector('.story-image-fields__inputs')
    expect(inputs?.querySelector('.story-image-fields-row')).toBeNull()
    expect(
      within(inputs as HTMLElement).getByRole('group', { name: 'Caption (opz.)' }),
    ).toBeInTheDocument()
    expect(container.querySelector('.story-image-preview-col')).toBeTruthy()
  })

  it('anteprima condivide metà riga con i campi', () => {
    const { container } = render(
      <StoryImageFields prefix="Test" value={{ URL: '' }} onChange={vi.fn()} />,
    )
    const row = container.querySelector('.story-image-fields-row')
    expect(row?.querySelector('.story-image-preview-col')).toBeTruthy()
    expect(row?.querySelector('.story-image-preview')).toBeTruthy()
    expect(row?.querySelector('.story-image-fields__inputs')).toBeTruthy()
  })
})
