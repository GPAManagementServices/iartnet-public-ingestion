import {
  createEmptyLinkScheda,
  type TStoryCatalogoOpereCitateType,
  type TStoryImageType,
  type TStoryLinkSchedaType,
  type TStorySection,
} from '../types/story'

type SectionWithImage = {
  Image: TStoryImageType
  LinkScheda?: TStoryLinkSchedaType
}

function copyImage(image: TStoryImageType): TStoryImageType {
  return { URL: image.URL }
}

function copyLinkScheda(link: TStoryLinkSchedaType | undefined): TStoryLinkSchedaType {
  const empty = createEmptyLinkScheda()
  if (!link) return empty
  return { Layout: empty.Layout, URL: link.URL }
}

function toCatalogoItem(section: SectionWithImage): TStoryCatalogoOpereCitateType {
  return {
    Image: copyImage(section.Image),
    Title: '',
    Author: '',
    Tags: [],
    LinkScheda: copyLinkScheda(section.LinkScheda),
  }
}

/** Una voce catalogo per ogni sezione che contiene `Image`. */
export function catalogoItemsFromSections(
  sections: TStorySection[],
): TStoryCatalogoOpereCitateType[] {
  return sections.flatMap((section) => {
    if (!('Image' in section) || !section.Image) return []
    return [toCatalogoItem(section as SectionWithImage)]
  })
}
