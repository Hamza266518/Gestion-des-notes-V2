import { diplomesApi } from '../api/diplomes';

export const API_ORIGIN = new URL(import.meta.env.VITE_API_URL || 'http://localhost:8000/api').origin;

// A single batch generation runs per tab. The state lives outside React so
// navigating between pages never unmounts the work: the floating notification
// is rendered once at the app root and keeps streaming progress regardless of
// the current route.
const readErrorMessage = async (err) => {
  const data = err.response?.data;
  if (data instanceof Blob) {
    try {
      return JSON.parse(await data.text()).message || null;
    } catch {
      return null;
    }
  }
  return data?.message || null;
};

let run = null;
let version = 0;
let cancelled = false;
const listeners = new Set();

function emit() {
  version += 1;
  listeners.forEach((listener) => listener());
}

function patch(patchPart) {
  if (!run) return;
  run = { ...run, ...patchPart };
  emit();
}

export const batchRunStore = {
  subscribe(listener) {
    listeners.add(listener);
    return () => listeners.delete(listener);
  },
  getSnapshot() {
    return run;
  },
  start({ anneeId, candidates }) {
    const list = Array.isArray(candidates) ? candidates : [];
    if (!anneeId || !list.length) return;

    const batch = `b${Date.now().toString(36)}${Math.random().toString(36).slice(2, 8)}`;
    cancelled = false;

    run = {
      phase: 'generating',
      batch,
      done: 0,
      total: list.length,
      current: '',
      generated: 0,
      failed: 0,
      errors: [],
      pin: false,
      minimized: false,
      filename: null,
      downloadUrl: null,
      stopping: false,
    };
    emit();

    (async () => {
      let generated = 0;
      let failed = 0;
      const errors = [];

      for (let i = 0; i < list.length; i++) {
        if (cancelled) break;
        const student = list[i];
        patch({ current: student.nom_prenom });
        try {
          await diplomesApi.batchGenerate({
            etudiant_id: student.id,
            annee_academique_id: anneeId,
            batch,
          });
          generated++;
        } catch (err) {
          failed++;
          errors.push(`${student.nom_prenom} — ${(await readErrorMessage(err)) || 'erreur inconnue'}`);
        }
        patch({
          done: i + 1,
          generated,
          failed,
          errors: [...errors],
          current: cancelled ? '' : student.nom_prenom,
        });
      }

      if (cancelled) {
        if (!run.generated) {
          patch({ phase: 'error', message: 'Génération arrêtée avant le premier diplôme' });
        } else {
          patch({ phase: 'done' });
        }
        return;
      }

      if (generated === 0) {
        patch({ phase: 'error', message: errors[0] || 'Aucun diplôme généré' });
        return;
      }

      patch({ phase: 'compressing', current: '' });
      try {
        const res = await diplomesApi.batchArchive({ batch, annee_academique_id: anneeId });
        const filename = res.data?.data?.filename;
        if (!filename) {
          throw new Error('L’archive a été générée mais son lien est introuvable.');
        }
        const token = localStorage.getItem('token') || '';
        const downloadUrl = `${API_ORIGIN}/api/admin/diplomes/batch-download?filename=${encodeURIComponent(filename)}&token=${encodeURIComponent(token)}`;
        patch({ phase: 'done', filename, downloadUrl });
      } catch (err) {
        patch({
          phase: 'error',
          message: (await readErrorMessage(err)) || 'Erreur lors de la compression de l’archive',
        });
      }
    })();
  },
  cancel() {
    if (!run) return;
    cancelled = true;
    patch({ stopping: true });
  },
  close() {
    if (!run) return;
    cancelled = true;
    run = null;
    emit();
  },
  togglePin() {
    if (!run) return;
    patch({ pin: !run.pin });
  },
  toggleMin() {
    if (!run) return;
    patch({ minimized: !run.minimized });
  },
};