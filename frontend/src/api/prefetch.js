import { adminApi } from './admin';
import { formateursApi } from './formateurs';

// Route -> prefetch thunks fired on sidebar hover (~250ms before click).
// Responses are stored by the apiClient GET cache and served instantly
// when the page mounts. Only parameterless endpoints are listed so the
// cache key matches the page's own request exactly.
const prefetchers = {
  '/admin/formateurs': [() => formateursApi.getFormateurs()],
  '/admin/annees': [() => adminApi.getAnnees()],
  '/admin/filieres-groupes': [() => adminApi.getFilieres()],
};

const timers = new Map();

export function startPrefetch(path) {
  if (!prefetchers[path] || timers.has(path)) return;
  timers.set(
    path,
    setTimeout(() => {
      timers.delete(path);
      prefetchers[path].forEach((fn) => {
        try {
          const p = fn();
          if (p && typeof p.catch === 'function') p.catch(() => {});
        } catch {
          /* ignore prefetch errors */
        }
      });
    }, 250)
  );
}

export function cancelPrefetch(path) {
  const t = timers.get(path);
  if (t) {
    clearTimeout(t);
    timers.delete(path);
  }
}
