export function checkLength(password) {
  return !!password && password.length >= 10;
}

export function checkUppercase(password) {
  return !!password && /[A-Z]/.test(password);
}

export function checkLowercase(password) {
  return !!password && /[a-z]/.test(password);
}

export function checkDigit(password) {
  return !!password && /\d/.test(password);
}

export function checkSymbol(password) {
  return !!password && /[@$!%*?&\-_.+]/.test(password);
}

export function validatePassword(password) {
  const errors = [];

  if (!password) {
    errors.push('Le mot de passe est requis');
    return { isValid: false, errors };
  }

  if (!checkLength(password)) {
    errors.push('Au moins 10 caractères');
  }

  if (!checkUppercase(password)) {
    errors.push('Au moins une lettre majuscule');
  }

  if (!checkLowercase(password)) {
    errors.push('Au moins une lettre minuscule');
  }

  if (!checkDigit(password)) {
    errors.push('Au moins un chiffre');
  }

  if (!checkSymbol(password)) {
    errors.push('Au moins un symbole (@$!%*?&-_.+)');
  }

  return { isValid: errors.length === 0, errors };
}

export function passwordsMatch(password1, password2) {
  return password1 === password2;
}

export function getPasswordRequirements() {
  return [
    { text: '10 caractères minimum', check: checkLength },
    { text: 'Au moins une majuscule', check: checkUppercase },
    { text: 'Au moins une minuscule', check: checkLowercase },
    { text: 'Au moins un chiffre', check: checkDigit },
    { text: 'Au moins un symbole (@, $, !, %, *, ?, &, -, _, ., +)', check: checkSymbol },
  ];
}

export function getPasswordStrength(password) {
  if (!password) return { score: 0, label: '', level: 0 };

  let score = 0;
  if (checkLength(password)) score += 20;
  if (password.length >= 14) score += 10;
  if (checkUppercase(password)) score += 20;
  if (checkLowercase(password)) score += 20;
  if (checkDigit(password)) score += 15;
  if (checkSymbol(password)) score += 15;

  if (score >= 90) return { score, label: 'Très fort', level: 4 };
  if (score >= 65) return { score, label: 'Fort', level: 3 };
  if (score >= 40) return { score, label: 'Moyen', level: 2 };
  return { score, label: 'Faible', level: 1 };
}
