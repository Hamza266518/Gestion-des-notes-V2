import apiClient from './apiClient';

// Soutenance (NSTI): the jury criteria are Note rows on the criteria controls of
// the "Soutenance" unit. Only final-year programmes run one, so the page builds
// its filters from `programmes()` and only then reads a jury sheet.
export const soutenanceApi = {
  // The filières/années that actually have a soutenance, criteria included.
  programmes: () =>
    apiClient.get('/admin/soutenance/programmes'),

  status: (params) =>
    apiClient.get('/admin/soutenance/status', { params }),

  scan: (formData) =>
    apiClient.post('/admin/soutenance/scan', formData, {
      headers: { 'Content-Type': 'multipart/form-data' },
    }),

  confirm: (data) =>
    apiClient.post('/admin/soutenance/confirm', data),

  // Manual student lookup for a row the scan could not match. The endpoint is
  // the generic "search students of a filière/année/groupe" search shared with
  // the exam scanner; it already reports the publication lock per student.
  searchEtudiants: (params) =>
    apiClient.get('/admin/scan-examens/search-etudiants', { params }),
};
