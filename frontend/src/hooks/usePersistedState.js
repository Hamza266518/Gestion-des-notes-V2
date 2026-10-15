import { useState, useEffect, useRef, useCallback } from 'react';

const PREFIX = 'gnotes:filter:';

// Persists a JSON-serializable object in sessionStorage (per tab) so a page's
// filter state survives navigating away and back, and even an F5 refresh.
// Only the keys present in `initial` are restored/saved, so stale keys left by
// an older app version are silently ignored.
//
// Returns [state, setState, reset]:
//   state   – the current object (restored from storage on mount)
//   setState– a normal setter; every change is written back to sessionStorage
//   reset   – clears the stored value and resets to `initial`
export default function usePersistedState(key, initial) {
  const storageKey = PREFIX + key;

  const [state, setState] = useState(() => {
    try {
      const raw = sessionStorage.getItem(storageKey);
      if (raw == null) return initial;
      const saved = JSON.parse(raw);
      if (!saved || typeof saved !== 'object' || Array.isArray(saved)) return initial;
      const merged = {};
      for (const k of Object.keys(initial)) merged[k] = saved[k] ?? initial[k];
      return merged;
    } catch {
      return initial;
    }
  });

  const mounted = useRef(false);
  useEffect(() => {
    if (!mounted.current) { mounted.current = true; return; }
    try {
      sessionStorage.setItem(storageKey, JSON.stringify(state));
    } catch { /* ignore quota/security errors */ }
  }, [storageKey, state]);

  const reset = useCallback((fallback) => {
    try {
      sessionStorage.removeItem(storageKey);
    } catch { /* ignore */ }
    setState(fallback ?? initial);
  }, [storageKey, initial]);

  return [state, setState, reset];
}