import apiClient from './apiClient';

export const controlesApi = {
  getControles: (sequence_id) =>
    apiClient.get('/admin/controles', { params: { sequence_id } }),
  createControle: (data) =>
    apiClient.post('/admin/controles', data),
  deleteControle: (id) =>
    apiClient.delete(`/admin/controles/${id}`),
  generateForSequence: (sequenceId) =>
    apiClient.post(`/admin/controles/generate/${sequenceId}`),
};
