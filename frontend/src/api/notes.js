import apiClient from './apiClient';

export const notesApi = {
    getNotes: (params) =>
        apiClient.get('/admin/notes', { params }),
    updateNote: (id, valeur) =>
        apiClient.put(`/admin/notes/${id}`, { valeur }),
};