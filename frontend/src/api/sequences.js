import apiClient from './apiClient';

export const sequencesApi = {
    getSequences: (unite_id) =>
        apiClient.get('/admin/sequences', { params: { unite_id } }),
    createSequence: (data) =>
        apiClient.post('/admin/sequences', data),
    updateSequence: (id, data) =>
        apiClient.put(`/admin/sequences/${id}`, data),
    deleteSequence: (id) =>
        apiClient.delete(`/admin/sequences/${id}`),
    toggleSequence: (id) =>
        apiClient.post(`/admin/sequences/${id}/toggle-active`),
};