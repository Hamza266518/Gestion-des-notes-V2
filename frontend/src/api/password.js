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

  // Password recovery (unauthenticated), in three steps:
  // 1. identifier (username or contact email) -> code sent to the verified email
  // 2. the code is checked without being consumed
  // 3. the new password is set (and the code is consumed)
  requestRecoveryCode: (identifier) => api.post('/auth/password/forgot', { username: identifier }),
  verifyRecoveryCode: (identifier, code) =>
    api.post('/auth/password/verify-code', { username: identifier, code }),
  resetPassword: (identifier, code, newPassword, confirmPassword) =>
    api.post('/auth/password/reset', {
      username: identifier,
      code,
      new_password: newPassword,
      confirm_password: confirmPassword,
    }),
};