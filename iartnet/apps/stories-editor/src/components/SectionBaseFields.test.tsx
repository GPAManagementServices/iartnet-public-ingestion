import { cleanup, fireEvent, render, screen, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { createEmptySection } from '../story/sectionKind'
import { SectionBaseFields } from './SectionBaseFields'

afterEach(() => cleanup())

describe('SectionBaseFields', () => {
  it('pannello Aspetto sezione è chiuso di default', () => {
    render(
      <SectionBaseFields
        instanceId="sec-1"
        section={createEmptySection('TextIntro')}
        onChange={() => {}}
      />,
    )

    expect(screen.getByRole('button', { name: /Aspetto sezione/i })).toHaveAttribute(
      'aria-expanded',
      'false',
    )
  })

  it('apre il pannello e aggiorna foreColor, bgColor e bgImage', async () => {
    const user = userEvent.setup()
    const onChange = vi.fn()
    render(
      <SectionBaseFields
        instanceId="sec-1"
        section={createEmptySection('TextIntro')}
        onChange={onChange}
      />,
    )

    await user.click(screen.getByRole('button', { name: /Aspetto sezione/i }))

    const panel = within(
      screen.getByRole('button', { name: /Aspetto sezione/i }).closest('.accordion-item') as HTMLElement,
    )

    fireEvent.change(panel.getByLabelText(/foreColor sezione \(opz\.\)/i), {
      target: { value: '#112233' },
    })
    expect(onChange).toHaveBeenLastCalledWith(
      expect.objectContaining({ foreColor: '#112233' }),
    )

    onChange.mockClear()
    fireEvent.change(panel.getByLabelText(/bgColor sezione \(opz\.\)/i), {
      target: { value: '#aabbcc' },
    })
    expect(onChange).toHaveBeenLastCalledWith(
      expect.objectContaining({ bgColor: '#aabbcc' }),
    )

    onChange.mockClear()
    fireEvent.change(
      within(panel.getByRole('group', { name: /bgImage: immagine/i })).getByLabelText(/^URL$/i),
      { target: { value: 'https://ex.test/bg.jpg' } },
    )
    expect(onChange).toHaveBeenLastCalledWith(
      expect.objectContaining({
        bgImage: expect.objectContaining({ URL: 'https://ex.test/bg.jpg' }),
      }),
    )
  })

  it('conserva bgImage.bgColor anche senza URL', async () => {
    const user = userEvent.setup()
    const onChange = vi.fn()
    render(
      <SectionBaseFields
        instanceId="sec-1"
        section={createEmptySection('TextIntro')}
        onChange={onChange}
      />,
    )

    await user.click(screen.getByRole('button', { name: /Aspetto sezione/i }))

    const bgImageGroup = within(
      (
        screen.getByRole('button', { name: /Aspetto sezione/i }).closest('.accordion-item') as HTMLElement
      ).querySelector('fieldset') as HTMLElement,
    )

    fireEvent.change(bgImageGroup.getByLabelText(/^bgColor \(opz\.\)$/i), {
      target: { value: '#aabbcc' },
    })
    expect(onChange).toHaveBeenLastCalledWith(
      expect.objectContaining({
        bgImage: expect.objectContaining({ URL: '', bgColor: '#aabbcc' }),
      }),
    )
  })
})
