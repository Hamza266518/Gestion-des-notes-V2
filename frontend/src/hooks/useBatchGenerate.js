import { useCallback, useEffect, useRef, useSyncExternalStore } from 'react';
import { batchRunStore } from '../stores/batchRunStore';

/**
 * Access to the tab-wide batch generation. State is owned by batchRunStore (a
 * module singleton), so switching pages never kills the run. `onDone` fires on
 * the transition into 'done' or 'error'; the notification itself is rendered
 * once at the app root and reads the same store.
 */
export default function useBatchGenerate({ anneeId, onDone }) {
  const run = useSyncExternalStore(batchRunStore.subscribe, batchRunStore.getSnapshot);

  const onDoneRef = useRef(onDone);
  useEffect(() => {
    onDoneRef.current = onDone;
  });

  const prevPhase = useRef(run?.phase);
  useEffect(() => {
    const phase = run?.phase;
    const prev = prevPhase.current;
    prevPhase.current = phase;
    if ((phase === 'done' || phase === 'error') && phase !== prev) {
      onDoneRef.current?.();
    }
  }, [run]);

  const start = useCallback(
    (candidates) => batchRunStore.start({ anneeId, candidates }),
    [anneeId],
  );

  return { run, start };
}