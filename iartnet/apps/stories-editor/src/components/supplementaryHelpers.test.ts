import { describe, expect, it } from 'vitest'
import type { TStoriesExtJson } from '../types/story'
import { createDefaultExtJson } from '../story/defaults'
import { supplementaryMerge } from './supplementaryHelpers'

describe('supplementaryMerge', () => {
  it('aggiorna solo i campi forniti', () => {
    const base = createDefaultExtJson()
    base.bibliography = [{ Title: 'a', Description: '' }]
    const partial: Partial<TStoriesExtJson> = {
      sitography: [{ Title: 's', Description: 'd' }],
    }
    const next = supplementaryMerge(base, partial)
    expect(next.bibliography?.[0]?.Title).toBe('a')
    expect(next.sitography?.[0]?.Title).toBe('s')
    expect(next.sections).toEqual(base.sections)
    expect(next.Header).toEqual(base.Header)
  })
})
