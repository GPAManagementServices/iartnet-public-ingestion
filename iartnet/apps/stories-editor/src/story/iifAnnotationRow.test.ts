import { describe, expect, it } from 'vitest'
import {
  annotationsFromRows,
  newIIFAnnotationRow,
  rowsFromAnnotations,
} from './iifAnnotationRow'

describe('iifAnnotationRow', () => {
  it('newIIFAnnotationRow crea annotazione vuota', () => {
    const row = newIIFAnnotationRow()
    expect(row.id).toBeTruthy()
    expect(row.annotation).toEqual({
      Text: '',
      Rect: { x: 0, y: 0, width: 0, height: 0 },
    })
  })

  it('rowsFromAnnotations + annotationsFromRows preserva i contenuti', () => {
    const annotations = [
      { Text: 'a', Rect: { x: 1, y: 2, width: 3, height: 4 } },
      { Text: 'b<br />c', Rect: { x: 0, y: 0, width: 10, height: 20 } },
    ]
    const rows = rowsFromAnnotations(annotations)
    expect(rows).toHaveLength(2)
    expect(annotationsFromRows(rows)).toEqual(annotations)
  })

  it('rowsFromAnnotations preserva gli id quando cambia solo il contenuto', () => {
    const rows = rowsFromAnnotations([
      { Text: 'a', Rect: { x: 1, y: 2, width: 3, height: 4 } },
      { Text: 'b', Rect: { x: 5, y: 6, width: 7, height: 8 } },
    ])
    const ids = rows.map((r) => r.id)
    const next = rowsFromAnnotations(
      [
        { Text: 'a', Rect: { x: 10, y: 20, width: 30, height: 40 } },
        { Text: 'b', Rect: { x: 5, y: 6, width: 7, height: 8 } },
      ],
      rows,
    )
    expect(next.map((r) => r.id)).toEqual(ids)
    expect(next[0]!.annotation.Rect.x).toBe(10)
  })
})
