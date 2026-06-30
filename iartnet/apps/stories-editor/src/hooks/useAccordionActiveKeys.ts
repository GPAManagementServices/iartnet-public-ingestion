import { useCallback, useEffect, useRef, useState } from 'react'

function sameKeyOrder(a: string[], b: string[]) {
  return a.length === b.length && a.every((k, i) => k === b[i])
}

/** Stato controllato per Accordion `alwaysOpen` (più pannelli). */
export function useAccordionActiveKeys(allKeys: string[]) {
  const keysKey = allKeys.join('\0')
  const [activeKeys, setActiveKeys] = useState<string[]>(() => [...allKeys])
  const prevKeysRef = useRef<Set<string> | null>(null)

  useEffect(() => {
    const keys = keysKey.length > 0 ? keysKey.split('\0') : []
    const currentSet = new Set(keys)
    const prevSet = prevKeysRef.current
    prevKeysRef.current = currentSet

    setActiveKeys((prev) => {
      let next = prev.filter((k) => currentSet.has(k))
      if (prevSet !== null) {
        for (const k of keys) {
          if (!prevSet.has(k) && !next.includes(k)) {
            next = [...next, k]
          }
        }
      }
      return sameKeyOrder(next, prev) ? prev : next
    })
  }, [keysKey])

  const allOpen =
    allKeys.length > 0 && allKeys.every((k) => activeKeys.includes(k))

  const toggleAll = useCallback(() => {
    setActiveKeys(allOpen ? [] : [...allKeys])
  }, [allOpen, allKeys])

  const onSelect = useCallback((keys: unknown) => {
    setActiveKeys(keys as string[])
  }, [])

  const openKey = useCallback(
    (key: string) => {
      prevKeysRef.current ??= new Set(allKeys)
      prevKeysRef.current.add(key)
      setActiveKeys((prev) => (prev.includes(key) ? prev : [...prev, key]))
    },
    [allKeys],
  )

  return {
    activeKeys,
    setActiveKeys,
    onSelect,
    allOpen,
    toggleAll,
    openKey,
    prevKeysRef,
  }
}

/** Accordion a pannello singolo (senza `alwaysOpen`). */
export function useSingleAccordionActiveKey(
  eventKey: string,
  initiallyOpen = true,
) {
  const [activeKey, setActiveKey] = useState<string | undefined>(
    initiallyOpen ? eventKey : undefined,
  )

  const isOpen = activeKey === eventKey

  const toggleAll = useCallback(() => {
    setActiveKey(isOpen ? undefined : eventKey)
  }, [isOpen, eventKey])

  const onSelect = useCallback(
    (key: unknown) => {
      setActiveKey(key === eventKey ? eventKey : undefined)
    },
    [eventKey],
  )

  return { activeKey, onSelect, allOpen: isOpen, toggleAll }
}
