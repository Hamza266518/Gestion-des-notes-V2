import { useState } from 'react';
import Modal from '../common/Modal';
import { passwordApi } from '../../api/password';
import { validatePassword, getPasswordRequirements, getPasswordStrength } from '../../utils/passwordValidator';
import { FiEye, FiEyeOff, FiCheckCircle } from 'react-icons/fi';

export function PasswordChangeModal({ onSuccess }) {
  const [formData, setFormData] = useState({
    oldPassword: '',
    newPassword: '',
    confirmPassword: '',
  });
  const [showOld, setShowOld] = useState(false);
  const [showNew, setShowNew] = useState(false);
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

  const handleSubmit = async (e) => {
    e.preventDefault();
    setGeneralError('');
    setErrors([]);

    if (!formData.oldPassword) {
      setErrors(["L'ancien mot de passe est requis"]);
      return;
    }

    const validation = validatePassword(formData.newPassword);
    if (!validation.isValid) {
      setErrors(validation.errors);
      return;
    }

    if (formData.newPassword !== formData.confirmPassword) {
      setErrors(['Les mots de passe ne correspondent pas']);
      return;
    }

    if (formData.oldPassword === formData.newPassword) {
      setErrors(['Le nouveau mot de passe doit être différent de l\'ancien']);
      return;
    }

    try {
      setLoading(true);
      const response = await passwordApi.changePassword(
        formData.oldPassword,
        formData.newPassword,
        formData.confirmPassword
      );
      if (response.data.success) {
        setSuccess(true);
        setTimeout(() => onSuccess?.(), 2000);
      }
    } catch (err) {
      const data = err.response?.data;
      if (data?.errors) {
        const fieldErrors = Object.values(data.errors).flat();
        setErrors(fieldErrors);
        setGeneralError('');
      } else {
        setGeneralError(data?.message || 'Erreur lors du changement de mot de passe');
      }
    } finally {
      setLoading(false);
    }
  };

  if (success) {
    return (
      <Modal open={true} onClose={() => {}} title="">
        <div style={{ textAlign: 'center', padding: '24px 16px' }}>
          <FiCheckCircle size={56} style={{ color: 'var(--success)', marginBottom: 16 }} />
          <h3 style={{ margin: '0 0 8px', color: 'var(--success)', fontSize: 18 }}>
            Mot de passe modifié avec succès!
          </h3>
          <p style={{ margin: 0, color: 'var(--gray-500)', fontSize: 14 }}>
            Vous pouvez maintenant accéder à votre espace étudiant.
          </p>
        </div>
      </Modal>
    );
  }

  const requirements = getPasswordRequirements();
  const strength = getPasswordStrength(formData.newPassword);

  return (
    <Modal open={true} onClose={() => {}} title="Changement de mot de passe requis">
      <p style={{ margin: '0 0 20px', color: 'var(--gray-500)', fontSize: 14 }}>
        Veuillez changer votre mot de passe lors de votre première connexion
      </p>

      <form onSubmit={handleSubmit}>
        {generalError && (
          <div className="alert alert-error">{generalError}</div>
        )}

        {errors.length > 0 && (
          <div className="alert alert-error">
            {errors.map((error, idx) => (
              <div key={idx}>• {error}</div>
            ))}
          </div>
        )}

        <div className="form-group">
          <label className="form-label">Ancien mot de passe</label>
          <div style={{ position: 'relative', display: 'flex', alignItems: 'center' }}>
            <input
              type={showOld ? 'text' : 'password'}
              name="oldPassword"
              className="form-input"
              value={formData.oldPassword}
              onChange={handleInputChange}
              placeholder="Votre mot de passe initial"
              disabled={loading}
              required
              style={{ paddingRight: 40 }}
            />
            <button
              type="button"
              onClick={() => setShowOld(!showOld)}
              disabled={loading}
              style={{
                position: 'absolute', right: 10, background: 'none', border: 'none',
                cursor: 'pointer', color: 'var(--gray-400)', padding: 4, display: 'flex'
              }}
              tabIndex={-1}
            >
              {showOld ? <FiEyeOff size={18} /> : <FiEye size={18} />}
            </button>
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
            {requirements.map((req, idx) => {
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
              type={showNew ? 'text' : 'password'}
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
              onClick={() => setShowNew(!showNew)}
              disabled={loading}
              style={{
                position: 'absolute', right: 10, background: 'none', border: 'none',
                cursor: 'pointer', color: 'var(--gray-400)', padding: 4, display: 'flex'
              }}
              tabIndex={-1}
            >
              {showNew ? <FiEyeOff size={18} /> : <FiEye size={18} />}
            </button>
          </div>

          {formData.newPassword && strength.level > 0 && (
            <div style={{ marginTop: 8 }}>
              <div style={{
                height: 6, borderRadius: 3, background: 'var(--gray-200)',
                overflow: 'hidden', marginBottom: 4
              }}>
                <div style={{
                  height: '100%', width: `${strength.score}%`,
                  borderRadius: 3, transition: 'width 0.3s, background 0.3s',
                  background: strength.level === 1 ? '#e74c3c'
                    : strength.level === 2 ? '#f39c12'
                    : strength.level === 3 ? '#2ecc71'
                    : '#27ae60'
                }} />
              </div>
              <span style={{
                fontSize: 11, fontWeight: 600,
                color: strength.level === 1 ? '#e74c3c'
                  : strength.level === 2 ? '#f39c12'
                  : strength.level === 3 ? '#2ecc71'
                  : '#27ae60'
              }}>
                {strength.label}
              </span>
            </div>
          )}
        </div>

        <div className="form-group" style={{ marginBottom: 0 }}>
          <label className="form-label">Confirmer le mot de passe</label>
          <div style={{ position: 'relative', display: 'flex', alignItems: 'center' }}>
            <input
              type={showNew ? 'text' : 'password'}
              name="confirmPassword"
              className="form-input"
              value={formData.confirmPassword}
              onChange={handleInputChange}
              placeholder="Confirmez votre mot de passe"
              disabled={loading}
              required
              style={{ paddingRight: 40 }}
            />
            <button
              type="button"
              onClick={() => setShowNew(!showNew)}
              disabled={loading}
              style={{
                position: 'absolute', right: 10, background: 'none', border: 'none',
                cursor: 'pointer', color: 'var(--gray-400)', padding: 4, display: 'flex'
              }}
              tabIndex={-1}
            >
              {showNew ? <FiEyeOff size={18} /> : <FiEye size={18} />}
            </button>
          </div>
        </div>

        <div style={{ display: 'flex', justifyContent: 'flex-end', gap: 8, marginTop: 20 }}>
          <button
            type="submit"
            className="btn btn-primary"
            disabled={loading || !formData.oldPassword || !formData.newPassword || !formData.confirmPassword}
          >
            {loading ? 'Traitement...' : 'Changer le mot de passe'}
          </button>
        </div>
      </form>
    </Modal>
  );
}
