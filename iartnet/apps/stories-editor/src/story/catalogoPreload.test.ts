import { describe, expect, it } from 'vitest'
import { catalogoItemsFromSections } from './catalogoPreload'

describe('catalogoItemsFromSections', () => {
  const BASE = { published: true, animazione: { Effetto: '' } }

  it('ignora sezioni senza Image', () => {
    expect(
      catalogoItemsFromSections([
        { Kind: 'TextIntro', Text: 'intro', ...BASE },
        { Kind: 'SplitContent', LeftText: 'a', RightText: 'b', ...BASE },
      ]),
    ).toEqual([])
  })

  it('copia URL e LinkScheda URL da ogni sezione con immagine', () => {
    const items = catalogoItemsFromSections([
      {
        Kind: 'SplitImage',
        ...BASE,
        Layout: 'Right',
        Text: 'testo',
        Image: { URL: 'https://img.test/1.jpg', Caption: 'Didascalia 1' },
        LinkScheda: { Layout: 'TopLeft', URL: 'https://scheda.test/1' },
        MediaType: 'Image',
      },
      {
        Kind: 'InlineImage',
        ...BASE,
        Image: { URL: 'https://img.test/2.jpg' },
        LinkScheda: { Layout: 'TopRight', URL: 'https://scheda.test/2' },
      },
      {
        Kind: 'ImageFullScreen',
        ...BASE,
        Position: 'BottomLeft',
        Fit: 'Cover',
        Image: { URL: 'https://img.test/3.jpg', Caption: null },
        LinkScheda: { Layout: 'TopLeft', URL: '' },
      },
    ])

    expect(items).toHaveLength(3)
    expect(items[0]).toEqual({
      Image: { URL: 'https://img.test/1.jpg' },
      Title: '',
      Author: '',
      Tags: [],
      LinkScheda: { Layout: 'TopLeft', URL: 'https://scheda.test/1' },
    })
    expect(items[1]).toEqual({
      Image: { URL: 'https://img.test/2.jpg' },
      Title: '',
      Author: '',
      Tags: [],
      LinkScheda: { Layout: 'TopLeft', URL: 'https://scheda.test/2' },
    })
    expect(items[2]).toEqual({
      Image: { URL: 'https://img.test/3.jpg' },
      Title: '',
      Author: '',
      Tags: [],
      LinkScheda: { Layout: 'TopLeft', URL: '' },
    })
  })
})
