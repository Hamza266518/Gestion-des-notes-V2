import apiClient from './apiClient';

export const notesApi = {
    getNotes: (params) =>
        apiClient.get('/admin/notes', { params }),
    getRecapNotes: (params) =>
        apiClient.get('/admin/recap-notes', { params }),
    createNote: (data) =>
        apiClient.post('/admin/notes', data),
    updateNote: (id, valeur) =>
        apiClient.put(`/admin/notes/${id}`, { valeur }),
    getExamens: (params) =>
        apiClient.get('/admin/examens', { params }),
    saveBulkExamens: (data) =>
        apiClient.post('/admin/examens/bulk', data),
    getBulletin: (params) =>
        apiClient.get('/admin/bulletins', { params }),
};