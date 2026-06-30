import { parseSplitMediaType, type TStorySplitImageType } from '../types/story'

export function normalizeSplitImage(section: TStorySplitImageType): TStorySplitImageType {
  return {
    ...section,
    MediaType: parseSplitMediaType(section.MediaType),
  }
}
