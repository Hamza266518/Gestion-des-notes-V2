import apiClient from './apiClient';

export const etudiantsApi = {
    getEtudiants: (params) =>
        apiClient.get('/admin/etudiants', { params }),
    deleteEtudiant: (id) =>
        apiClient.delete(`/admin/etudiants/${id}`),
};