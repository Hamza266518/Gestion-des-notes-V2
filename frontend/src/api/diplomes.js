import apiClient from './apiClient';

export const diplomesApi = {
    getDiplomes: (params) =>
        apiClient.get('/admin/diplomes', { params }),
    generateDiplome: (data) =>
        apiClient.post('/admin/diplomes/generate', data),
    generateAllDiplomes: (data) =>
        apiClient.post('/admin/diplomes/generate-all', data),
    markPrinted: (id) =>
        apiClient.put(`/admin/diplomes/${id}/printed`),
    downloadDiplome: (id) =>
        apiClient.get(`/admin/diplomes/${id}/download`),
};