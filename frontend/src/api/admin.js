import apiClient from './apiClient';

export const adminApi = {
    getAnnees: () =>
        apiClient.get('/admin/annees-academiques'),
    createAnnee: (data) =>
        apiClient.post('/admin/annees-academiques', data),
    setCurrentAnnee: (id) =>
        apiClient.post(`/admin/annees-academiques/${id}/set-current`),
    archiveAnnee: (id) =>
        apiClient.post(`/admin/annees-academiques/${id}/archive`),

    getFilieres: () =>
        apiClient.get('/admin/filieres'),
    createFiliere: (data) =>
        apiClient.post('/admin/filieres', data),
    updateFiliere: (id, data) =>
        apiClient.put(`/admin/filieres/${id}`, data),
    deleteFiliere: (id) =>
        apiClient.delete(`/admin/filieres/${id}`),

    getNiveaux: (filiere_id) =>
        apiClient.get('/admin/niveaux', { params: { filiere_id } }),
    createNiveau: (data) =>
        apiClient.post('/admin/niveaux', data),
    deleteNiveau: (id) =>
        apiClient.delete(`/admin/niveaux/${id}`),

    getGroupes: (params) =>
        apiClient.get('/admin/groupes', { params }),
    createGroupe: (data) =>
        apiClient.post('/admin/groupes', data),
    updateGroupe: (id, data) =>
        apiClient.put(`/admin/groupes/${id}`, data),
    deleteGroupe: (id) =>
        apiClient.delete(`/admin/groupes/${id}`),

    getFormateurs: () =>
        apiClient.get('/admin/formateurs'),
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

    getActivityLogs: (params) =>
        apiClient.get('/admin/activity-logs', { params }),

    confirmScanCin: (data) =>
        apiClient.post('/admin/scan-cin/confirm', data),
};