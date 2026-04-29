import apiClient from './apiClient';

export const formateursApi = {
  getFormateurs: () =>
    apiClient.get('/admin/formateurs'),
  createFormateur: (data) =>
    apiClient.post('/admin/formateurs', data),
  deleteFormateur: (id) =>
    apiClient.delete(`/admin/formateurs/${id}`),
  getFormateurUnites: (id) =>
    apiClient.get(`/admin/formateurs/${id}/unites`),
  importFormateurUnites: (id, formData) =>
    apiClient.post(`/admin/formateurs/${id}/import-unites`, formData, {
      headers: { 'Content-Type': 'multipart/form-data' },
    }),
  scanFormateurUnites: (id, formData) =>
    apiClient.post(`/admin/formateurs/${id}/scan-unites`, formData, {
      headers: { 'Content-Type': 'multipart/form-data' },
    }),
  removeFormateurUnite: (id, unite_id) =>
    apiClient.delete(`/admin/formateurs/${id}/remove-unite`, {
      data: { unite_id },
    }),
};
