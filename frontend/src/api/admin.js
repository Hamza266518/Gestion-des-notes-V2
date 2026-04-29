import apiClient from './apiClient';

export const adminApi = {
    // Annees academiques
    getAnnees: () =>
        apiClient.get('/admin/annees-academiques'),
    createAnnee: (label) =>
        apiClient.post('/admin/annees-academiques', { label }),
    setCurrentAnnee: (id) =>
        apiClient.post(`/admin/annees-academiques/${id}/set-current`),
    archiveAnnee: (id) =>
        apiClient.post(`/admin/annees-academiques/${id}/archive`),
    deleteAnnee: (id) =>
        apiClient.delete(`/admin/annees-academiques/${id}`),

    // Filieres
    getFilieres: () =>
        apiClient.get('/admin/filieres'),
    createFiliere: (data) =>
        apiClient.post('/admin/filieres', data),
    updateFiliere: (id, data) =>
        apiClient.put(`/admin/filieres/${id}`, data),
    deleteFiliere: (id) =>
        apiClient.delete(`/admin/filieres/${id}`),

    // Niveaux
    getNiveaux: (filiere_id) =>
        apiClient.get('/admin/niveaux', { params: { filiere_id } }),
    createNiveau: (data) =>
        apiClient.post('/admin/niveaux', data),
    deleteNiveau: (id) =>
        apiClient.delete(`/admin/niveaux/${id}`),

    // Groupes
    getGroupes: (params) =>
        apiClient.get('/admin/groupes', { params }),
    createGroupe: (data) =>
        apiClient.post('/admin/groupes', data),
    updateGroupe: (id, data) =>
        apiClient.put(`/admin/groupes/${id}`, data),
    deleteGroupe: (id) =>
        apiClient.delete(`/admin/groupes/${id}`),

    // Formateurs
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