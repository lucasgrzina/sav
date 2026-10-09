/** Compares two guid lists ignoring order and duplicates. */
export function haveSameGuids(a: readonly string[], b: readonly string[]): boolean {
  const setA = new Set(a)
  const setB = new Set(b)
  if (setA.size !== setB.size) return false
  for (const guid of setA) {
    if (!setB.has(guid)) return false
  }
  return true
}

/** Guids present in `previous` that are no longer in `next` (unlinked staff). */
export function removedGuids(previous: readonly string[], next: readonly string[]): string[] {
  const nextSet = new Set(next)
  return previous.filter((guid) => !nextSet.has(guid))
}
