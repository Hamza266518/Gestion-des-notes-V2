import apiClient from './apiClient';

export const stagesApi = {
    searchStudents: (search) =>
        apiClient.get('/admin/stage/search-students', { params: { search } }),
    stageServices: (etudiant_id) =>
        apiClient.get('/admin/stage/services', { params: { etudiant_id } }),
    save: (data) =>
        apiClient.post('/admin/stage/save', data),
    load: (params) =>
        apiClient.get('/admin/stage/load', { params }),
    notes: (params) =>
        apiClient.get('/admin/stage/notes', { params }),
    saveNote: (data) =>
        apiClient.post('/admin/stage/notes', data),
    getSavedServices: (params) =>
        apiClient.get('/admin/stage/saved-services', { params }),
    downloadGrille: (params) =>
        apiClient.get('/admin/stage/grille/download', { params, responseType: 'blob' }),
    downloadGrilleGroupe: (params) =>
        apiClient.get('/admin/stage/grille/groupe/download', { params, responseType: 'blob' }),
};
