import apiClient from './apiClient';

export const formateursApi = {
  getFormateurs: (config) =>
    apiClient.get('/admin/formateurs', config),
  createFormateur: (data) =>
    apiClient.post('/admin/formateurs', data),
  deleteFormateur: (id) =>
    apiClient.delete(`/admin/formateurs/${id}`),
  getFormateurSequences: (id) =>
    apiClient.get(`/admin/formateurs/${id}/sequences`),
  assignFormateurSequence: (id, data) =>
    apiClient.post(`/admin/formateurs/${id}/assign-sequence`, data),
  removeFormateurSequence: (id, sequence_id) =>
    apiClient.delete(`/admin/formateurs/${id}/remove-sequence`, {
      data: { sequence_id },
    }),
  importFormateurSequences: (id, formData) =>
    apiClient.post(`/admin/formateurs/${id}/import-sequences`, formData, {
      headers: { 'Content-Type': 'multipart/form-data' },
    }),
  scanFormateurSequences: (id, formData) =>
    apiClient.post(`/admin/formateurs/${id}/scan-sequences`, formData, {
      headers: { 'Content-Type': 'multipart/form-data' },
    }),
  updatePassword: (id, password) =>
    apiClient.post(`/admin/formateurs/${id}/update-password`, { password }),
  getMySequences: () =>
    apiClient.get('/formateur/sequences'),
  getScanData: () =>
    apiClient.get('/formateur/scan-data'),
  getNotes: (params) =>
    apiClient.get('/formateur/notes', { params }),
  updateNote: (id, valeur) =>
    apiClient.put(`/formateur/notes/${id}`, { valeur }),
  searchEtudiants: (search) =>
    apiClient.get('/formateur/etudiants', { params: { search } }),
};
