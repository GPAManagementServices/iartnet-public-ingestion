import { cleanup, render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { useState } from 'react'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { createDefaultStory } from '../story/defaults'
import { openMetadataPanel } from '../test/openPanels'
import type { TStoriesTypeData } from '../types/story'
import { MetadataCard } from './MetadataCard'

afterEach(() => cleanup())

function Harness({ initial }: { initial?: Partial<TStoriesTypeData> }) {
  const [story, setStory] = useState<TStoriesTypeData>(() => ({
    ...createDefaultStory(),
    ...initial,
  }))
  return <MetadataCard story={story} onChange={setStory} />
}

describe('MetadataCard', () => {
  it('digitando name aggiorna il sottotitolo dell’accordion', async () => {
    const user = userEvent.setup()
    render(<Harness />)
    await openMetadataPanel(user)
    await user.type(screen.getByLabelText(/^name$/i), 'Nome visibile')
    expect(screen.getByRole('button', { name: /Nome visibile/i })).toBeInTheDocument()
  })

  it('il campo updated_at è modificabile a mano', async () => {
    const user = userEvent.setup()
    render(<Harness initial={{ updated_at: 'old-ts' }} />)
    await openMetadataPanel(user)
    const updatedAt = screen.getByLabelText(/^updated_at$/i)
    await user.clear(updatedAt)
    await user.type(updatedAt, '2025-06-01T12:00:00.000Z')
    expect(updatedAt).toHaveValue('2025-06-01T12:00:00.000Z')
  })

  it('il pulsante Aggiorna updated_at imposta la data corrente', async () => {
    const user = userEvent.setup()
    vi.spyOn(Date.prototype, 'toISOString').mockReturnValue('2099-01-01T00:00:00.000Z')
    render(<Harness initial={{ updated_at: 'old' }} />)
    await openMetadataPanel(user)
    await user.click(screen.getByRole('button', { name: /Aggiorna updated_at/i }))
    expect(screen.getByLabelText(/^updated_at$/i)).toHaveValue('2099-01-01T00:00:00.000Z')
    vi.restoreAllMocks()
  })

  it('aggiorna publish_state, description e created_at', async () => {
    const user = userEvent.setup()
    render(<Harness initial={{ publish_state: 'draft', description: 'd', created_at: 'c0' }} />)
    await openMetadataPanel(user)

    const publishState = screen.getByLabelText(/^publish_state$/i)
    await user.clear(publishState)
    await user.type(publishState, 'published')
    expect(publishState).toHaveValue('published')

    const description = screen.getByLabelText(/^description$/i)
    await user.clear(description)
    await user.type(description, 'Nuova desc')
    expect(description).toHaveValue('Nuova desc')

    const createdAt = screen.getByLabelText(/^created_at$/i)
    await user.clear(createdAt)
    await user.type(createdAt, '2020-01-01T00:00:00.000Z')
    expect(createdAt).toHaveValue('2020-01-01T00:00:00.000Z')
  })
})
