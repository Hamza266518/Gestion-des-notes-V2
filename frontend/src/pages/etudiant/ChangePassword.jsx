import { useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { passwordApi } from '../../api/password';
import { validatePassword, getPasswordRequirements, getPasswordStrength } from '../../utils/passwordValidator';
import { FiEye, FiEyeOff, FiCheckCircle, FiArrowLeft } from 'react-icons/fi';
import '../../css/components.css';
import '../../css/layout.css';

export function ChangePassword() {
  const navigate = useNavigate();
  const [formData, setFormData] = useState({
    oldPassword: '',
    newPassword: '',
    confirmPassword: '',
  });
  const [showPasswords, setShowPasswords] = useState({ old: false, new: false });
  const [errors, setErrors] = useState([]);
  const [generalError, setGeneralError] = useState('');
  const [successMessage, setSuccessMessage] = useState('');
  const [loading, setLoading] = useState(false);

  const handleInputChange = (e) => {
    const { name, value } = e.target;
    setFormData((prev) => ({ ...prev, [name]: value }));
    setErrors([]);
    setGeneralError('');
  };

  const togglePasswordVisibility = (field) => {
    setShowPasswords((prev) => ({ ...prev, [field]: !prev[field] }));
  };

  const handleSubmit = async (e) => {
    e.preventDefault();
    setGeneralError('');
    setErrors([]);
    setSuccessMessage('');

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
      setErrors(['Les nouveaux mots de passe ne correspondent pas']);
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
        setSuccessMessage('Mot de passe modifié avec succès!');
        setFormData({ oldPassword: '', newPassword: '', confirmPassword: '' });
        setTimeout(() => navigate('/etudiant/bulletin'), 2000);
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

  const requirements = getPasswordRequirements();
  const strength = getPasswordStrength(formData.newPassword);

  return (
    <div className="page">
      <div className="page-header">
        <h1>Changer le mot de passe</h1>
      </div>

      <div className="card" style={{ maxWidth: 560 }}>
        <div className="card-body">
          <p style={{ margin: '0 0 24px', color: 'var(--gray-500)', fontSize: 14 }}>
            Modifiez votre mot de passe en toute sécurité
          </p>

          <form onSubmit={handleSubmit}>
            {successMessage && (
              <div className="alert alert-success">
                <FiCheckCircle size={16} /> {successMessage}
              </div>
            )}

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
                  type={showPasswords.old ? 'text' : 'password'}
                  name="oldPassword"
                  className="form-input"
                  value={formData.oldPassword}
                  onChange={handleInputChange}
                  placeholder="Entrez votre ancien mot de passe"
                  disabled={loading}
                  required
                  style={{ paddingRight: 40 }}
                />
                <button
                  type="button"
                  className="btn-ghost"
                  onClick={() => togglePasswordVisibility('old')}
                  disabled={loading}
                  style={{
                    position: 'absolute', right: 8, background: 'none', border: 'none',
                    cursor: 'pointer', color: 'var(--gray-400)', padding: 6, display: 'flex'
                  }}
                  tabIndex={-1}
                >
                  {showPasswords.old ? <FiEyeOff size={18} /> : <FiEye size={18} />}
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
                  type={showPasswords.new ? 'text' : 'password'}
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
                  onClick={() => togglePasswordVisibility('new')}
                  disabled={loading}
                  style={{
                    position: 'absolute', right: 8, background: 'none', border: 'none',
                    cursor: 'pointer', color: 'var(--gray-400)', padding: 6, display: 'flex'
                  }}
                  tabIndex={-1}
                >
                  {showPasswords.new ? <FiEyeOff size={18} /> : <FiEye size={18} />}
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
              <label className="form-label">Confirmer le nouveau mot de passe</label>
              <div style={{ position: 'relative', display: 'flex', alignItems: 'center' }}>
                <input
                  type={showPasswords.new ? 'text' : 'password'}
                  name="confirmPassword"
                  className="form-input"
                  value={formData.confirmPassword}
                  onChange={handleInputChange}
                  placeholder="Confirmez votre nouveau mot de passe"
                  disabled={loading}
                  required
                  style={{ paddingRight: 40 }}
                />
                <button
                  type="button"
                  onClick={() => togglePasswordVisibility('new')}
                  disabled={loading}
                  style={{
                    position: 'absolute', right: 8, background: 'none', border: 'none',
                    cursor: 'pointer', color: 'var(--gray-400)', padding: 6, display: 'flex'
                  }}
                  tabIndex={-1}
                >
                  {showPasswords.new ? <FiEyeOff size={18} /> : <FiEye size={18} />}
                </button>
              </div>
            </div>

            <div style={{ display: 'flex', justifyContent: 'flex-end', gap: 10, marginTop: 24 }}>
              <button
                type="button"
                className="btn btn-ghost"
                onClick={() => navigate('/etudiant/bulletin')}
                disabled={loading}
              >
                <FiArrowLeft size={14} /> Annuler
              </button>
              <button
                type="submit"
                className="btn btn-primary"
                disabled={loading || !formData.oldPassword || !formData.newPassword || !formData.confirmPassword}
              >
                {loading ? 'Traitement...' : 'Changer le mot de passe'}
              </button>
            </div>
          </form>
        </div>
      </div>
    </div>
  );
}
