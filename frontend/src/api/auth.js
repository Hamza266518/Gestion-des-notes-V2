import api from './apiClient';

export const authApi = {
  login: (username, password) => api.post('/auth/login', { username, password }),
  logout: () => api.post('/auth/logout'),
  me: () => api.get('/auth/me'),

  // Mon compte (tous les rôles)
  account: () => api.get('/account'),
  updateEmail: (email, currentPassword) =>
    api.put('/account/email', { email, current_password: currentPassword || undefined }),
  verifyEmail: (code) => api.post('/account/email/verify', { code }),
  resendEmailVerification: () => api.post('/account/email/resend'),
  removeEmail: () => api.delete('/account/email'),
};