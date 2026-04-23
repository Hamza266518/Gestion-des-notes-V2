import apiClient from './apiClient';

export const adminApi = {
    // Academic Years
    getAnnees: () => apiClient.get('/admin/annees-academiques'),
    setCurrentAnnee: (id) => apiClient.post(`/admin/annees-academiques/${id}/set-current`),
    
    // Filieres
    getFilieres: () => apiClient.get('/admin/filieres'),
    createFiliere: (data) => apiClient.post('/admin/filieres', data),
    updateFiliere: (id, data) => apiClient.put(`/admin/filieres/${id}`, data),
    deleteFiliere: (id) => apiClient.delete(`/admin/filieres/${id}`),
    
    // Students & Imports
    importEtudiants: (formData) => apiClient.post('/admin/etudiants/import', formData, {
        headers: { 'Content-Type': 'multipart/form-data' }
    }),
    scanCin: (data) => apiClient.post('/admin/etudiants/scan-cin', data),
    
    // Formateurs
    getFormateurs: () => apiClient.get('/admin/formateurs'),
    assignUnite: (formateurId, data) => apiClient.post(`/admin/formateurs/${formateurId}/assign-unite`, data),
    
    // Controls & Notes
    generateControles: (sequenceId) => apiClient.post(`/admin/controles/generate/${sequenceId}`),
    getNotes: (params) => apiClient.get('/admin/notes', { params }),
    updateNote: (id, data) => apiClient.put(`/admin/notes/${id}`, data),
};
