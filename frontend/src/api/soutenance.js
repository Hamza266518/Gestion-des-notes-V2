import apiClient from './apiClient';

// Soutenance (NSTI): the jury criteria are Note rows on the "Soutenance"
// unit's direct controls. The scan reads the whole jury sheet (one line per
// student, one grade per criterion); the confirm writes those Note rows.
export const soutenanceApi = {
    status: (params) =>
        apiClient.get('/admin/soutenance/status', { params }),
    scan: (formData) =>
        apiClient.post('/admin/soutenance/scan', formData, {
            headers: { 'Content-Type': 'multipart/form-data' },
        }),
    confirm: (data) =>
        apiClient.post('/admin/soutenance/confirm', data),
};
