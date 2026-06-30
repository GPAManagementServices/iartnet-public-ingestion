import type { TStoriesTypeData } from '../types/story'
import { normalizeStoryForExport } from './storyExport'

/** Nome file sicuro per download / suggestedName (ASCII, senza path). */
export function suggestedJsonFilename(story: TStoriesTypeData): string {
  const raw = (story.name?.trim() || story.id?.trim() || 'story').replace(/[^\w.\-]+/g, '_').replace(/_+/g, '_')
  const base = raw.replace(/^\.+|\.+$/g, '') || 'story'
  return `${base}.json`
}

export function serializeStoryForFile(story: TStoriesTypeData): string {
  return JSON.stringify(normalizeStoryForExport(story), null, 2)
}

type SavePickerWindow = Window &
  typeof globalThis & {
    showSaveFilePicker?: (options: {
      suggestedName?: string
      types?: Array<{ description: string; accept: Record<string, string[]> }>
    }) => Promise<FileSystemFileHandle>
  }

/**
 * Salva testo JSON: `showSaveFilePicker` se supportato, altrimenti `<a download>`.
 * Ignora `AbortError` (utente ha annullato il dialogo).
 */
export async function saveJsonTextToFilesystem(text: string, suggestedName: string): Promise<void> {
  const w = window as SavePickerWindow
  if (typeof w.showSaveFilePicker === 'function') {
    try {
      const handle = await w.showSaveFilePicker({
        suggestedName,
        types: [{ description: 'JSON', accept: { 'application/json': ['.json'] } }],
      })
      const writable = await handle.createWritable()
      await writable.write(new Blob([text], { type: 'application/json;charset=utf-8' }))
      await writable.close()
      return
    } catch (e) {
      if (e instanceof DOMException && e.name === 'AbortError') return
    }
  }

  const blob = new Blob([text], { type: 'application/json;charset=utf-8' })
  const url = URL.createObjectURL(blob)
  const a = document.createElement('a')
  a.href = url
  a.download = suggestedName
  a.rel = 'noopener'
  document.body.appendChild(a)
  a.click()
  a.remove()
  URL.revokeObjectURL(url)
}
