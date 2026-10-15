import apiClient from './apiClient';

// Session cache so repeatedly typing the same city does not re-call Gemini.
const lieuCache = new Map();

export const translateLieu = async (lieu) => {
  const key = String(lieu || '').trim().toLowerCase();
  if (!key) return { lieu_ar: '' };
  if (lieuCache.has(key)) return { lieu_ar: lieuCache.get(key) };
  const res = await apiClient.post('/admin/translate-lieu', { lieu });
  const ar = res?.data?.data?.lieu_ar || '';
  lieuCache.set(key, ar);
  return { lieu_ar: ar };
};

export const etudiantsApi = {
    getEtudiants: (params) =>
        apiClient.get('/admin/etudiants', { params }),
    downloadListeDocx: (params) =>
        apiClient.get('/admin/etudiants/docx/liste', { params, responseType: 'blob', cache: false }),
    downloadIdentifiantsDocx: (params) =>
        apiClient.get('/admin/etudiants/docx/identifiants', { params, responseType: 'blob', cache: false }),
    updateEtudiant: (id, data) =>
        apiClient.put(`/admin/etudiants/${id}`, data),
    deleteEtudiant: (id) =>
        apiClient.delete(`/admin/etudiants/${id}`),
};