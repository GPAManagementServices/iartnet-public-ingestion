import { describe, expect, it, vi } from 'vitest'
import { createDefaultStory } from './defaults'
import { saveJsonTextToFilesystem, suggestedJsonFilename } from './jsonFilesystem'

describe('suggestedJsonFilename', () => {
  it('usa name, poi id, poi story.json', () => {
    const s = createDefaultStory()
    s.name = 'La mia story!'
    s.id = 'id1'
    expect(suggestedJsonFilename(s)).toMatch(/^La_mia_story.*\.json$/)

    s.name = ''
    expect(suggestedJsonFilename(s)).toBe('id1.json')

    s.id = ''
    expect(suggestedJsonFilename(s)).toBe('story.json')
  })
})

describe('saveJsonTextToFilesystem', () => {
  type WindowWithPicker = Window & {
    showSaveFilePicker?: (...args: unknown[]) => Promise<{ createWritable: () => Promise<unknown> }>
  }

  it('con showSaveFilePicker scrive il file scelto', async () => {
    const write = vi.fn().mockResolvedValue(undefined)
    const close = vi.fn().mockResolvedValue(undefined)
    const createWritable = vi.fn().mockResolvedValue({ write, close })

    const picker = vi.fn().mockResolvedValue({ createWritable })
    const win = window as WindowWithPicker
    const prev = win.showSaveFilePicker
    win.showSaveFilePicker = picker as unknown as WindowWithPicker['showSaveFilePicker']

    try {
      await saveJsonTextToFilesystem('{"ok":true}', 'out.json')
      expect(picker).toHaveBeenCalledWith(expect.objectContaining({ suggestedName: 'out.json' }))
      expect(write).toHaveBeenCalled()
      expect(close).toHaveBeenCalled()
    } finally {
      if (prev) win.showSaveFilePicker = prev
      else delete win.showSaveFilePicker
    }
  })

  it('senza showSaveFilePicker avvia il download classico', async () => {
    const win = window as WindowWithPicker
    const prev = win.showSaveFilePicker
    delete win.showSaveFilePicker
    const revoke = vi.spyOn(URL, 'revokeObjectURL').mockImplementation(() => {})

    try {
      await saveJsonTextToFilesystem('{"k":true}', 'down.json')
      expect(revoke).toHaveBeenCalled()
    } finally {
      revoke.mockRestore()
      if (prev) win.showSaveFilePicker = prev
    }
  })
})
