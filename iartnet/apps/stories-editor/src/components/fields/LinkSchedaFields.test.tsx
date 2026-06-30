import { cleanup, fireEvent, render, screen } from '@testing-library/react'
import { useState } from 'react'
import { afterEach, describe, expect, it, vi } from 'vitest'
import type { TStoryLinkSchedaType } from '../../types/story'
import { LinkSchedaFields } from './LinkSchedaFields'

afterEach(() => cleanup())

describe('LinkSchedaFields', () => {
  it('senza value iniziale: Layout e URL aggiornano onChange', () => {
    const onChange = vi.fn()
    render(<LinkSchedaFields value={undefined} onChange={onChange} />)

    fireEvent.change(screen.getByLabelText(/^Layout$/i), { target: { value: 'TopRight' } })
    expect(onChange).toHaveBeenCalledWith({ Layout: 'TopRight', URL: '' })

    onChange.mockClear()
    fireEvent.change(screen.getByLabelText(/^URL$/i), { target: { value: 'https://ex.test/x' } })
    expect(onChange).toHaveBeenCalledWith({ Layout: 'TopLeft', URL: 'https://ex.test/x' })
  })

  it('con value: cambiare URL non resetta Layout', () => {
    function Harness() {
      const [v, setV] = useState<TStoryLinkSchedaType>({ Layout: 'TopRight', URL: 'https://a' })
      return <LinkSchedaFields value={v} onChange={setV} />
    }
    render(<Harness />)

    const url = screen.getByLabelText(/^URL$/i)
    fireEvent.change(url, { target: { value: 'https://b' } })
    expect(url).toHaveValue('https://b')
    expect(screen.getByLabelText(/^Layout$/i)).toHaveValue('TopRight')
  })
})
