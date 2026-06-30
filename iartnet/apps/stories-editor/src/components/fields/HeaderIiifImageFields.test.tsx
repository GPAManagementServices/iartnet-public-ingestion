import { cleanup, render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { useState } from 'react'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import type { TStoryImageType } from '../../types/story'
import { HeaderIiifImageFields } from './HeaderIiifImageFields'

afterEach(() => cleanup())

const HEADER_IIIF_URL =
  'https://iiif.gpams.it/iiif/2/7374bafe-152c-463a-b5b5-2f518b1c5e8a.tif/0,675,4961,2300/,1000/0/default.jpg'

function Harness({
  initial,
  onChange = vi.fn(),
}: {
  initial?: Partial<TStoryImageType>
  onChange?: (next: TStoryImageType) => void
}) {
  const [value, setValue] = useState<TStoryImageType>({
    URL: '',
    Caption: null,
    bgColor: null,
    ...initial,
  })
  return (
    <HeaderIiifImageFields
      prefix="Header"
      value={value}
      onChange={(next) => {
        setValue(next)
        onChange(next)
      }}
    />
  )
}

describe('HeaderIiifImageFields', () => {
  beforeEach(() => {
    vi.stubGlobal(
      'fetch',
      vi.fn().mockResolvedValue({
        ok: true,
        json: async () => ({ width: 5153, height: 7064 }),
      }),
    )
  })

  it('modalità manuale con URL vuoto e campo URL editabile', () => {
    render(<Harness />)
    expect(screen.getByLabelText(/^URL$/i)).toBeInTheDocument()
    expect(screen.getByLabelText(/URL manuale/i)).toBeChecked()
  })

  it('incolla URL IIIF in manuale e passa alla modalità IIIF', async () => {
    const user = userEvent.setup()
    const onChange = vi.fn()
    render(<Harness onChange={onChange} />)

    await user.click(screen.getByLabelText(/^URL$/i))
    await user.paste(HEADER_IIIF_URL)

    await waitFor(() => {
      expect(screen.getByRole('radio', { name: 'IIIF' })).toBeChecked()
      expect(screen.getByLabelText(/^BaseURI$/i)).toHaveValue(
        'https://iiif.gpams.it/iiif/2/7374bafe-152c-463a-b5b5-2f518b1c5e8a.tif',
      )
    })

    expect(onChange).toHaveBeenLastCalledWith(
      expect.objectContaining({ URL: HEADER_IIIF_URL }),
    )
  })

  it('carica URL IIIF iniziale con rect e dimensione finale', async () => {
    render(<Harness initial={{ URL: HEADER_IIIF_URL }} />)

    expect(screen.getByRole('radio', { name: 'IIIF' })).toBeChecked()
    expect(screen.getByLabelText(/^URL composto$/i)).toHaveValue(HEADER_IIIF_URL)

    expect(screen.getByLabelText(/^x$/i)).toHaveValue(0)
    expect(screen.getByLabelText(/^y$/i)).toHaveValue(675)
    expect(screen.getByLabelText(/^width$/i)).toHaveValue(4961)
    expect(screen.getByLabelText(/^height$/i)).toHaveValue(2300)
    expect(screen.getByLabelText(/^Alt$/i)).toHaveValue(1000)

    await waitFor(() => {
      expect(screen.getByText(/Canvas 5153 × 7064 px/i)).toBeInTheDocument()
    })
  })

  it('aggiorna URL quando cambia Alt', async () => {
    const user = userEvent.setup()
    const onChange = vi.fn()
    render(<Harness initial={{ URL: HEADER_IIIF_URL }} onChange={onChange} />)

    const alt = screen.getByLabelText(/^Alt$/i)
    await user.clear(alt)
    await user.type(alt, '620')

    expect(onChange).toHaveBeenLastCalledWith(
      expect.objectContaining({
        URL: 'https://iiif.gpams.it/iiif/2/7374bafe-152c-463a-b5b5-2f518b1c5e8a.tif/0,675,4961,2300/,620/0/default.jpg',
      }),
    )
  })
})
