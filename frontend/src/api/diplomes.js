import apiClient from './apiClient';

export const diplomesApi = {
    getDiplomes: (params) =>
        apiClient.get('/admin/diplomes', { params }),
    generateDiplome: (data) =>
        apiClient.post('/admin/diplomes/generate', data),
    generateAllDiplomes: (data) =>
        apiClient.post('/admin/diplomes/generate-all', data),
    checkReadiness: (params) =>
        apiClient.get('/admin/diplomes/check-readiness', { params }),
    markPrinted: (id) =>
        apiClient.put(`/admin/diplomes/${id}/printed`),
    manualCandidates: (params) =>
        apiClient.get('/admin/diplomes/manual-candidates', { params }),
    manualGenerate: (data) =>
        apiClient.post('/admin/diplomes/manual-generate', data),
    batchGenerate: (data) =>
        apiClient.post('/admin/diplomes/batch-generate', data),
    batchArchive: (data) =>
        apiClient.post('/admin/diplomes/batch-archive', data),
    downloadDiplome: (id) =>
        apiClient.get(`/admin/diplomes/${id}/download`, { responseType: 'blob', cache: false }),
    getTemplatesMeta: () =>
        apiClient.get('/admin/diplomes/templates-meta'),
    getInformations: () =>
        apiClient.get('/admin/diplomes/informations'),
    saveInformations: (data) =>
        apiClient.put('/admin/diplomes/informations', data),
    saveInformationsPositions: (data) =>
        apiClient.put('/admin/diplomes/informations/positions', data),
};