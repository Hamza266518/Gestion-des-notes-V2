import apiClient from './apiClient';

export const stagesApi = {
    searchStudents: (search) =>
        apiClient.get('/admin/stage/search-students', { params: { search } }),
    save: (data) =>
        apiClient.post('/admin/stage/save', data),
    load: (params) =>
        apiClient.get('/admin/stage/load', { params }),
};
