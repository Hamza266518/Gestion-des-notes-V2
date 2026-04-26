import apiClient from './apiClient';

export const diplomesApi = {
    getDiplomes: (params) =>
        apiClient.get('/admin/diplomes', { params }),
    generateDiplome: (data) =>
        apiClient.post('/admin/diplomes/generate', data),
    markPrinted: (id) =>
        apiClient.put(`/admin/diplomes/${id}/printed`),
};