import apiClient from './apiClient';

export const scanApi = {
  // formateur scans note papers
  scan: (formData) =>
      apiClient.post('/formateur/scan', formData, {
          headers: { 'Content-Type': 'multipart/form-data' },
      }),

  // formateur confirms notes
  confirm: (data) =>
      apiClient.post('/formateur/confirm', data),

  // formateur notes list
  getNotes: (params) =>
      apiClient.get('/formateur/notes', { params }),

  // admin scans CIN
  scanCin: (formData) =>
      apiClient.post('/admin/scan-cin', formData, {
          headers: { 'Content-Type': 'multipart/form-data' },
      }),
  confirmScanCin: (data) =>
      apiClient.post('/admin/scan-cin/confirm', data),

  // admin scans unites document
  scanUnitesDocument: (file, filiereId, semestre) => {
    const fd = new FormData();
    fd.append('image', file);
    fd.append('filiere_id', filiereId);
    fd.append('semestre', semestre);
    return apiClient.post('/admin/scan-unites', fd);
  },
  confirmScanUnites: (data) =>
    apiClient.post('/admin/scan-unites/confirm', data),
};