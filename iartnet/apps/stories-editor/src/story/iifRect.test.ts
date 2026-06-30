import { describe, expect, it } from 'vitest'
import { clampIIFRect, iifRectFieldLimits, validateIIFRect } from './iifRect'

const bounds = { width: 5153, height: 7064 }

describe('iifRect', () => {
  it('validateIIFRect: bozza con x fuori canvas', () => {
    const r = validateIIFRect({ x: 6000, y: 0, width: 0, height: 0 }, bounds)
    expect(r.valid).toBe(false)
    expect(r.fieldErrors.x).toBeTruthy()
  })

  it('validateIIFRect: bozza 0×0 valida al centro', () => {
    expect(validateIIFRect({ x: 0, y: 0, width: 0, height: 0 }, bounds)).toMatchObject({
      valid: true,
      isDraft: true,
    })
  })

  it('validateIIFRect: rettangolo dentro il canvas', () => {
    expect(
      validateIIFRect({ x: 10, y: 20, width: 100, height: 50 }, bounds),
    ).toMatchObject({ valid: true, isDraft: false })
  })

  it('validateIIFRect: fuori bounds', () => {
    const r = validateIIFRect({ x: 5100, y: 0, width: 100, height: 50 }, bounds)
    expect(r.valid).toBe(false)
    expect(r.summary).toMatch(/fuori/i)
  })

  it('validateIIFRect: width negativo', () => {
    const r = validateIIFRect({ x: 0, y: 0, width: -1, height: 10 }, bounds)
    expect(r.valid).toBe(false)
    expect(r.fieldErrors.width).toBeTruthy()
  })

  it('clampIIFRect riporta dentro il canvas', () => {
    expect(clampIIFRect({ x: 5100, y: 7000, width: 200, height: 200 }, bounds)).toEqual({
      x: 4953,
      y: 6864,
      width: 200,
      height: 200,
    })
  })

  it('clampIIFRect mantiene bozza 0×0', () => {
    expect(clampIIFRect({ x: -5, y: 0, width: 0, height: 0 }, bounds)).toEqual({
      x: 0,
      y: 0,
      width: 0,
      height: 0,
    })
  })

  it('iifRectFieldLimits calcola max dipendente da x/y', () => {
    expect(iifRectFieldLimits({ x: 100, y: 0, width: 50, height: 10 }, bounds, 'width')).toEqual({
      min: 1,
      max: 5053,
    })
  })
})
