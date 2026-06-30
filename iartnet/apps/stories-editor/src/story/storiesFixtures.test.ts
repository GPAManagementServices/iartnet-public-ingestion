import { describe, expect, it } from 'vitest'
import { parseStoryJson } from './parseStory'
import { normalizeStoryForExport } from './storyExport'

const storyFixtures = import.meta.glob<string>('../../Stories/*.json', {
  query: '?raw',
  import: 'default',
  eager: true,
})

describe('Stories/*.json fixtures', () => {
  for (const [path, text] of Object.entries(storyFixtures)) {
    const file = path.split('/').pop() ?? path

    it(`parse ${file}`, () => {
      const result = parseStoryJson(text)
      expect(result.ok).toBe(true)
      if (!result.ok) throw new Error(`${file}: ${result.error}`)
      for (const [i, sec] of result.value.ext_json.sections.entries()) {
        expect(sec.Kind, `${file} sections[${i}]`).toBeDefined()
        expect(typeof sec.published, `${file} sections[${i}].published`).toBe('boolean')
        expect(sec.animazione, `${file} sections[${i}].animazione`).toEqual(
          expect.objectContaining({ Effetto: expect.any(String) }),
        )
      }
    })

    it(`round-trip logico ${file}`, () => {
      const parsed = parseStoryJson(text)
      expect(parsed.ok).toBe(true)
      if (!parsed.ok) throw new Error(parsed.error)

      const exported = normalizeStoryForExport(parsed.value)
      const again = parseStoryJson(
        JSON.stringify({
          ...parsed.value,
          ext_json: exported.ext_json,
        }),
      )
      expect(again.ok).toBe(true)
      if (!again.ok) throw new Error(again.error)
      expect(again.value.ext_json.sections).toEqual(exported.ext_json.sections)
    })
  }
})
