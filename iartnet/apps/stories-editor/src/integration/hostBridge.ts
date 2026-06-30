import type { TStoriesExtJson, TStoriesTypeData } from '../types/story'

export const STORY_EDITOR_MESSAGE_TYPES = {
  INIT: 'STORY_EDITOR_INIT',
  SAVE: 'STORY_EDITOR_SAVE',
  READY: 'STORY_EDITOR_READY',
  CANCEL: 'STORY_EDITOR_CANCEL',
  DIRTY: 'STORY_EDITOR_DIRTY',
} as const

export const STORY_EDITOR_READY_RETRY_MS = 500

export type StoryEditorInitMessage = {
  type: typeof STORY_EDITOR_MESSAGE_TYPES.INIT
  payload: TStoriesTypeData
}

export type StoryEditorSaveMessage = {
  type: typeof STORY_EDITOR_MESSAGE_TYPES.SAVE
  payload: { ext_json: TStoriesExtJson; updated_at?: string }
}

export type StoryEditorDirtyMessage = {
  type: typeof STORY_EDITOR_MESSAGE_TYPES.DIRTY
  dirty: boolean
}

export function isEmbedMode(): boolean {
  return new URLSearchParams(window.location.search).get('embed') === '1'
}

function allowedOrigin(): string {
  return window.location.origin
}

export function postToHost(message: object): void {
  if (window.parent === window) {
    return
  }
  window.parent.postMessage(message, allowedOrigin())
}

export function listenFromHost(onInit: (payload: TStoriesTypeData) => void): () => void {
  let initialized = false
  let retryTimer: ReturnType<typeof setInterval> | null = null

  const handler = (event: MessageEvent) => {
    if (event.origin !== allowedOrigin()) {
      return
    }
    const data = event.data
    if (!data || typeof data !== 'object') {
      return
    }
    if (data.type === STORY_EDITOR_MESSAGE_TYPES.INIT && !initialized) {
      initialized = true
      if (retryTimer !== null) {
        clearInterval(retryTimer)
        retryTimer = null
      }
      onInit(data.payload as TStoriesTypeData)
    }
  }

  const postReady = () => {
    if (initialized) {
      return
    }
    postToHost({ type: STORY_EDITOR_MESSAGE_TYPES.READY })
  }

  window.addEventListener('message', handler)
  postReady()
  retryTimer = setInterval(postReady, STORY_EDITOR_READY_RETRY_MS)

  return () => {
    window.removeEventListener('message', handler)
    if (retryTimer !== null) {
      clearInterval(retryTimer)
    }
  }
}

export function sendSave(ext_json: TStoriesExtJson, updated_at?: string): void {
  postToHost({
    type: STORY_EDITOR_MESSAGE_TYPES.SAVE,
    payload: { ext_json, updated_at },
  })
}

export function sendDirty(dirty: boolean): void {
  postToHost({
    type: STORY_EDITOR_MESSAGE_TYPES.DIRTY,
    dirty,
  })
}

export function sendCancel(): void {
  postToHost({ type: STORY_EDITOR_MESSAGE_TYPES.CANCEL })
}
