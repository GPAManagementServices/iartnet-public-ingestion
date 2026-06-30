import { describe, expect, it } from 'vitest'
import { createEmptyIIFAnnotationsGroup, type TStoryIIFAnnotationsGroupType } from '../types/story'
import {
  buildIiifImageUrl,
  extractIiifBaseUri,
  iiifDeliveryUrl,
  iiifInfoJsonUrl,
  iiifPreviewUrl,
  iiifPreviewUrlForZoom,
  IIIF_IMAGE_URL_DEFAULTS,
  normalizeIIFImage,
  normalizeIIFAnnotationsGroup,
  parseIiifImageUrl,
} from './iiifImage'

describe('iiifImage', () => {
  it('extractIiifBaseUri da URL completo gpams', () => {
    const url =
      'https://iiif.gpams.it/iiif/2/8c5b2439-803d-4426-9c84-94c878e6b63b.tif/full/max/0/default.jpg'
    expect(extractIiifBaseUri(url)).toBe(
      'https://iiif.gpams.it/iiif/2/8c5b2439-803d-4426-9c84-94c878e6b63b.tif',
    )
  })

  it('extractIiifBaseUri restituisce null per stringa vuota', () => {
    expect(extractIiifBaseUri('')).toBeNull()
  })

  it('iiifInfoJsonUrl e iiifPreviewUrl derivano dal base', () => {
    const base = 'https://iiif.gpams.it/iiif/2/uuid.tif'
    expect(iiifInfoJsonUrl(base)).toBe(`${base}/info.json`)
    expect(iiifPreviewUrl(base)).toBe(`${base}/full/800,/0/default.jpg`)
  })

  it('normalizeIIFImage da BaseURI esplicito', () => {
    expect(
      normalizeIIFImage({
        BaseURI: 'https://ex.test/iiif/2/a.tif',
        Width: 5153,
        Height: 7064,
      }),
    ).toEqual({
      BaseURI: 'https://ex.test/iiif/2/a.tif',
      Width: 5153,
      Height: 7064,
      bgColor: null,
    })
  })

  it('normalizeIIFImage da URL legacy', () => {
    expect(
      normalizeIIFImage({
        URL: 'https://iiif.gpams.it/iiif/2/uuid.jpg/full/max/0/default.jpg',
        Caption: 'ignorata',
        bgColor: '#fff',
      }),
    ).toEqual({
      BaseURI: 'https://iiif.gpams.it/iiif/2/uuid.jpg',
      Width: null,
      Height: null,
      bgColor: '#fff',
    })
  })

  it('normalizeIIFImage ignora dimensioni non valide', () => {
    expect(normalizeIIFImage({ BaseURI: 'x', Width: -1, Height: 'bad' })).toEqual({
      BaseURI: 'x',
      Width: null,
      Height: null,
      bgColor: null,
    })
  })

  it('normalizeIIFImage normalizza bgColor vuoto a null', () => {
    expect(
      normalizeIIFImage({ BaseURI: 'x', bgColor: '  ' }),
    ).toEqual({
      BaseURI: 'x',
      Width: null,
      Height: null,
      bgColor: null,
    })
  })

  it('normalizeIIFAnnotationsGroup normalizza Caption multiriga in HTML', () => {
    expect(
      normalizeIIFAnnotationsGroup({
        ...createEmptyIIFAnnotationsGroup(),
        Image: { BaseURI: 'https://ex.test/iiif/2/a.tif', Width: null, Height: null },
        Caption: 'riga 1\nriga 2',
        Annotations: [],
      }),
    ).toEqual({
      ...createEmptyIIFAnnotationsGroup(),
      Image: { BaseURI: 'https://ex.test/iiif/2/a.tif', Width: null, Height: null, bgColor: null },
      Caption: 'riga 1<br />riga 2',
      Annotations: [],
    })
  })

  it('normalizeIIFAnnotationsGroup normalizza Caption legacy string[]', () => {
    const section: TStoryIIFAnnotationsGroupType = {
      ...createEmptyIIFAnnotationsGroup(),
      Image: { BaseURI: 'https://ex.test/iiif/2/a.tif', Width: null, Height: null },
      Caption: ['<b>Titolo</b>', 'Testo'] as unknown as string,
      Annotations: [],
    }
    expect(normalizeIIFAnnotationsGroup(section)).toEqual({
      ...createEmptyIIFAnnotationsGroup(),
      Image: { BaseURI: 'https://ex.test/iiif/2/a.tif', Width: null, Height: null, bgColor: null },
      Caption: '<b>Titolo</b><br />Testo',
      Annotations: [],
    })
  })

  it('iiifPreviewUrlForZoom aumenta risoluzione con zoom', () => {
    const base = 'https://iiif.gpams.it/iiif/2/uuid.tif'
    const bounds = { width: 5153, height: 7064 }
    expect(iiifPreviewUrlForZoom(base, bounds, 400, 1)).toBe(`${base}/full/800,/0/default.jpg`)
    expect(iiifPreviewUrlForZoom(base, bounds, 400, 2)).toBe(`${base}/full/800,/0/default.jpg`)
    expect(iiifPreviewUrlForZoom(base, bounds, 400, 3)).toBe(`${base}/full/1200,/0/default.jpg`)
  })

  it('parseIiifImageUrl da URL header con regione pixel e size per altezza', () => {
    const url =
      'https://iiif.gpams.it/iiif/2/7374bafe-152c-463a-b5b5-2f518b1c5e8a.tif/0,675,4961,2300/,1000/0/default.jpg'
    expect(parseIiifImageUrl(url)).toEqual({
      baseUri: 'https://iiif.gpams.it/iiif/2/7374bafe-152c-463a-b5b5-2f518b1c5e8a.tif',
      region: { x: 0, y: 675, width: 4961, height: 2300 },
      size: { width: null, height: 1000 },
      rotation: 0,
      quality: 'default',
      format: 'jpg',
    })
  })

  it('parseIiifImageUrl da URL full con larghezza', () => {
    const url =
      'https://iiif.gpams.it/iiif/2/b83103f7-0c4b-471e-872d-04b614d0ed28.tif/full/1400,/0/default.jpg'
    expect(parseIiifImageUrl(url)).toEqual({
      baseUri: 'https://iiif.gpams.it/iiif/2/b83103f7-0c4b-471e-872d-04b614d0ed28.tif',
      region: 'full',
      size: { width: 1400, height: null },
      rotation: 0,
      quality: 'default',
      format: 'jpg',
    })
  })

  it('parseIiifImageUrl da URL full/max', () => {
    const url = 'https://iiif.gpams.it/iiif/2/uuid.jpg/full/max/0/default.jpg'
    expect(parseIiifImageUrl(url)).toEqual({
      baseUri: 'https://iiif.gpams.it/iiif/2/uuid.jpg',
      region: 'full',
      size: { width: null, height: null, keyword: 'max' },
      rotation: 0,
      quality: 'default',
      format: 'jpg',
    })
  })

  it('parseIiifImageUrl restituisce null per URL non-IIIF', () => {
    expect(parseIiifImageUrl('https://cdn.test/photo.png')).toBeNull()
    expect(parseIiifImageUrl('')).toBeNull()
  })

  it('buildIiifImageUrl round-trip con story reali', () => {
    const samples = [
      'https://iiif.gpams.it/iiif/2/7374bafe-152c-463a-b5b5-2f518b1c5e8a.tif/0,675,4961,2300/,1000/0/default.jpg',
      'https://iiif.gpams.it/iiif/2/b83103f7-0c4b-471e-872d-04b614d0ed28.tif/full/1400,/0/default.jpg',
      'https://iiif.gpams.it/iiif/2/b83103f7-0c4b-471e-872d-04b614d0ed28.tif/0,3900,5265,2100/,620/0/default.jpg',
      'https://iiif.gpams.it/iiif/2/69101eac-e4b0-47f1-b94b-fa540acae1ef.jpg/1000,0,3300,8166/800,/0/default.jpg',
    ]
    for (const url of samples) {
      const parts = parseIiifImageUrl(url)
      expect(parts).not.toBeNull()
      expect(buildIiifImageUrl(parts!)).toBe(url)
    }
  })

  it('buildIiifImageUrl compone regione e dimensioni esplicite', () => {
    expect(
      buildIiifImageUrl({
        baseUri: 'https://iiif.gpams.it/iiif/2/uuid.tif',
        region: { x: 10, y: 20, width: 300, height: 400 },
        size: { width: 800, height: 600 },
        ...IIIF_IMAGE_URL_DEFAULTS,
      }),
    ).toBe('https://iiif.gpams.it/iiif/2/uuid.tif/10,20,300,400/800,600/0/default.jpg')
  })

  it('iiifDeliveryUrl limita la dimensione di anteprima', () => {
    const parts = parseIiifImageUrl(
      'https://iiif.gpams.it/iiif/2/uuid.tif/0,675,4961,2300/,1000/0/default.jpg',
    )!
    expect(iiifDeliveryUrl(parts, 400)).toBe(
      'https://iiif.gpams.it/iiif/2/uuid.tif/0,675,4961,2300/,400/0/default.jpg',
    )
  })
})
