import { cleanup, fireEvent, render, screen, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, describe, expect, it, vi } from 'vitest'
import * as JsonFs from '../story/jsonFilesystem'
import { openContentsTab, openJsonTab, openMetadataPanel } from '../test/openPanels'
import { StoryWorkbench } from './StoryWorkbench'

afterEach(() => cleanup())

const minimalStoryJson = {
  id: 'z1',
  name: 'Z',
  description: '',
  created_at: '',
  updated_at: '',
  publish_state: 'draft',
  ext_json: {
    Header: {
      Layout: 'None' as const,
      Title: 'Da JSON',
      Chip: null,
      Image: null,
    },
    sections: [
      { Kind: 'TextIntro', Text: 'uno' },
      {
        Kind: 'SplitImage',
        Layout: 'Right' as const,
        Text: 'due',
        LinkScheda: { Layout: 'TopLeft', URL: 'info' },
        Image: { URL: 'https://ex.test/x.png' },
        MediaType: 'Image',
      },
    ],
  },
}

describe('StoryWorkbench', () => {
  it('Applica JSON aggiorna header e sezioni nel tab Contenuti', async () => {
    const user = userEvent.setup()
    render(<StoryWorkbench />)
    await openJsonTab(user)

    const area = screen.getByRole('textbox', { name: /Area JSON/i })
    fireEvent.change(area, { target: { value: JSON.stringify(minimalStoryJson, null, 2) } })
    await user.click(screen.getByRole('button', { name: /Applica JSON/i }))

    await openContentsTab(user)
    const panel = screen.getByRole('tabpanel', { name: /Contenuti/i })
    expect(within(panel).getByDisplayValue('Da JSON')).toBeInTheDocument()
    expect(within(panel).getByText('uno')).toBeInTheDocument()
    expect(within(panel).getByDisplayValue('https://ex.test/x.png')).toBeInTheDocument()
  })

  it('JSON invalido mostra alert dismissibile', async () => {
    const user = userEvent.setup()
    render(<StoryWorkbench />)
    await openJsonTab(user)

    fireEvent.change(screen.getByRole('textbox', { name: /Area JSON/i }), { target: { value: '{' } })
    await user.click(screen.getByRole('button', { name: /Applica JSON/i }))

    const alert = await screen.findByRole('alert')
    expect(alert).toHaveTextContent(/JSON/i)
    await user.click(within(alert).getByRole('button', { name: /close/i }))
    expect(screen.queryByRole('alert')).not.toBeInTheDocument()
  })

  it('Sincronizza testo dall’editor copia i metadati nell’area JSON', async () => {
    const user = userEvent.setup()
    render(<StoryWorkbench />)
    await openMetadataPanel(user)
    await user.type(screen.getByLabelText(/^name$/i), 'SyncName')

    await openJsonTab(user)
    const area = screen.getByRole('textbox', { name: /Area JSON/i }) as HTMLTextAreaElement
    expect(area.value).not.toContain('SyncName')

    await user.click(screen.getByRole('button', { name: /Sincronizza testo dall/i }))
    expect(area.value).toContain('SyncName')
  })

  it('Reset modello ripristina id, name e sections vuote', async () => {
    const user = userEvent.setup()
    render(<StoryWorkbench />)
    await openMetadataPanel(user)
    await user.type(screen.getByLabelText(/^id$/i), 'tmp-id')

    await openJsonTab(user)
    await user.click(screen.getByRole('button', { name: /Reset modello/i }))

    const parsed = JSON.parse(
      (screen.getByRole('textbox', { name: /Area JSON/i }) as HTMLTextAreaElement).value,
    ) as { id: string; name: string; ext_json: { sections: unknown[] } }
    expect(parsed.id).toBe('')
    expect(parsed.name).toBe('')
    expect(parsed.ext_json.sections).toEqual([])
  })

  it('Salva su file: errore → alert', async () => {
    const user = userEvent.setup()
    vi.spyOn(JsonFs, 'saveJsonTextToFilesystem').mockRejectedValueOnce(new Error('fail'))
    render(<StoryWorkbench />)
    await openJsonTab(user)
    await user.click(screen.getByRole('button', { name: /Salva su file/i }))
    expect(await screen.findByRole('alert')).toHaveTextContent(/Salvataggio file non riuscito/i)
    vi.restoreAllMocks()
  })

  it('Salva su file: successo → nessun alert', async () => {
    const user = userEvent.setup()
    const save = vi.spyOn(JsonFs, 'saveJsonTextToFilesystem').mockResolvedValueOnce(undefined)
    render(<StoryWorkbench />)
    await openJsonTab(user)
    await user.click(screen.getByRole('button', { name: /Salva su file/i }))
    expect(save).toHaveBeenCalled()
    expect(screen.queryByRole('alert')).not.toBeInTheDocument()
    vi.restoreAllMocks()
  })

  it('Carica da file: lettura fallita → alert', async () => {
    const user = userEvent.setup()
    render(<StoryWorkbench />)
    await openJsonTab(user)
    const input = screen.getByLabelText(/Carica da file/i)
    const file = new File(['{}'], 'x.json', { type: 'application/json' })
    vi.spyOn(file, 'text').mockRejectedValueOnce(new Error('read'))
    await user.upload(input, file)
    expect(await screen.findByRole('alert')).toHaveTextContent(/Lettura file non riuscita/i)
  })

  it('Carica da file: JSON valido → area JSON e tab Contenuti aggiornati', async () => {
    const user = userEvent.setup()
    render(<StoryWorkbench />)
    await openJsonTab(user)

    const payload = {
      ...minimalStoryJson,
      id: 'from-file',
      name: 'Da file',
      ext_json: {
        Header: { Layout: 'None' as const, Title: 'Header file', Chip: null, Image: null },
        sections: [{ Kind: 'TextIntro', Text: 'sezione file' }],
      },
    }
    await user.upload(
      screen.getByLabelText(/Carica da file/i),
      new File([JSON.stringify(payload)], 'story.json', { type: 'application/json' }),
    )

    const jsonArea = screen.getByRole('textbox', { name: /Area JSON/i }) as HTMLTextAreaElement
    expect(jsonArea.value).toContain('from-file')
    await openContentsTab(user)
    const panel = screen.getByRole('tabpanel', { name: /Contenuti/i })
    expect(within(panel).getByDisplayValue('Header file')).toBeInTheDocument()
    expect(within(panel).getByText('sezione file')).toBeInTheDocument()
  })
})
