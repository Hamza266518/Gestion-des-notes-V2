import { useState } from 'react';
import Modal from '../common/Modal';
import { passwordApi } from '../../api/password';
import { validatePassword, getPasswordRequirements } from '../../utils/passwordValidator';
import { FiEye, FiEyeOff, FiArrowLeft, FiCheck } from 'react-icons/fi';

const GENERIC_FAILURE = 'Identifiant ou code invalide. Veuillez recommencer.';
const STEPS = ['Identifiant', 'Vérification', 'Mot de passe'];

const notice = {
  padding: '10px 12px',
  borderRadius: 'var(--border-radius)',
  fontSize: 12.5,
  lineHeight: 1.55,
  marginBottom: 16,
};
const noticeError = { ...notice, background: 'var(--danger-light)', color: 'var(--danger)' };
const noticeInfo = { ...notice, background: 'var(--primary-faint)', color: 'var(--primary)' };

export function ForgotPasswordModal({ onClose, onSuccess }) {
  const [phase, setPhase] = useState(1);
  const [identifier, setIdentifier] = useState('');
  const [code, setCode] = useState('');
  const [newPassword, setNewPassword] = useState('');
  const [confirmPassword, setConfirmPassword] = useState('');
  const [showPassword, setShowPassword] = useState(false);
  const [info, setInfo] = useState('');
  const [errors, setErrors] = useState([]);
  const [generalError, setGeneralError] = useState('');
  const [loading, setLoading] = useState(false);
  const [success, setSuccess] = useState(false);

  const requirements = getPasswordRequirements();

  const clearMessages = () => {
    setErrors([]);
    setGeneralError('');
  };

  const handleIdentify = async (e) => {
    e.preventDefault();
    clearMessages();

    const value = identifier.trim();
    if (!value) {
      setErrors(['Saisissez votre identifiant ou votre adresse email']);
      return;
    }

    try {
      setLoading(true);
      const res = await passwordApi.requestRecoveryCode(value);
      setIdentifier(value);
      setInfo(res.data?.message || 'Un code de vérification a été envoyé sur l\'adresse email du compte.');
      setPhase(2);
    } catch (err) {
      if (err.response?.status === 429) {
        setGeneralError('Trop de tentatives. Veuillez patienter avant de réessayer.');
      } else {
        setGeneralError(err.response?.data?.message || "Impossible d'envoyer le code. Veuillez réessayer.");
      }
    } finally {
      setLoading(false);
    }
  };

  const handleVerifyCode = async (e) => {
    e.preventDefault();
    clearMessages();

    if (!/^\d{6}$/.test(code)) {
      setErrors(['Le code est composé de 6 chiffres']);
      return;
    }

    try {
      setLoading(true);
      await passwordApi.verifyRecoveryCode(identifier, code);
      setInfo('Code vérifié. Choisissez maintenant votre nouveau mot de passe.');
      setPhase(3);
    } catch (err) {
      setGeneralError(err.response?.data?.message || GENERIC_FAILURE);
    } finally {
      setLoading(false);
    }
  };

  const handleResend = async () => {
    clearMessages();
    try {
      setLoading(true);
      const res = await passwordApi.requestRecoveryCode(identifier);
      setInfo(res.data?.message || 'Un nouveau code a été envoyé.');
    } catch (err) {
      setGeneralError(err.response?.data?.message || "Impossible d'envoyer le code.");
    } finally {
      setLoading(false);
    }
  };

  const handleResetPassword = async (e) => {
    e.preventDefault();
    clearMessages();

    const nextErrors = [];
    const validation = validatePassword(newPassword);
    if (!validation.isValid) nextErrors.push(...validation.errors);
    if (newPassword !== confirmPassword) nextErrors.push('Les mots de passe ne correspondent pas');
    if (nextErrors.length > 0) {
      setErrors(nextErrors);
      return;
    }

    try {
      setLoading(true);
      await passwordApi.resetPassword(identifier, code, newPassword, confirmPassword);
      setSuccess(true);
      setTimeout(() => onSuccess?.(), 2000);
    } catch (err) {
      setGeneralError(err.response?.data?.message || GENERIC_FAILURE);
    } finally {
      setLoading(false);
    }
  };

  if (success) {
    return (
      <Modal open onClose={onClose} title="" style={{ maxWidth: 420 }}>
        <div style={{ textAlign: 'center', padding: '16px 8px' }}>
          <div style={{
            width: 52, height: 52, borderRadius: '50%', background: 'var(--success-light)',
            color: 'var(--success)', display: 'flex', alignItems: 'center', justifyContent: 'center',
            margin: '0 auto 14px', fontSize: 26,
          }}>
            <FiCheck />
          </div>
          <h3 style={{ margin: '0 0 6px', fontSize: 17, fontWeight: 700, color: 'var(--gray-800)' }}>
            Mot de passe réinitialisé
          </h3>
          <p style={{ margin: 0, color: 'var(--gray-500)', fontSize: 13.5 }}>
            Vous pouvez maintenant vous connecter avec votre nouveau mot de passe.
          </p>
        </div>
      </Modal>
    );
  }

  return (
    <Modal open onClose={onClose} title="Mot de passe oublié" style={{ maxWidth: 440 }}>
      {generalError && <div style={noticeError}>{generalError}</div>}
      {errors.length > 0 && (
        <div style={noticeError}>
          {errors.map((error, index) => <div key={index}>{error}</div>)}
        </div>
      )}

      {phase === 1 && (
        <form onSubmit={handleIdentify}>
          <h3 style={{ margin: '0 0 4px', fontSize: 15, fontWeight: 700, color: 'var(--gray-800)' }}>
            Identifiez votre compte
          </h3>
          <p style={{ margin: '0 0 18px', color: 'var(--gray-500)', fontSize: 12.5, lineHeight: 1.55 }}>
            Saisissez votre identifiant ou l'adresse email de votre compte. Un code de vérification
            y sera envoyé. Sans adresse email vérifiée, contactez l'administrateur.
          </p>
          <div className="form-group">
            <label className="form-label">Identifiant ou adresse email</label>
            <input
              type="text"
              className="form-input"
              value={identifier}
              onChange={(e) => { setIdentifier(e.target.value); clearMessages(); }}
              placeholder="prenom.nom ou vous@exemple.com"
              autoComplete="username"
              autoCapitalize="none"
              spellCheck="false"
              disabled={loading}
            />
          </div>
          <div style={{ display: 'flex', justifyContent: 'flex-end', gap: 8, marginTop: 20 }}>
            <button type="button" className="btn btn-ghost" onClick={onClose} disabled={loading}>
              Annuler
            </button>
            <button type="submit" className="btn btn-primary" disabled={loading || !identifier.trim()}>
              {loading ? 'Envoi…' : 'Envoyer le code'}
            </button>
          </div>
        </form>
      )}

      {phase === 2 && (
        <form onSubmit={handleVerifyCode}>
          <h3 style={{ margin: '0 0 4px', fontSize: 15, fontWeight: 700, color: 'var(--gray-800)' }}>
            Vérification de l'email
          </h3>
          <p style={{ margin: '0 0 18px', color: 'var(--gray-500)', fontSize: 12.5, lineHeight: 1.55 }}>
            {info || 'Saisissez le code à 6 chiffres reçu par email.'}
          </p>
          <div className="form-group">
            <label className="form-label">Code de vérification</label>
            <input
              type="text"
              className="form-input"
              value={code}
              onChange={(e) => { setCode(e.target.value.replace(/\D/g, '').slice(0, 6)); clearMessages(); }}
              placeholder="000000"
              inputMode="numeric"
              maxLength={6}
              autoComplete="one-time-code"
              disabled={loading}
              style={{ letterSpacing: 4 }}
            />
          </div>
          <button
            type="button"
            className="btn btn-ghost btn-sm"
            onClick={handleResend}
            disabled={loading}
            style={{ padding: '2px 0', color: 'var(--primary)' }}
          >
            Renvoyer le code
          </button>
          <div style={{ display: 'flex', justifyContent: 'flex-end', gap: 8, marginTop: 20 }}>
            <button
              type="button"
              className="btn btn-ghost"
              onClick={() => { setPhase(1); setCode(''); setInfo(''); clearMessages(); }}
              disabled={loading}
            >
              <FiArrowLeft size={14} /> Retour
            </button>
            <button type="submit" className="btn btn-primary" disabled={loading || code.length !== 6}>
              {loading ? 'Vérification…' : 'Vérifier'}
            </button>
          </div>
        </form>
      )}

      {phase === 3 && (
        <form onSubmit={handleResetPassword}>
          <h3 style={{ margin: '0 0 4px', fontSize: 15, fontWeight: 700, color: 'var(--gray-800)' }}>
            Nouveau mot de passe
          </h3>
          <p style={{ margin: '0 0 18px', color: 'var(--gray-500)', fontSize: 12.5, lineHeight: 1.55 }}>
            {info || 'Choisissez un nouveau mot de passe.'}
          </p>
          <div className="form-group">
            <label className="form-label">Nouveau mot de passe</label>
            <div style={{ position: 'relative' }}>
              <input
                type={showPassword ? 'text' : 'password'}
                className="form-input"
                value={newPassword}
                onChange={(e) => { setNewPassword(e.target.value); clearMessages(); }}
                placeholder="Entrez votre nouveau mot de passe"
                autoComplete="new-password"
                disabled={loading}
                style={{ paddingRight: 38 }}
              />
              <button
                type="button"
                onClick={() => setShowPassword((value) => !value)}
                disabled={loading}
                tabIndex={-1}
                aria-label="Afficher ou masquer"
                style={{
                  position: 'absolute', top: '50%', right: 6, transform: 'translateY(-50%)',
                  background: 'none', border: 'none', cursor: 'pointer',
                  color: 'var(--gray-500)', padding: 4, display: 'flex',
                }}
              >
                {showPassword ? <FiEyeOff size={17} /> : <FiEye size={17} />}
              </button>
            </div>
          </div>
          <div className="form-group">
            <label className="form-label">Confirmer le mot de passe</label>
            <input
              type={showPassword ? 'text' : 'password'}
              className="form-input"
              value={confirmPassword}
              onChange={(e) => { setConfirmPassword(e.target.value); clearMessages(); }}
              placeholder="Confirmez votre mot de passe"
              autoComplete="new-password"
              disabled={loading}
            />
            <ul style={{ listStyle: 'none', padding: 0, margin: '10px 0 0' }}>
              {requirements.map(({ text, check }) => {
                const ok = check(newPassword);
                return (
                  <li key={text} style={{ fontSize: 11.5, lineHeight: 1.7, color: ok ? 'var(--success)' : 'var(--gray-400)' }}>
                    {ok ? '● ' : '○ '}{text}
                  </li>
                );
              })}
            </ul>
          </div>
          <div style={{ display: 'flex', justifyContent: 'flex-end', gap: 8, marginTop: 20 }}>
            <button
              type="button"
              className="btn btn-ghost"
              onClick={() => { setPhase(2); setNewPassword(''); setConfirmPassword(''); setInfo(''); clearMessages(); }}
              disabled={loading}
            >
              <FiArrowLeft size={14} /> Retour
            </button>
            <button
              type="submit"
              className="btn btn-primary"
              disabled={loading || !newPassword || !confirmPassword}
            >
              {loading ? 'Traitement…' : 'Réinitialiser'}
            </button>
          </div>
        </form>
      )}

      <div style={{
        display: 'flex', alignItems: 'center', justifyContent: 'center',
        gap: 8, marginTop: 22, paddingTop: 16, borderTop: '1px solid var(--gray-100)',
      }}>
        {STEPS.map((label, index) => {
          const step = index + 1;
          const done = phase > step;
          const current = phase === step;
          return (
            <div key={label} style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
              {index > 0 && (
                <div style={{ width: 24, height: 2, background: phase > index ? 'var(--primary)' : 'var(--gray-200)' }} />
              )}
              <div
                title={label}
                style={{
                  width: 26, height: 26, borderRadius: '50%', fontSize: 12, fontWeight: 600,
                  display: 'flex', alignItems: 'center', justifyContent: 'center',
                  background: done || current ? 'var(--primary)' : 'var(--gray-100)',
                  color: done || current ? 'var(--white)' : 'var(--gray-400)',
                }}
              >
                {done ? <FiCheck size={13} /> : step}
              </div>
            </div>
          );
        })}
      </div>
    </Modal>
  );
}

export default ForgotPasswordModal;