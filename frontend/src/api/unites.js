import apiClient from './apiClient';

export const unitesApi = {
    getUnites: (params) =>
        apiClient.get('/admin/unites', { params }),
    createUnite: (data) =>
        apiClient.post('/admin/unites', data),
    updateUnite: (id, data) =>
        apiClient.put(`/admin/unites/${id}`, data),
    deleteUnite: (id) =>
        apiClient.delete(`/admin/unites/${id}`),
    toggleUnite: (id) =>
        apiClient.post(`/admin/unites/${id}/toggle-active`),
};