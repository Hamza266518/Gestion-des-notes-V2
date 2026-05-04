import apiClient from './apiClient';

export const publicationsApi = {
    getPublications: (params) =>
        apiClient.get('/admin/publications', { params }),
    publish: (data) =>
        apiClient.post('/admin/publications/publish', data),
    unpublish: (data) =>
        apiClient.post('/admin/publications/unpublish', data),
};