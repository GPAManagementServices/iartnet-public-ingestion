import type {
  SectionKind,
  TStoryIIFAnnotationsGroupType,
  TStoryScrollRevealType,
  TStorySection,
  TStorySplitContentType,
  TStorySplitImageType,
  TStoryTextIntroType,
} from '../types/story'
import { normalizeRichText } from './richText'

function readRichTextField(section: TStorySection, key: string): unknown {
  return (section as unknown as Record<string, unknown>)[key]
}

/** Normalizza campi testo ricco di una sezione già tipizzata (wire legacy ammesso a runtime). */
export function normalizeSectionRichTextFields(
  kind: SectionKind,
  section: TStorySection,
): TStorySection {
  switch (kind) {
    case 'TextIntro':
    case 'InlineText': {
      const s = section as TStoryTextIntroType
      return { ...s, Text: normalizeRichText(readRichTextField(s, 'Text')) }
    }
    case 'SplitContent': {
      const s = section as TStorySplitContentType
      return {
        ...s,
        LeftText: normalizeRichText(readRichTextField(s, 'LeftText')),
        RightText: normalizeRichText(readRichTextField(s, 'RightText')),
      }
    }
    case 'SplitImage': {
      const s = section as TStorySplitImageType
      return { ...s, Text: normalizeRichText(readRichTextField(s, 'Text')) }
    }
    case 'ScrollReveal': {
      const s = section as TStoryScrollRevealType
      return {
        ...s,
        Paragraphs: s.Paragraphs.map((paragraph) => ({
          ...paragraph,
          Text: normalizeRichText((paragraph as unknown as Record<string, unknown>).Text),
        })),
      }
    }
    case 'IIFAnnotationsGroup': {
      const s = section as TStoryIIFAnnotationsGroupType
      return {
        ...s,
        Annotations: s.Annotations.map((annotation) => ({
          ...annotation,
          Text: normalizeRichText(annotation.Text, { allowImages: true }),
        })),
      }
    }
    default:
      return section
  }
}
