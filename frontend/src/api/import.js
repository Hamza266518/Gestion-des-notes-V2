import apiClient from './apiClient';

export const importApi = {
    importEtudiants: (formData) =>
        apiClient.post('/admin/import', formData, {
            headers: { 'Content-Type': 'multipart/form-data' },
        }),
    previewImport: (formData) =>
        apiClient.post('/admin/preview', formData, {
            headers: { 'Content-Type': 'multipart/form-data' },
        }),
    importUnites: (formData) =>
        apiClient.post('/admin/unites/import', formData, {
            headers: { 'Content-Type': 'multipart/form-data' },
        }),
    previewUnites: (formData) =>
        apiClient.post('/admin/unites/preview', formData, {
            headers: { 'Content-Type': 'multipart/form-data' },
        }),
    importFormateurs: (formData) =>
        apiClient.post('/admin/formateurs/import', formData, {
            headers: { 'Content-Type': 'multipart/form-data' },
        }),
    previewFormateurs: (formData) =>
        apiClient.post('/admin/formateurs/preview', formData, {
            headers: { 'Content-Type': 'multipart/form-data' },
        }),
};