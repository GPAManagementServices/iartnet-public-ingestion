import {
  createEmptyScrollRevealParagraph,
  type TStoryImageType,
  type TStoryScrollRevealParagraphType,
  type TStoryScrollRevealType,
} from '../types/story'

function normalizeImage(raw: unknown): TStoryImageType {
  if (raw && typeof raw === 'object' && !Array.isArray(raw)) {
    return raw as TStoryImageType
  }
  return { URL: '' }
}

function normalizeParagraph(raw: unknown): TStoryScrollRevealParagraphType {
  if (!raw || typeof raw !== 'object' || Array.isArray(raw)) {
    return createEmptyScrollRevealParagraph()
  }
  const record = raw as Record<string, unknown>
  const paragraph: TStoryScrollRevealParagraphType = {
    Text: (record.Text ?? '') as string,
    Image: normalizeImage(record.Image),
  }
  if (record.LinkScheda !== undefined) {
    paragraph.LinkScheda = record.LinkScheda as TStoryScrollRevealParagraphType['LinkScheda']
  }
  return paragraph
}

export function normalizeScrollReveal(section: TStoryScrollRevealType): TStoryScrollRevealType {
  return {
    Kind: section.Kind,
    published: section.published,
    animazione: section.animazione,
    Paragraphs: section.Paragraphs.map(normalizeParagraph),
  }
}
