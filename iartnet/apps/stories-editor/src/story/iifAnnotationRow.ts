import {
  createEmptyIIFAnnotation,
  type TStoryIIFAnnotationType,
} from '../types/story'
import { newClientId } from './sectionRow'

export type IIFAnnotationRow = {
  id: string
  annotation: TStoryIIFAnnotationType
}

export function newIIFAnnotationRow(): IIFAnnotationRow {
  return {
    id: newClientId(),
    annotation: createEmptyIIFAnnotation(),
  }
}

export function rowsFromAnnotations(
  annotations: TStoryIIFAnnotationType[],
  previousRows: IIFAnnotationRow[] = [],
): IIFAnnotationRow[] {
  if (previousRows.length === annotations.length) {
    return previousRows.map((row, index) => ({
      ...row,
      annotation: annotations[index]!,
    }))
  }
  return annotations.map((annotation) => ({
    id: newClientId(),
    annotation,
  }))
}

export function annotationsFromRows(
  rows: IIFAnnotationRow[],
): TStoryIIFAnnotationType[] {
  return rows.map((r) => r.annotation)
}
