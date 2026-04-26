import apiClient from './apiClient';

export const portalsApi = {
    getBulletin: () =>
        apiClient.get('/etudiant/bulletin'),
};