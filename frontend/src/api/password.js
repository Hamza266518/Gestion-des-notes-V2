import api from './apiClient';

export const passwordApi = {
  // Check if user needs to change password on first login
  checkFirstLogin: () => api.get('/etudiant/password/check-first-login'),
  
  // Change password (authenticated users)
  changePassword: (oldPassword, newPassword, confirmPassword) =>
    api.post('/etudiant/password/change', {
      old_password: oldPassword,
      new_password: newPassword,
      confirm_password: confirmPassword,
    }),
  
  // Verify old password and CIN for forgot password (unauthenticated)
  verifyForgotPassword: (oldPassword, cin) =>
    api.post('/etudiant/password/forgot-verify', {
      old_password: oldPassword,
      cin: cin,
    }),
  
  // Reset password using forgot password token (unauthenticated)
  resetForgotPassword: (tempToken, newPassword, confirmPassword) =>
    api.post('/etudiant/password/reset', {
      temp_token: tempToken,
      new_password: newPassword,
      confirm_password: confirmPassword,
    }),
};
