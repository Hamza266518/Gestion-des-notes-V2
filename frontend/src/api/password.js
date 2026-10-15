import api from './apiClient';

export const passwordApi = {
  // Check if user needs to change password on first login
  checkFirstLogin: () => api.get('/etudiant/password/check-first-login'),

  // Change password (authenticated, every role)
  changePassword: (oldPassword, newPassword, confirmPassword) =>
    api.post('/account/password', {
      old_password: oldPassword,
      new_password: newPassword,
      confirm_password: confirmPassword,
    }),

  // Password recovery (unauthenticated): username -> code sent to the verified email
  requestRecoveryCode: (username) => api.post('/auth/password/forgot', { username }),
  resetPassword: (username, code, newPassword, confirmPassword) =>
    api.post('/auth/password/reset', {
      username,
      code,
      new_password: newPassword,
      confirm_password: confirmPassword,
    }),
};