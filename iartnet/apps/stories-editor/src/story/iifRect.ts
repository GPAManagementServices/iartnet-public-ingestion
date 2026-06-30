import type { TStoryIIFAnnotationType } from '../types/story'
import type { IIFImageBounds } from './iiifImage'

export type IIFRectField = keyof TStoryIIFAnnotationType['Rect']

export type IIFRectValidation = {
  valid: boolean
  /** Rettangolo 0×0: bozza, non errore bloccante */
  isDraft: boolean
  fieldErrors: Partial<Record<IIFRectField, string>>
  summary: string | null
}

export function parseIIFRectCoordinate(raw: string): number {
  const n = Number(raw)
  if (!Number.isFinite(n)) return 0
  return Math.round(n)
}

export function isDraftIIFRect(rect: TStoryIIFAnnotationType['Rect']): boolean {
  return rect.width === 0 && rect.height === 0
}

export function validateIIFRect(
  rect: TStoryIIFAnnotationType['Rect'],
  bounds: IIFImageBounds | null,
): IIFRectValidation {
  const fieldErrors: Partial<Record<IIFRectField, string>> = {}

  if (rect.x < 0) fieldErrors.x = 'x deve essere ≥ 0'
  if (rect.y < 0) fieldErrors.y = 'y deve essere ≥ 0'

  if (bounds) {
    if (rect.x >= bounds.width) {
      fieldErrors.x = `x deve essere < ${bounds.width} (canvas ${bounds.width} px)`
    }
    if (rect.y >= bounds.height) {
      fieldErrors.y = `y deve essere < ${bounds.height} (canvas ${bounds.height} px)`
    }
  }

  if (isDraftIIFRect(rect)) {
    const valid = Object.keys(fieldErrors).length === 0
    return {
      valid,
      isDraft: true,
      fieldErrors,
      summary: valid
        ? bounds
          ? 'Regione non definita (imposta width e height > 0)'
          : null
        : bounds
          ? 'Coordinate non valide rispetto al canvas'
          : 'Coordinate non valide (carica le dimensioni da info.json)',
    }
  }

  if (rect.width <= 0) fieldErrors.width = 'width deve essere > 0'
  if (rect.height <= 0) fieldErrors.height = 'height deve essere > 0'

  if (bounds) {
    if (rect.x + rect.width > bounds.width) {
      fieldErrors.width =
        fieldErrors.width ??
        `x + width deve essere ≤ ${bounds.width} (canvas ${bounds.width} px)`
      if (!fieldErrors.x && rect.x >= bounds.width) {
        fieldErrors.x = `x deve essere < ${bounds.width}`
      }
    }
    if (rect.y + rect.height > bounds.height) {
      fieldErrors.height =
        fieldErrors.height ??
        `y + height deve essere ≤ ${bounds.height} (canvas ${bounds.height} px)`
      if (!fieldErrors.y && rect.y >= bounds.height) {
        fieldErrors.y = `y deve essere < ${bounds.height}`
      }
    }
  }

  const valid = Object.keys(fieldErrors).length === 0
  const summary = valid
    ? null
    : bounds
      ? 'Rettangolo fuori dal canvas immagine'
      : 'Coordinate non valide (carica le dimensioni da info.json)'

  return { valid, isDraft: false, fieldErrors, summary }
}

/** Limiti HTML per un campo Rect quando il canvas è noto. */
export function iifRectFieldLimits(
  rect: TStoryIIFAnnotationType['Rect'],
  bounds: IIFImageBounds,
  field: IIFRectField,
): { min: number; max: number } {
  switch (field) {
    case 'x':
      return { min: 0, max: Math.max(0, bounds.width - Math.max(1, rect.width)) }
    case 'y':
      return { min: 0, max: Math.max(0, bounds.height - Math.max(1, rect.height)) }
    case 'width':
      return { min: 1, max: Math.max(1, bounds.width - Math.max(0, rect.x)) }
    case 'height':
      return { min: 1, max: Math.max(1, bounds.height - Math.max(0, rect.y)) }
  }
}

/** Corregge il rettangolo ai limiti del canvas (mantiene bozza 0×0). */
export function clampIIFRect(
  rect: TStoryIIFAnnotationType['Rect'],
  bounds: IIFImageBounds,
): TStoryIIFAnnotationType['Rect'] {
  if (isDraftIIFRect(rect)) {
    return {
      x: Math.max(0, Math.round(rect.x)),
      y: Math.max(0, Math.round(rect.y)),
      width: 0,
      height: 0,
    }
  }

  let x = Math.max(0, Math.round(rect.x))
  let y = Math.max(0, Math.round(rect.y))
  let width = Math.max(1, Math.round(rect.width))
  let height = Math.max(1, Math.round(rect.height))

  width = Math.min(width, bounds.width)
  height = Math.min(height, bounds.height)
  x = Math.min(x, Math.max(0, bounds.width - width))
  y = Math.min(y, Math.max(0, bounds.height - height))
  width = Math.min(width, bounds.width - x)
  height = Math.min(height, bounds.height - y)

  return { x, y, width, height }
}
