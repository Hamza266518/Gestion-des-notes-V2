import apiClient from './apiClient';

export const etudiantsApi = {
    getEtudiants: (params) =>
        apiClient.get('/admin/etudiants', { params }),
    updateEtudiant: (id, data) =>
        apiClient.put(`/admin/etudiants/${id}`, data),
    deleteEtudiant: (id) =>
        apiClient.delete(`/admin/etudiants/${id}`),
};