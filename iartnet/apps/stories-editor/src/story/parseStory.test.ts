import { describe, expect, it } from 'vitest'
import {
  createDefaultExtJson,
  createDefaultStory,
  DEFAULT_HEADER_FONT_COLOR,
} from './defaults'
import { parseExtJsonString, parseStoryJson } from './parseStory'

const emptyRoot = {
  id: '',
  name: '',
  description: '',
  created_at: '',
  updated_at: '',
  publish_state: '',
}

function storyJson(ext_json: unknown) {
  return JSON.stringify({ ...emptyRoot, ext_json })
}

function expectParseFails(text: string, message: RegExp) {
  const result = parseStoryJson(text)
  expect(result.ok).toBe(false)
  if (!result.ok) expect(result.error).toMatch(message)
}

const SECTION_BASE = { published: true, animazione: { Effetto: '' } }

function sampleStory() {
  const s = createDefaultStory()
  s.id = 's1'
  s.name = 'Nome'
  s.ext_json.Header.Layout = 'ImageRight'
  s.ext_json.Header.Title = 'Titolo'
  s.ext_json.Header.SubTitle = 'Sottotitolo'
  s.ext_json.Header.SEO = { slug: 'mozart-and-the-magic-flute' }
  s.ext_json.Header.Image = { URL: 'https://ex.test/a.png' }
  s.ext_json.Header.IndexImage = { URL: 'https://ex.test/index.png' }
  s.ext_json.Header.HeaderLayoutTheme = 'Light'
  s.ext_json.sections = [
    { Kind: 'TextIntro', Text: 'intro', ...SECTION_BASE },
    {
      Kind: 'SplitImage',
      Layout: 'Right',
      Text: 'body',
      LinkScheda: { Layout: 'TopLeft', URL: 'info' },
      Image: { URL: 'https://ex.test/b.png' },
      MediaType: 'Image',
      ...SECTION_BASE,
    },
  ]
  s.ext_json.bibliography = [{ Title: 'Bib', Description: 'Desc' }]
  return s
}

describe('parseStoryJson', () => {
  it('rifiuta JSON invalido', () => {
    expectParseFails('{', /JSON/)
  })

  it('rifiuta sezione senza Kind in sections', () => {
    expectParseFails(
      storyJson({ Header: { Layout: 'None' }, sections: [{ foo: 1 }] }),
      /Kind mancante/i,
    )
  })

  it('rifiuta Kind non valido', () => {
    expectParseFails(
      storyJson({
        Header: { Layout: 'None' },
        sections: [{ Kind: 'NotAKind', Text: 'x' }],
      }),
      /Kind non valido/i,
    )
  })

  it('accetta Kind InlineText e lo conserva nel parse', () => {
    const result = parseStoryJson(
      storyJson({
        Header: { Layout: 'None', Title: null, Chip: null, Image: null },
        sections: [{ Kind: 'InlineText', Text: 'inline' }],
      }),
    )
    expect(result.ok).toBe(true)
    if (!result.ok) throw new Error(result.error)
    expect(result.value.ext_json.sections[0]).toEqual({
      Kind: 'InlineText',
      Text: 'inline',
      ...SECTION_BASE,
    })
  })

  it('Header senza FontColor ottiene il default', () => {
    const result = parseStoryJson(
      storyJson({
        Header: { Layout: 'None', Title: null, Chip: null, Image: null },
        sections: [],
      }),
    )
    expect(result.ok).toBe(true)
    if (!result.ok) throw new Error(result.error)
    expect(result.value.ext_json.Header.FontColor).toBe(DEFAULT_HEADER_FONT_COLOR)
  })

  it('Header senza HeaderLayoutTheme ottiene Light', () => {
    const result = parseStoryJson(
      storyJson({
        Header: { Layout: 'None', Title: null, Chip: null, Image: null },
        sections: [],
      }),
    )
    expect(result.ok).toBe(true)
    if (!result.ok) throw new Error(result.error)
    expect(result.value.ext_json.Header.HeaderLayoutTheme).toBe('Light')
  })

  it('Header con HeaderLayoutTheme Dark viene conservato', () => {
    const result = parseStoryJson(
      storyJson({
        Header: {
          Layout: 'None',
          Title: null,
          Chip: null,
          Image: null,
          HeaderLayoutTheme: 'Dark',
        },
        sections: [],
      }),
    )
    expect(result.ok).toBe(true)
    if (!result.ok) throw new Error(result.error)
    expect(result.value.ext_json.Header.HeaderLayoutTheme).toBe('Dark')
  })

  it('accetta story completa (stesso contenuto dopo parse)', () => {
    const original = sampleStory()
    const result = parseStoryJson(JSON.stringify(original))
    expect(result.ok).toBe(true)
    if (!result.ok) throw new Error(result.error)
    expect(result.value.id).toBe(original.id)
    expect(result.value.name).toBe(original.name)
    expect(result.value.ext_json.sections).toEqual(original.ext_json.sections)
    expect(result.value.ext_json.Header.IndexImage).toEqual(original.ext_json.Header.IndexImage)
    expect(result.value.ext_json.Header.SubTitle).toBe('Sottotitolo')
    expect(result.value.ext_json.Header.SEO).toEqual({ slug: 'mozart-and-the-magic-flute' })
  })

  it('Header con SEO slug viene parsato e conservato', () => {
    const result = parseStoryJson(
      storyJson({
        Header: {
          Layout: 'None',
          Title: null,
          Chip: null,
          Image: null,
          SEO: { slug: 'canonical-slug' },
        },
        sections: [],
      }),
    )
    expect(result.ok).toBe(true)
    if (!result.ok) throw new Error(result.error)
    expect(result.value.ext_json.Header.SEO).toEqual({ slug: 'canonical-slug' })
  })

  it('Header con SEO.URL legacy viene normalizzato in slug', () => {
    const result = parseStoryJson(
      storyJson({
        Header: {
          Layout: 'None',
          Title: null,
          Chip: null,
          Image: null,
          SEO: { URL: 'https://ex.test/stories/legacy-slug' },
        },
        sections: [],
      }),
    )
    expect(result.ok).toBe(true)
    if (!result.ok) throw new Error(result.error)
    expect(result.value.ext_json.Header.SEO).toEqual({ slug: 'legacy-slug' })
  })

  it('Header con SEO slug vuoto diventa null', () => {
    const result = parseStoryJson(
      storyJson({
        Header: {
          Layout: 'None',
          Title: null,
          Chip: null,
          Image: null,
          SEO: { slug: '   ' },
        },
        sections: [],
      }),
    )
    expect(result.ok).toBe(true)
    if (!result.ok) throw new Error(result.error)
    expect(result.value.ext_json.Header.SEO).toBeNull()
  })

  it('Header con SubTitle viene parsato e conservato', () => {
    const result = parseStoryJson(
      storyJson({
        Header: {
          Layout: 'None',
          Title: null,
          SubTitle: 'Un sottotitolo',
          Chip: null,
          Image: null,
        },
        sections: [],
      }),
    )
    expect(result.ok).toBe(true)
    if (!result.ok) throw new Error(result.error)
    expect(result.value.ext_json.Header.SubTitle).toBe('Un sottotitolo')
  })

  it('Header con IndexImage viene parsato e conservato', () => {
    const result = parseStoryJson(
      storyJson({
        Header: {
          Layout: 'None',
          Title: null,
          Chip: null,
          Image: null,
          IndexImage: { URL: 'https://ex.test/index.png', Caption: 'thumb' },
        },
        sections: [],
      }),
    )
    expect(result.ok).toBe(true)
    if (!result.ok) throw new Error(result.error)
    expect(result.value.ext_json.Header.IndexImage).toEqual({
      URL: 'https://ex.test/index.png',
      Caption: 'thumb',
    })
  })

  it('accetta sezione IIFAnnotationsGroup con Kind e annotazioni', () => {
    const section = {
      Kind: 'IIFAnnotationsGroup',
      Image: {
        BaseURI: 'https://iiif.gpams.it/iiif/2/uuid.tif',
        Width: 5153,
        Height: 7064,
        bgColor: null,
      },
      Caption: null,
      Annotations: [
        { Text: 'nota', Rect: { x: 10, y: 20, width: 30, height: 40 } },
        { Text: ['riga 1', 'riga 2'], Rect: { x: 0, y: 0, width: 100, height: 50 } },
      ],
    }
    const result = parseStoryJson(
      storyJson({
        Header: { Layout: 'None', Title: null, Chip: null, Image: null },
        sections: [section],
      }),
    )
    expect(result.ok).toBe(true)
    if (!result.ok) throw new Error(result.error)
    expect(result.value.ext_json.sections[0]).toEqual({
      Kind: 'IIFAnnotationsGroup',
      ...SECTION_BASE,
      Image: {
        BaseURI: 'https://iiif.gpams.it/iiif/2/uuid.tif',
        Width: 5153,
        Height: 7064,
        bgColor: null,
      },
      Caption: null,
      Annotations: [
        { Text: 'nota', Rect: { x: 10, y: 20, width: 30, height: 40 } },
        { Text: 'riga 1<br />riga 2', Rect: { x: 0, y: 0, width: 100, height: 50 } },
      ],
    })
  })

  it('normalizza Image.URL legacy in BaseURI su import', () => {
    const result = parseStoryJson(
      storyJson({
        Header: { Layout: 'None', Title: null, Chip: null, Image: null },
        sections: [
          {
            Kind: 'IIFAnnotationsGroup',
            Image: {
              URL: 'https://iiif.gpams.it/iiif/2/uuid.jpg/full/max/0/default.jpg',
            },
            Annotations: [],
          },
        ],
      }),
    )
    expect(result.ok).toBe(true)
    if (!result.ok) throw new Error(result.error)
    expect(result.value.ext_json.sections[0]).toEqual({
      Kind: 'IIFAnnotationsGroup',
      ...SECTION_BASE,
      Image: {
        BaseURI: 'https://iiif.gpams.it/iiif/2/uuid.jpg',
        Width: null,
        Height: null,
        bgColor: null,
      },
      Caption: null,
      Annotations: [],
    })
  })

  it('rifiuta sezione IIFAnnotationsGroup senza Kind', () => {
    expectParseFails(
      storyJson({
        Header: { Layout: 'None', Title: null, Chip: null, Image: null },
        sections: [
          {
            Image: { BaseURI: 'https://ex.test/iiif/2/iif.jpg' },
            Annotations: [{ Text: 'solo', Rect: { x: 1, y: 2, width: 3, height: 4 } }],
          },
        ],
      }),
      /Kind mancante/i,
    )
  })

  it('applica default published e animazione su sezioni legacy', () => {
    const result = parseStoryJson(
      storyJson({
        Header: { Layout: 'None', Title: null, Chip: null, Image: null },
        sections: [{ Kind: 'TextIntro', Text: 'legacy' }],
      }),
    )
    expect(result.ok).toBe(true)
    if (!result.ok) throw new Error(result.error)
    expect(result.value.ext_json.sections[0]).toMatchObject(SECTION_BASE)
  })

  it('accetta catalogo opere citate opzionale', () => {
    const ext = createDefaultExtJson()
    ext.catalogoOpereCitate = [
      {
        Image: { URL: 'u' },
        Title: 't',
        Author: 'a',
        Tags: ['x'],
        LinkScheda: { Layout: 'TopLeft', URL: 'i' },
      },
    ]
    const result = parseStoryJson(storyJson(ext))
    expect(result.ok).toBe(true)
  })

  it('rifiuta radice non oggetto', () => {
    expectParseFails('[]', /oggetto/)
  })

  it('rifiuta ext_json null', () => {
    expectParseFails(JSON.stringify({ ...emptyRoot, ext_json: null }), /ext_json/)
  })

  it('rifiuta bibliography non array', () => {
    expectParseFails(
      storyJson({
        Header: { Layout: 'None', Title: null, Chip: null, Image: null },
        sections: [],
        bibliography: {},
      }),
      /bibliography/,
    )
  })

  it('rifiuta sitography non array', () => {
    expectParseFails(
      storyJson({
        Header: { Layout: 'None', Title: null, Chip: null, Image: null },
        sections: [],
        sitography: 'bad',
      }),
      /sitography/,
    )
  })

  it('rifiuta credits non array', () => {
    expectParseFails(
      storyJson({
        Header: { Layout: 'None', Title: null, Chip: null, Image: null },
        sections: [],
        credits: 1,
      }),
      /credits/,
    )
  })

  it('rifiuta catalogoOpereCitate non array', () => {
    expectParseFails(
      storyJson({
        Header: { Layout: 'None', Title: null, Chip: null, Image: null },
        sections: [],
        catalogoOpereCitate: null,
      }),
      /catalogoOpereCitate/,
    )
  })
})

describe('parseExtJsonString', () => {
  it('parsa ext_json con liste opzionali', () => {
    const ext = sampleStory().ext_json
    const result = parseExtJsonString(JSON.stringify(ext))
    expect(result.ok).toBe(true)
    if (!result.ok) throw new Error(result.error)
    expect(result.value.ext_json).toEqual(ext)
  })

  it('rifiuta header layout invalido', () => {
    const result = parseExtJsonString(
      JSON.stringify({
        Header: { Layout: 'Invalid' },
        sections: [],
      }),
    )
    expect(result.ok).toBe(false)
  })

  it('accetta i layout ImageBackground Text Left/Right', () => {
    for (const Layout of ['ImageBackground Text Left', 'ImageBackground Text Right'] as const) {
      const result = parseExtJsonString(
        JSON.stringify({
          Header: { Layout, Title: null, Chip: null, Image: null },
          sections: [],
        }),
      )
      expect(result.ok).toBe(true)
      if (!result.ok) throw new Error(result.error)
      expect(result.value.ext_json.Header.Layout).toBe(Layout)
    }
  })

  it('normalizza il layout legacy ImageBackground', () => {
    const result = parseExtJsonString(
      JSON.stringify({
        Header: { Layout: 'ImageBackground', Title: null, Chip: null, Image: null },
        sections: [],
      }),
    )
    expect(result.ok).toBe(true)
    if (!result.ok) throw new Error(result.error)
    expect(result.value.ext_json.Header.Layout).toBe('ImageBackground Text Left')
  })
})
