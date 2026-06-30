import type { TStoriesExtJson } from '../types/story'

/** Aggiorna `ext_json` senza perdere chiavi quando `partial` è parziale. */
export function supplementaryMerge(
  base: TStoriesExtJson,
  partial: Partial<TStoriesExtJson>,
): TStoriesExtJson {
  const next: TStoriesExtJson = { ...base }
  if (partial.Header !== undefined) next.Header = partial.Header
  if (partial.sections !== undefined) next.sections = partial.sections
  if (partial.bibliography !== undefined) next.bibliography = partial.bibliography
  if (partial.sitography !== undefined) next.sitography = partial.sitography
  if (partial.credits !== undefined) next.credits = partial.credits
  if (partial.catalogoOpereCitate !== undefined)
    next.catalogoOpereCitate = partial.catalogoOpereCitate
  return next
}
