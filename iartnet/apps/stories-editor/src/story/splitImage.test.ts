import { describe, expect, it } from 'vitest'
import { parseSplitMediaType, type TStorySplitImageType } from '../types/story'
import { createEmptySection } from './sectionKind'
import { normalizeSplitImage } from './splitImage'

describe('splitImage', () => {
  it('parseSplitMediaType default Image', () => {
    expect(parseSplitMediaType(undefined)).toBe('Image')
    expect(parseSplitMediaType(null)).toBe('Image')
    expect(parseSplitMediaType('')).toBe('Image')
    expect(parseSplitMediaType('Audio')).toBe('Image')
  })

  it('parseSplitMediaType accetta Video', () => {
    expect(parseSplitMediaType('Video')).toBe('Video')
    expect(parseSplitMediaType('Image')).toBe('Image')
  })

  it('normalizeSplitImage imposta Image se assente', () => {
    const section = createEmptySection('SplitImage') as TStorySplitImageType
    section.Layout = 'Right'
    section.Text = 't'
    section.Image = { URL: 'u' }
    expect(normalizeSplitImage(section)).toEqual({
      ...section,
      MediaType: 'Image',
    })
  })

  it('normalizeSplitImage preserva Video', () => {
    const section = createEmptySection('SplitImage') as TStorySplitImageType
    section.Layout = 'Left'
    section.Text = 't'
    section.Image = { URL: 'https://video.test/v.mp4' }
    section.MediaType = 'Video'
    expect(normalizeSplitImage(section)).toMatchObject({ MediaType: 'Video' })
  })
})
