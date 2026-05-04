/**
 * Centralized API error handler
 * Returns user-friendly error messages based on status code
 */
export const handleApiError = (error, toast, options = {}) => {
  const { showToast = true, redirectOn401 = false } = options;

  // Network error (no internet)
  if (!error.response) {
    return 'Problème de connexion. Vérifiez votre internet.';
  }

  const status = error.response.status;
  const data = error.response.data;
  let message = '';
  let shouldRetry = false;

  switch (status) {
    case 401:
      message = "Session expirée. Veuillez vous reconnecter.";
      if (redirectOn401) {
        setTimeout(() => {
          localStorage.removeItem('token');
          window.location.href = '/login';
        }, 2000);
      }
      break;

    case 403:
      message = "Vous n'avez pas les droits pour effectuer cette action.";
      break;

    case 404:
      message = data?.message || "Données non trouvées. La page a peut-être été supprimée.";
      shouldRetry = true;
      break;

    case 422:
      // Validation errors - extract field-specific messages
      if (data?.errors) {
        const errors = data.errors;
        const firstError = Object.values(errors)[0];
        message = Array.isArray(firstError) ? firstError[0] : firstError;
      } else {
        message = data?.message || "Erreur de validation. Vérifiez les champs.";
      }
      break;

    case 429:
      message = "Trop de requêtes. Veuillez patienter quelques instants.";
      shouldRetry = true;
      break;

    case 500:
    case 502:
    case 503:
      message = "Erreur serveur. L'équipe technique a été notifiée.";
      shouldRetry = true;
      break;

    default:
      message = data?.message || `Erreur ${status}. Veuillez réessayer.`;
      shouldRetry = true;
  }

  // Error logged for debugging (not shown to user)

  if (showToast && toast?.error) toast.error(message);

  return { type: 'api', status, message, shouldRetry, data };
};

/**
 * Extract field-specific validation errors from API response
 */
export const getFieldErrors = (error) => {
  if (!error?.response?.data?.errors) return {};
  return error.response.data.errors;
};

/**
 * Handle timeout errors
 */
export const handleTimeoutError = (toast) => {
  const message = "La requête prend trop de temps. Vérifiez votre connexion ou réessayez plus tard.";
  if (toast?.error) toast.error(message);
  return { type: 'timeout', message, shouldRetry: true };
};

/**
 * Show success message
 */
export const showSuccess = (toast, action = 'Enregistrement') => {
  if (toast?.success) toast.success(`${action} réussi`);
};

/**
 * Show warning message
 */
export const showWarning = (toast, message) => {
  if (toast?.warning) toast.warning(message);
};

/**
 * Show info message
 */
export const showInfo = (toast, message) => {
  if (toast?.info) toast.info(message);
};

export default handleApiError;
