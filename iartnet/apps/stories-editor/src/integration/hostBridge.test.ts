import { afterEach, describe, expect, it, vi } from 'vitest'
import {
  STORY_EDITOR_MESSAGE_TYPES,
  STORY_EDITOR_READY_RETRY_MS,
  isEmbedMode,
  listenFromHost,
  postToHost,
  sendSave,
} from './hostBridge'
import type { TStoriesTypeData } from '../types/story'

afterEach(() => {
  vi.restoreAllMocks()
  window.history.replaceState({}, '', '/')
})

describe('hostBridge', () => {
  it('detects embed mode from query string', () => {
    window.history.replaceState({}, '', '/?embed=1')
    expect(isEmbedMode()).toBe(true)

    window.history.replaceState({}, '', '/')
    expect(isEmbedMode()).toBe(false)
  })

  it('posts READY on listenFromHost and handles INIT', () => {
    const postMessage = vi.fn()
    const parent = { postMessage } as unknown as Window
    vi.stubGlobal('parent', parent)

    const payload: TStoriesTypeData = {
      id: 'abc',
      name: 'Story',
      description: '',
      created_at: '2026-01-01T00:00:00.000Z',
      updated_at: '2026-01-01T00:00:00.000Z',
      publish_state: 'draft',
      ext_json: {
        Header: {
          Layout: 'None',
          Title: 'Titolo',
        },
        sections: [],
      },
    }

    let received: TStoriesTypeData | null = null
    const cleanup = listenFromHost((story) => {
      received = story
    })

    expect(postMessage).toHaveBeenCalledWith(
      { type: STORY_EDITOR_MESSAGE_TYPES.READY },
      window.location.origin,
    )

    window.dispatchEvent(
      new MessageEvent('message', {
        origin: window.location.origin,
        data: {
          type: STORY_EDITOR_MESSAGE_TYPES.INIT,
          payload,
        },
      }),
    )

    expect(received).toEqual(payload)
    cleanup()
  })

  it('retries READY until INIT is received', () => {
    vi.useFakeTimers()
    const postMessage = vi.fn()
    vi.stubGlobal('parent', { postMessage } as unknown as Window)

    const cleanup = listenFromHost(() => {})

    expect(postMessage).toHaveBeenCalledTimes(1)

    vi.advanceTimersByTime(STORY_EDITOR_READY_RETRY_MS)
    expect(postMessage).toHaveBeenCalledTimes(2)

    window.dispatchEvent(
      new MessageEvent('message', {
        origin: window.location.origin,
        data: {
          type: STORY_EDITOR_MESSAGE_TYPES.INIT,
          payload: {
            id: 'abc',
            name: 'Story',
            description: '',
            created_at: '2026-01-01T00:00:00.000Z',
            updated_at: '2026-01-01T00:00:00.000Z',
            publish_state: 'draft',
            ext_json: {
              Header: { Layout: 'None', Title: 'Titolo' },
              sections: [],
            },
          },
        },
      }),
    )

    vi.advanceTimersByTime(STORY_EDITOR_READY_RETRY_MS * 3)
    expect(postMessage).toHaveBeenCalledTimes(2)

    cleanup()
    vi.useRealTimers()
  })

  it('ignores duplicate INIT messages', () => {
    const postMessage = vi.fn()
    vi.stubGlobal('parent', { postMessage } as unknown as Window)

    const onInit = vi.fn()
    const cleanup = listenFromHost(onInit)

    const initMessage = {
      type: STORY_EDITOR_MESSAGE_TYPES.INIT,
      payload: {
        id: 'abc',
        name: 'Story',
        description: '',
        created_at: '2026-01-01T00:00:00.000Z',
        updated_at: '2026-01-01T00:00:00.000Z',
        publish_state: 'draft',
        ext_json: {
          Header: { Layout: 'None', Title: 'Titolo' },
          sections: [],
        },
      },
    }

    window.dispatchEvent(
      new MessageEvent('message', {
        origin: window.location.origin,
        data: initMessage,
      }),
    )
    window.dispatchEvent(
      new MessageEvent('message', {
        origin: window.location.origin,
        data: {
          ...initMessage,
          payload: { ...initMessage.payload, name: 'Changed' },
        },
      }),
    )

    expect(onInit).toHaveBeenCalledTimes(1)
    expect(onInit).toHaveBeenCalledWith(initMessage.payload)
    cleanup()
  })

  it('sendSave posts ext_json payload to parent', () => {
    const postMessage = vi.fn()
    vi.stubGlobal('parent', { postMessage } as unknown as Window)

    sendSave(
      {
        Header: { Layout: 'None', Title: 'A' },
        sections: [],
      },
      '2026-01-02T00:00:00.000Z',
    )

    expect(postMessage).toHaveBeenCalledWith(
      {
        type: STORY_EDITOR_MESSAGE_TYPES.SAVE,
        payload: {
          ext_json: {
            Header: { Layout: 'None', Title: 'A' },
            sections: [],
          },
          updated_at: '2026-01-02T00:00:00.000Z',
        },
      },
      window.location.origin,
    )
  })

  it('postToHost is a no-op outside iframe', () => {
    vi.stubGlobal('parent', window)
    const postMessage = vi.spyOn(window, 'postMessage')

    postToHost({ type: STORY_EDITOR_MESSAGE_TYPES.CANCEL })

    expect(postMessage).not.toHaveBeenCalled()
  })
})
