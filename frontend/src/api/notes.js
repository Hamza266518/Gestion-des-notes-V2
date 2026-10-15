import apiClient from './apiClient';

export const notesApi = {
    getNotes: (params) =>
        apiClient.get('/admin/notes', { params }),
    getRecapNotes: (params) =>
        apiClient.get('/admin/recap-notes', { params }),
    getRecapTableTypes: (params) =>
        apiClient.get('/admin/recap-notes/table-types', { params }),
    downloadRecapDocx: (params) =>
        apiClient.get('/admin/recap-notes/docx', { params, responseType: 'blob', cache: false }),
    downloadRecapDocxAll: (params) =>
        apiClient.get('/admin/recap-notes/docx-all', { params, responseType: 'blob', cache: false }),
    updateNote: (id, valeur) =>
        apiClient.put(`/admin/notes/${id}`, { valeur }),
    createNote: (data) =>
        apiClient.post('/admin/notes', data),
    getExamens: (params) =>
        apiClient.get('/admin/examens', { params }),
    saveBulkExamens: (data) =>
        apiClient.post('/admin/examens/bulk', data),
    getBulletin: (params) =>
        apiClient.get('/admin/bulletins', { params }),
    downloadBulletinDocx: (params) =>
        apiClient.get('/admin/bulletin/docx', { params, responseType: 'blob', cache: false }),
    downloadBulletinDocxAll: (params) =>
        apiClient.get('/admin/bulletin/docx-all', { params, responseType: 'blob', cache: false }),
};