import {
  createEmptyLinkScheda,
  createEmptyScrollRevealParagraph,
  type SectionKind,
  type TStorySection,
  type TStoryCatalogoOpereCitateType,
  type TStoryImageFullScreenType,
  type TStoryImageType,
  type TStoryInlineImageType,
  type TStoryScrollRevealType,
  type TStorySplitContentType,
  type TStorySplitImageType,
  type TStoryTextIntroType,
  type TStoryInlineTextType,
  createEmptyIIFAnnotationsGroup,
} from '../types/story'
import { createSectionBase } from './sectionBase'

export const STORY_SECTION_KIND_FIELD = 'Kind' as const

export const SECTION_KIND_LABELS: Record<SectionKind, string> = {
  TextIntro: 'Text Intro',
  InlineText: 'InlineText',
  SplitContent: 'SplitContent',
  SplitImage: 'SplitImage',
  ScrollReveal: 'ScrollReveal',
  InlineImage: 'InlineImage',
  ImageFullScreen: 'ImageFullScreen',
  IIFAnnotationsGroup: 'IIF Annotations',
}

function emptyImage(): TStoryImageType {
  return { URL: '' }
}

export function createEmptySection(kind: SectionKind): TStorySection {
  const base = createSectionBase(kind)
  switch (kind) {
    case 'TextIntro':
      return { ...base, Kind: 'TextIntro', Text: '' } satisfies TStoryTextIntroType
    case 'InlineText':
      return { ...base, Kind: 'InlineText', Text: '' } satisfies TStoryInlineTextType
    case 'SplitContent':
      return { ...base, Kind: 'SplitContent', LeftText: '', RightText: '' } satisfies TStorySplitContentType
    case 'SplitImage':
      return {
        ...base,
        Kind: 'SplitImage',
        Layout: 'Right',
        Text: '',
        LinkScheda: createEmptyLinkScheda(),
        Image: emptyImage(),
        MediaType: 'Image',
      } satisfies TStorySplitImageType
    case 'ScrollReveal':
      return {
        ...base,
        Kind: 'ScrollReveal',
        Paragraphs: [createEmptyScrollRevealParagraph()],
      } satisfies TStoryScrollRevealType
    case 'InlineImage':
      return {
        ...base,
        Kind: 'InlineImage',
        LinkScheda: createEmptyLinkScheda(),
        Image: emptyImage(),
      } satisfies TStoryInlineImageType
    case 'ImageFullScreen':
      return {
        ...base,
        Kind: 'ImageFullScreen',
        Position: 'BottomLeft',
        Fit: 'Cover',
        LinkScheda: createEmptyLinkScheda(),
        Image: emptyImage(),
      } satisfies TStoryImageFullScreenType
    case 'IIFAnnotationsGroup':
      return createEmptyIIFAnnotationsGroup()
    default: {
      const _x: never = kind
      return _x
    }
  }
}

/** Cambia il tipo sezione preservando published, animazione e aspetto. */
export function changeSectionKind(section: TStorySection, nextKind: SectionKind): TStorySection {
  const { published, animazione, foreColor, bgColor, bgImage } = section
  const sameTextShape =
    (section.Kind === 'TextIntro' || section.Kind === 'InlineText') &&
    (nextKind === 'TextIntro' || nextKind === 'InlineText')
  if (sameTextShape) {
    return { ...section, Kind: nextKind } as TStorySection
  }
  return { ...createEmptySection(nextKind), published, animazione, foreColor, bgColor, bgImage }
}

export function createEmptyCatalogoItem(): TStoryCatalogoOpereCitateType {
  return {
    Image: emptyImage(),
    Title: '',
    Author: '',
    Tags: [],
    LinkScheda: createEmptyLinkScheda(),
  }
}
