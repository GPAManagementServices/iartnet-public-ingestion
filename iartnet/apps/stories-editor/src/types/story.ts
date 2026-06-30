// =============================================================================
// Tipi — copiabili in altri progetti (solo questa sezione)
// =============================================================================

export interface TStoryLinkSchedaType {
  Layout: 'TopLeft' | 'TopRight'
  URL: string
}

export interface TStoryImageType {
  URL: string
  Caption?: string | null
  bgColor?: string | null
}

export const STORY_HEADER_LAYOUTS = [
  'ImageRight',
  'ImageLeft',
  'ImageBackground Text Left',
  'ImageBackground Text Right',
  'None',
] as const

export type TStoryHeaderLayout = (typeof STORY_HEADER_LAYOUTS)[number]

export const STORY_HEADER_LAYOUT_THEMES = ['Light', 'Dark'] as const

export type TStoryHeaderLayoutTheme = (typeof STORY_HEADER_LAYOUT_THEMES)[number]

export interface TStoryHeaderSEOType {
  slug: string
}

export interface TStoryHeaderType {
  Layout: TStoryHeaderLayout
  Title?: string | null
  SubTitle?: string | null
  SEO?: TStoryHeaderSEOType | null
  FontColor?: string | null
  Chip?: string | null
  Image?: TStoryImageType | null
  IndexImage?: TStoryImageType | null
  HeaderLayoutTheme?: TStoryHeaderLayoutTheme | null
}

/** Kind per UI: TextIntro e InlineText hanno stessa forma JSON ({ Text }). */
export type SectionKind =
  | 'TextIntro'
  | 'InlineText'
  | 'SplitContent'
  | 'SplitImage'
  | 'ScrollReveal'
  | 'InlineImage'
  | 'ImageFullScreen'
  | 'IIFAnnotationsGroup'

export interface TStoryAnimazione {
  Effetto: string
}

export interface TStorySectionBase {
  Kind: SectionKind
  published?: boolean
  animazione: TStoryAnimazione
  foreColor?: string | null
  bgColor?: string | null
  bgImage?: TStoryImageType | null
}

export interface TStoryTextIntroType extends TStorySectionBase {
  Kind: 'TextIntro'
  /** HTML (normalizzato in import da string[] legacy o plain text con `\n`). */
  Text: string
}

export interface TStoryInlineTextType extends TStorySectionBase {
  Kind: 'InlineText'
  Text: string
}

export interface TStorySplitContentType extends TStorySectionBase {
  Kind: 'SplitContent'
  LeftText: string
  RightText: string
}

export const STORY_SPLIT_MEDIA_TYPES = ['Image', 'Video'] as const

export type TStorySplitMediaType = (typeof STORY_SPLIT_MEDIA_TYPES)[number]

export interface TStorySplitImageType extends TStorySectionBase {
  Kind: 'SplitImage'
  Layout:
  | 'Right'
  | 'Left'
  | 'RightInline'
  | 'LeftInline'
  | 'RightInlineVertical'
  | 'LeftInlineVertical'
  Text: string
  LinkScheda?: TStoryLinkSchedaType
  Image: TStoryImageType
  MediaType: TStorySplitMediaType
}

export interface TStoryScrollRevealParagraphType {
  Text: string
  Image: TStoryImageType
  LinkScheda?: TStoryLinkSchedaType
}

export interface TStoryScrollRevealType extends TStorySectionBase {
  Kind: 'ScrollReveal'
  Paragraphs: TStoryScrollRevealParagraphType[]
}

export interface TStoryInlineImageType extends TStorySectionBase {
  Kind: 'InlineImage'
  LinkScheda?: TStoryLinkSchedaType
  Image: TStoryImageType
}

export interface TStoryImageFullScreenType extends TStorySectionBase {
  Kind: 'ImageFullScreen'
  Position: 'BottomLeft' | 'BottomRight' | 'TopRight' | 'TopLeft'
  Fit: 'Cover' | 'Contain'
  LinkScheda?: TStoryLinkSchedaType
  Image: TStoryImageType
}

export interface TStoryIIFAnnotationType {
  Text: string
  Rect: {
    x: number
    y: number
    width: number
    height: number
  }
}

/**
 * Immagine IIIF Image API 2 per sezioni annotazioni.
 * BaseURI = @id del servizio; Width/Height = canvas full image (info.json).
 */
export interface TStoryIIFImageType {
  BaseURI: string
  Width: number | null
  Height: number | null
  bgColor?: string | null
}

export interface TStoryIIFAnnotationsGroupType extends TStorySectionBase {
  Kind: 'IIFAnnotationsGroup'
  Image: TStoryIIFImageType
  Caption: string | null
  Annotations: TStoryIIFAnnotationType[]
}

export interface TStoryBibliographyType {
  Title: string
  Description: string
}

export interface TStorySitographyType {
  Title: string
  Description: string
}

export interface TStoryCreditsType {
  Title: string
  Description: string
}

export interface TStoryCatalogoOpereCitateType {
  Image: TStoryImageType
  Title: string
  Author: string
  Tags: string[]
  LinkScheda?: TStoryLinkSchedaType
}

export type TStorySection =
  | TStoryTextIntroType
  | TStoryInlineTextType
  | TStorySplitContentType
  | TStorySplitImageType
  | TStoryScrollRevealType
  | TStoryInlineImageType
  | TStoryImageFullScreenType
  | TStoryIIFAnnotationsGroupType

export interface TStoriesExtJson {
  Header: TStoryHeaderType
  sections: TStorySection[]
  bibliography?: Array<TStoryBibliographyType>
  sitography?: Array<TStorySitographyType>
  credits?: Array<TStoryCreditsType>
  catalogoOpereCitate?: Array<TStoryCatalogoOpereCitateType>
}

export interface TStoriesTypeData {
  id: string
  name: string
  description: string
  created_at: string
  updated_at: string
  publish_state: string
  ext_json: TStoriesExtJson
}

// =============================================================================
// Funzioni — specifiche del generatore (non necessarie per i soli tipi)
// =============================================================================

/** Layout header legacy (pre split testo sx/dx) → normalizzato in import. */
const LEGACY_HEADER_LAYOUT = 'ImageBackground' as const

export function createEmptyLinkScheda(): TStoryLinkSchedaType {
  return { Layout: 'TopLeft', URL: '' }
}

export function createEmptyScrollRevealParagraph(): TStoryScrollRevealParagraphType {
  return {
    Text: '',
    Image: { URL: '' },
    LinkScheda: createEmptyLinkScheda(),
  }
}

export function parseHeaderLayout(raw: unknown): TStoryHeaderLayout | null {
  if (raw === LEGACY_HEADER_LAYOUT) {
    return 'ImageBackground Text Left'
  }
  if (typeof raw !== 'string') return null
  return (STORY_HEADER_LAYOUTS as readonly string[]).includes(raw)
    ? (raw as TStoryHeaderLayout)
    : null
}

export function parseHeaderLayoutTheme(raw: unknown): TStoryHeaderLayoutTheme {
  if (typeof raw === 'string' && (STORY_HEADER_LAYOUT_THEMES as readonly string[]).includes(raw)) {
    return raw as TStoryHeaderLayoutTheme
  }
  return 'Light'
}

export function parseSplitMediaType(raw: unknown): TStorySplitMediaType {
  if (typeof raw === 'string' && (STORY_SPLIT_MEDIA_TYPES as readonly string[]).includes(raw)) {
    return raw as TStorySplitMediaType
  }
  return 'Image'
}

export function createEmptyIIFAnnotation(): TStoryIIFAnnotationType {
  return {
    Text: '',
    Rect: { x: 0, y: 0, width: 0, height: 0 },
  }
}

export function createEmptyIIFImage(): TStoryIIFImageType {
  return {
    BaseURI: '',
    Width: null,
    Height: null,
    bgColor: null,
  }
}

export function createEmptyIIFAnnotationsGroup(): TStoryIIFAnnotationsGroupType {
  return {
    Kind: 'IIFAnnotationsGroup',
    published: true,
    animazione: { Effetto: '' },
    Image: createEmptyIIFImage(),
    Caption: null,
    Annotations: [],
  }
}
