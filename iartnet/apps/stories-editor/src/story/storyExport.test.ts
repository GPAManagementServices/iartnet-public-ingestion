import { describe, expect, it } from 'vitest'
import { createDefaultStory } from './defaults'
import { serializeStoryForFile } from './jsonFilesystem'
import { parseStoryJson } from './parseStory'
import { findLegacyRichTextArrays, normalizeStoryForExport } from './storyExport'

describe('storyExport', () => {
  it('normalizeStoryForExport converte string[] legacy in string HTML', () => {
    const parsed = parseStoryJson(
      JSON.stringify({
        id: 'x',
        name: 'n',
        description: '',
        created_at: '',
        updated_at: '',
        publish_state: 'draft',
        ext_json: {
          Header: { Layout: 'None', Title: null, Chip: null, Image: null },
          sections: [
            {
              Kind: 'TextIntro',
              Text: ['<b>Titolo</b>', 'corpo'],
            },
            {
              Kind: 'IIFAnnotationsGroup',
              Image: { BaseURI: 'https://ex.test/iiif/2/x.tif' },
              Caption: ['cap', 'due'],
              Annotations: [{ Text: ['a', 'b'], Rect: { x: 0, y: 0, width: 1, height: 1 } }],
            },
          ],
        },
      }),
    )
    expect(parsed.ok).toBe(true)
    if (!parsed.ok) throw new Error(parsed.error)

    const exported = normalizeStoryForExport(parsed.value)
    expect(exported.ext_json.sections[0]).toMatchObject({
      Kind: 'TextIntro',
      Text: '<b>Titolo</b><br />corpo',
      published: true,
      animazione: { Effetto: '' },
    })
    expect(exported.ext_json.sections[1]).toMatchObject({
      Caption: 'cap<br />due',
      Annotations: [{ Text: 'a<br />b' }],
    })
    expect(findLegacyRichTextArrays(exported)).toEqual([])
  })

  it('serializeStoryForFile non contiene string[] sui campi testo ricco', () => {
    const parsed = parseStoryJson(
      JSON.stringify({
        id: 'x',
        name: 'n',
        description: '',
        created_at: '',
        updated_at: '',
        publish_state: 'draft',
        ext_json: {
          Header: { Layout: 'None', Title: null, Chip: null, Image: null },
          sections: [{ Kind: 'TextIntro', Text: ['uno', 'due'] }],
        },
      }),
    )
    expect(parsed.ok).toBe(true)
    if (!parsed.ok) throw new Error(parsed.error)

    const json = serializeStoryForFile(parsed.value)
    const roundTrip = JSON.parse(json) as ReturnType<typeof createDefaultStory>
    expect(findLegacyRichTextArrays(roundTrip)).toEqual([])
    expect(roundTrip.ext_json.sections[0]).toMatchObject({ Text: 'uno<br />due' })
  })
})
