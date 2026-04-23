import apiClient from './apiClient';

export const formateurApi = {
    getNotes: () => apiClient.get('/formateur/notes'),
    scanSheet: (data) => apiClient.post('/formateur/scan', data),
    confirmScan: (data) => apiClient.post('/formateur/confirm', data),
};

export const etudiantApi = {
    getBulletin: () => apiClient.get('/etudiant/bulletin'),
};
