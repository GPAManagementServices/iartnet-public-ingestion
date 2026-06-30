import { cleanup, fireEvent, render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { IIFRectFields } from './IIFRectFields'

afterEach(() => cleanup())

describe('IIFRectFields', () => {
  it('mostra errore mentre il rettangolo è fuori canvas (senza clamp automatico)', () => {
    render(
      <IIFRectFields
        rowId="r1"
        rect={{ x: 5100, y: 0, width: 100, height: 50 }}
        bounds={{ width: 5153, height: 7064 }}
        onChange={() => {}}
      />,
    )
    expect(screen.getByText(/fuori dal canvas/i)).toBeInTheDocument()
    expect(screen.getByLabelText(/^width$/i)).toHaveClass('is-invalid')
  })

  it('blur non corregge silenziosamente i valori', () => {
    const onChange = vi.fn()
    render(
      <IIFRectFields
        rowId="r1"
        rect={{ x: 5100, y: 0, width: 100, height: 50 }}
        bounds={{ width: 5153, height: 7064 }}
        onChange={onChange}
      />,
    )
    fireEvent.blur(screen.getByLabelText(/^x$/i))
    expect(onChange).not.toHaveBeenCalled()
    expect(screen.getByText(/fuori dal canvas/i)).toBeInTheDocument()
  })

  it('Correggi al canvas applica clamp su richiesta', async () => {
    const user = userEvent.setup()
    const onChange = vi.fn()
    render(
      <IIFRectFields
        rowId="r1"
        rect={{ x: 5100, y: 7000, width: 200, height: 200 }}
        bounds={{ width: 5153, height: 7064 }}
        onChange={onChange}
      />,
    )
    await user.click(screen.getByRole('button', { name: /Correggi al canvas/i }))
    expect(onChange).toHaveBeenCalledWith({
      x: 4953,
      y: 6864,
      width: 200,
      height: 200,
    })
  })
})
