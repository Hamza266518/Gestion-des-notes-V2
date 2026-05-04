import api from './apiClient';

const portalApi = {
  getBulletin(anneeId = null) {
    const params = {};
    if (anneeId) params.annee_academique_id = anneeId;
    return api.get('/etudiant/bulletin', { params });
  },

  getMyInfo() {
    return api.get('/etudiant/me');
  },
};

export default portalApi;
