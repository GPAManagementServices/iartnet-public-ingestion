import type {
  TStoriesExtJson,
  TStoriesTypeData,
  TStoryHeaderLayoutTheme,
  TStoryHeaderSEOType,
  TStoryImageType,
} from '../types/story'
import { parseHeaderLayoutTheme } from '../types/story'

export const DEFAULT_HEADER_FONT_COLOR = 'rgba(0, 0, 0, 1)'

export function resolveHeaderFontColor(value?: string | null): string {
  const trimmed = value?.trim()
  return trimmed ? trimmed : DEFAULT_HEADER_FONT_COLOR
}

export function resolveHeaderLayoutTheme(
  value?: TStoryHeaderLayoutTheme | null,
): TStoryHeaderLayoutTheme {
  return parseHeaderLayoutTheme(value)
}

export function isStoryImageEmpty(image?: TStoryImageType | null): boolean {
  return !image?.URL?.trim()
}

export function isHeaderSeoEmpty(seo?: TStoryHeaderSEOType | null): boolean {
  return !seo?.slug?.trim()
}

export function createDefaultExtJson(): TStoriesExtJson {
  return {
    Header: {
      Layout: 'None',
      Title: '',
      SubTitle: null,
      SEO: null,
      FontColor: DEFAULT_HEADER_FONT_COLOR,
      Chip: '',
      Image: null,
      IndexImage: null,
      HeaderLayoutTheme: 'Light',
    },
    sections: [],
  }
}

export function createDefaultStory(): TStoriesTypeData {
  const now = new Date().toISOString()
  return {
    id: '',
    name: '',
    description: '',
    created_at: now,
    updated_at: now,
    publish_state: 'draft',
    ext_json: createDefaultExtJson(),
  }
}
