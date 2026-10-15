import { useState } from 'react';
import Modal from '../common/Modal';
import { passwordApi } from '../../api/password';
import { validatePassword, getPasswordRequirements } from '../../utils/passwordValidator';
import { FiEye, FiEyeOff, FiArrowLeft, FiCheck, FiUser, FiMail } from 'react-icons/fi';

const GENERIC_FAILURE = 'Identifiant ou code invalide. Veuillez recommencer.';

export function ForgotPasswordModal({ onClose, onSuccess }) {
  const [phase, setPhase] = useState(1);
  const [formData, setFormData] = useState({
    username: '',
    code: '',
    newPassword: '',
    confirmPassword: '',
  });
  const [info, setInfo] = useState('');
  const [showNewPassword, setShowNewPassword] = useState(false);
  const [errors, setErrors] = useState([]);
  const [generalError, setGeneralError] = useState('');
  const [loading, setLoading] = useState(false);
  const [success, setSuccess] = useState(false);

  const handleInputChange = (e) => {
    const { name, value } = e.target;
    setFormData((prev) => ({ ...prev, [name]: value }));
    setErrors([]);
    setGeneralError('');
  };

  const handleSendCode = async (e) => {
    e.preventDefault();
    setGeneralError('');
    setErrors([]);

    const username = formData.username.trim().toLowerCase();

    if (!username) {
      setErrors(['Votre identifiant est requis']);
      return;
    }

    try {
      setLoading(true);
      const response = await passwordApi.requestRecoveryCode(username);

      // Message volontairement identique pour tout identifiant : ne révèle rien.
      setInfo(
        response.data?.message ||
          'Si un compte correspond à cet identifiant et possède une adresse email vérifiée, un code vient d\'être envoyé.'
      );
      setFormData((prev) => ({ ...prev, username }));
      setPhase(2);
    } catch (err) {
      if (err.response?.status === 429) {
        setGeneralError('Trop de demandes. Veuillez patienter avant de réessayer.');
      } else {
        setGeneralError(err.response?.data?.message || 'Impossible d\'envoyer le code. Veuillez réessayer.');
      }
    } finally {
      setLoading(false);
    }
  };

  const handleResetPassword = async (e) => {
    e.preventDefault();
    setGeneralError('');
    setErrors([]);

    const newErrors = [];
    if (!formData.code.trim()) newErrors.push('Le code reçu par email est requis');
    if (!formData.code.trim().match(/^\d{6}$/)) newErrors.push('Le code est composé de 6 chiffres');

    const validation = validatePassword(formData.newPassword);
    if (!validation.isValid) newErrors.push(...validation.errors);

    if (formData.newPassword !== formData.confirmPassword) {
      newErrors.push('Les mots de passe ne correspondent pas');
    }

    if (newErrors.length > 0) {
      setErrors(newErrors);
      return;
    }

    try {
      setLoading(true);
      await passwordApi.resetPassword(
        formData.username.trim().toLowerCase(),
        formData.code.trim(),
        formData.newPassword,
        formData.confirmPassword
      );

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
      <Modal open={true} onClose={onClose} title="">
        <div style={{ textAlign: 'center', padding: '24px 16px' }}>
          <div style={{
            width: 64, height: 64, borderRadius: '50%', background: 'var(--success)',
            color: 'white', display: 'flex', alignItems: 'center', justifyContent: 'center',
            margin: '0 auto 16px', fontSize: 32
          }}>
            <FiCheck />
          </div>
          <h3 style={{ margin: '0 0 8px', color: 'var(--success)', fontSize: 18 }}>
            Mot de passe réinitialisé!
          </h3>
          <p style={{ margin: 0, color: 'var(--gray-500)', fontSize: 14 }}>
            Veuillez vous connecter avec votre nouveau mot de passe.
          </p>
        </div>
      </Modal>
    );
  }

  return (
    <Modal open={true} onClose={onClose} title="Récupération de mot de passe">
      {generalError && (
        <div className="alert alert-error" style={{ marginBottom: 16 }}>{generalError}</div>
      )}

      {errors.length > 0 && (
        <div className="alert alert-error" style={{ marginBottom: 16 }}>
          {errors.map((error, idx) => (
            <div key={idx}>• {error}</div>
          ))}
        </div>
      )}

      {phase === 1 && (
        <form onSubmit={handleSendCode}>
          <h3 style={{ margin: '0 0 6px', fontSize: 16, fontWeight: 600, color: 'var(--gray-800)' }}>
            Identifiant du compte
          </h3>
          <p style={{ margin: '0 0 20px', color: 'var(--gray-500)', fontSize: 13 }}>
            Un code de 6 chiffres sera envoyé à l'adresse email vérifiée de votre compte.
            Sans adresse email vérifiée, contactez l'administrateur.
          </p>

          <div className="form-group">
            <label className="form-label">Identifiant</label>
            <div style={{ position: 'relative', display: 'flex', alignItems: 'center' }}>
              <input
                type="text"
                name="username"
                className="form-input"
                value={formData.username}
                onChange={handleInputChange}
                placeholder="prenom.nom"
                autoComplete="username"
                autoCapitalize="none"
                spellCheck="false"
                disabled={loading}
                required
                style={{ paddingLeft: 38 }}
              />
              <FiUser
                size={18}
                style={{
                  position: 'absolute', left: 12, color: 'var(--gray-400)',
                  pointerEvents: 'none'
                }}
              />
            </div>
          </div>

          <div style={{ display: 'flex', justifyContent: 'flex-end', gap: 8, marginTop: 20 }}>
            <button
              type="button"
              className="btn btn-ghost"
              onClick={onClose}
              disabled={loading}
            >
              Annuler
            </button>
            <button
              type="submit"
              className="btn btn-primary"
              disabled={loading || !formData.username.trim()}
            >
              {loading ? 'Envoi...' : 'Envoyer le code'}
            </button>
          </div>
        </form>
      )}

      {phase === 2 && (
        <form onSubmit={handleResetPassword}>
          <h3 style={{ margin: '0 0 6px', fontSize: 16, fontWeight: 600, color: 'var(--gray-800)' }}>
            Code et nouveau mot de passe
          </h3>
          <p style={{ margin: '0 0 16px', color: 'var(--gray-500)', fontSize: 13 }}>
            {info || 'Saisissez le code reçu par email pour réinitialiser votre mot de passe.'}
          </p>

          <div className="form-group">
            <label className="form-label">Code reçu par email</label>
            <div style={{ position: 'relative', display: 'flex', alignItems: 'center' }}>
              <input
                type="text"
                name="code"
                className="form-input"
                value={formData.code}
                onChange={handleInputChange}
                placeholder="000000"
                inputMode="numeric"
                maxLength={6}
                autoComplete="one-time-code"
                disabled={loading}
                required
                style={{ paddingLeft: 38, letterSpacing: 4 }}
              />
              <FiMail
                size={18}
                style={{
                  position: 'absolute', left: 12, color: 'var(--gray-400)',
                  pointerEvents: 'none'
                }}
              />
            </div>
          </div>

          <div style={{
            background: 'var(--gray-50)', border: '1px solid var(--gray-200)',
            borderRadius: 'var(--border-radius)', padding: 14, marginBottom: 16
          }}>
            <h4 style={{
              fontSize: 12, fontWeight: 600, color: 'var(--gray-600)',
              margin: '0 0 8px', textTransform: 'uppercase', letterSpacing: '0.5px'
            }}>
              Exigences du mot de passe:
            </h4>
            <ul style={{ listStyle: 'none', padding: 0, margin: 0, display: 'flex', flexDirection: 'column', gap: 6 }}>
              {getPasswordRequirements().map((req, idx) => {
                const met = req.check(formData.newPassword);
                return (
                  <li key={idx} style={{
                    fontSize: 13, color: met ? 'var(--success)' : 'var(--gray-400)',
                    display: 'flex', alignItems: 'center', gap: 8, transition: 'color 0.3s'
                  }}>
                    <span style={{ fontSize: 14, fontWeight: 700 }}>{met ? '✓' : '○'}</span>
                    {req.text}
                  </li>
                );
              })}
            </ul>
          </div>

          <div className="form-group">
            <label className="form-label">Nouveau mot de passe</label>
            <div style={{ position: 'relative', display: 'flex', alignItems: 'center' }}>
              <input
                type={showNewPassword ? 'text' : 'password'}
                name="newPassword"
                className="form-input"
                value={formData.newPassword}
                onChange={handleInputChange}
                placeholder="Entrez votre nouveau mot de passe"
                disabled={loading}
                required
                style={{ paddingRight: 40 }}
              />
              <button
                type="button"
                onClick={() => setShowNewPassword(!showNewPassword)}
                disabled={loading}
                style={{
                  position: 'absolute', right: 10, background: 'none', border: 'none',
                  cursor: 'pointer', color: 'var(--gray-400)', padding: 4, display: 'flex'
                }}
                tabIndex={-1}
              >
                {showNewPassword ? <FiEyeOff size={18} /> : <FiEye size={18} />}
              </button>
            </div>
          </div>

          <div className="form-group" style={{ marginBottom: 0 }}>
            <label className="form-label">Confirmer le mot de passe</label>
            <input
              type={showNewPassword ? 'text' : 'password'}
              name="confirmPassword"
              className="form-input"
              value={formData.confirmPassword}
                onChange={handleInputChange}
              placeholder="Confirmez votre mot de passe"
              disabled={loading}
              required
            />
          </div>

          <div style={{ display: 'flex', justifyContent: 'flex-end', gap: 8, marginTop: 20 }}>
            <button
              type="button"
              className="btn btn-ghost"
              onClick={() => {
                setPhase(1);
                setFormData((prev) => ({ ...prev, code: '', newPassword: '', confirmPassword: '' }));
                setInfo('');
                setGeneralError('');
              }}
              disabled={loading}
            >
              <FiArrowLeft size={14} /> Retour
            </button>
            <button
              type="submit"
              className="btn btn-primary"
              disabled={loading || !formData.code.trim() || !formData.newPassword || !formData.confirmPassword}
            >
              {loading ? 'Traitement...' : 'Réinitialiser'}
            </button>
          </div>
        </form>
      )}

      <div style={{
        display: 'flex', alignItems: 'center', justifyContent: 'center',
        marginTop: 24, paddingTop: 16, borderTop: '1px solid var(--gray-100)'
      }}>
        <div style={{
          width: 32, height: 32, borderRadius: '50%',
          background: phase >= 1 ? 'var(--primary)' : 'var(--gray-200)',
          color: phase >= 1 ? 'white' : 'var(--gray-400)',
          display: 'flex', alignItems: 'center', justifyContent: 'center',
          fontWeight: 600, fontSize: 13, transition: 'all 0.3s'
        }}>1</div>
        <div style={{
          width: 40, height: 2, background: phase > 1 ? 'var(--primary)' : 'var(--gray-200)',
          margin: '0 8px', transition: 'background 0.3s'
        }} />
        <div style={{
          width: 32, height: 32, borderRadius: '50%',
          background: phase >= 2 ? 'var(--primary)' : 'var(--gray-200)',
          color: phase >= 2 ? 'white' : 'var(--gray-400)',
          display: 'flex', alignItems: 'center', justifyContent: 'center',
          fontWeight: 600, fontSize: 13, transition: 'all 0.3s'
        }}>2</div>
      </div>
    </Modal>
  );
}