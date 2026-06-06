import { useState } from 'react';
import Modal from '../common/Modal';
import { passwordApi } from '../../api/password';
import { validatePassword, getPasswordRequirements } from '../../utils/passwordValidator';
import { FiEye, FiEyeOff, FiArrowLeft, FiCheck } from 'react-icons/fi';

export function ForgotPasswordModal({ onClose, onSuccess }) {
  const [phase, setPhase] = useState(1);
  const [formData, setFormData] = useState({
    oldPassword: '',
    cin: '',
    newPassword: '',
    confirmPassword: '',
  });
  const [tempToken, setTempToken] = useState('');
  const [studentName, setStudentName] = useState('');
  const [showPassword, setShowPassword] = useState(false);
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

  const handleVerifyIdentity = async (e) => {
    e.preventDefault();
    setGeneralError('');
    setErrors([]);

    const newErrors = [];
    if (!formData.oldPassword) newErrors.push("L'ancien mot de passe est requis");
    if (!formData.cin) newErrors.push("Le CIN est requis");

    if (newErrors.length > 0) {
      setErrors(newErrors);
      return;
    }

    try {
      setLoading(true);
      const response = await passwordApi.verifyForgotPassword(
        formData.oldPassword,
        formData.cin
      );

      if (response.data.success) {
        setTempToken(response.data.temp_token);
        setStudentName(response.data.student_name);
        setPhase(2);
      }
    } catch (err) {
      setGeneralError(
        err.response?.data?.message || 'Vérification échouée. Veuillez vérifier vos identifiants.'
      );
    } finally {
      setLoading(false);
    }
  };

  const handleResetPassword = async (e) => {
    e.preventDefault();
    setGeneralError('');
    setErrors([]);

    const validation = validatePassword(formData.newPassword);
    if (!validation.isValid) {
      setErrors(validation.errors);
      return;
    }

    if (formData.newPassword !== formData.confirmPassword) {
      setErrors(['Les mots de passe ne correspondent pas']);
      return;
    }

    try {
      setLoading(true);
      const response = await passwordApi.resetForgotPassword(
        tempToken,
        formData.newPassword,
        formData.confirmPassword
      );

      if (response.data.success) {
        setSuccess(true);
        setTimeout(() => onSuccess?.(), 2000);
      }
    } catch (err) {
      setGeneralError(
        err.response?.data?.message || 'Erreur lors de la réinitialisation du mot de passe'
      );
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
        <form onSubmit={handleVerifyIdentity}>
          <h3 style={{ margin: '0 0 6px', fontSize: 16, fontWeight: 600, color: 'var(--gray-800)' }}>
            Vérification d'identité
          </h3>
          <p style={{ margin: '0 0 20px', color: 'var(--gray-500)', fontSize: 13 }}>
            Entrez votre ancien mot de passe et votre CIN pour confirmer votre identité
          </p>

          <div className="form-group">
            <label className="form-label">Ancien mot de passe</label>
            <div style={{ position: 'relative', display: 'flex', alignItems: 'center' }}>
              <input
                type={showPassword ? 'text' : 'password'}
                name="oldPassword"
                className="form-input"
                value={formData.oldPassword}
                onChange={handleInputChange}
                placeholder="Votre mot de passe actuel"
                disabled={loading}
                required
                style={{ paddingRight: 40 }}
              />
              <button
                type="button"
                onClick={() => setShowPassword(!showPassword)}
                disabled={loading}
                style={{
                  position: 'absolute', right: 10, background: 'none', border: 'none',
                  cursor: 'pointer', color: 'var(--gray-400)', padding: 4, display: 'flex'
                }}
                tabIndex={-1}
              >
                {showPassword ? <FiEyeOff size={18} /> : <FiEye size={18} />}
              </button>
            </div>
          </div>

          <div className="form-group">
            <label className="form-label">Numéro de CIN</label>
            <input
              type="text"
              name="cin"
              className="form-input"
              value={formData.cin}
              onChange={handleInputChange}
              placeholder="Votre numéro CIN"
              disabled={loading}
              required
            />
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
              disabled={loading || !formData.oldPassword || !formData.cin}
            >
              {loading ? 'Vérification...' : 'Vérifier'}
            </button>
          </div>
        </form>
      )}

      {phase === 2 && (
        <form onSubmit={handleResetPassword}>
          <h3 style={{ margin: '0 0 6px', fontSize: 16, fontWeight: 600, color: 'var(--gray-800)' }}>
            Nouveau mot de passe
          </h3>
          <p style={{ margin: '0 0 20px', color: 'var(--gray-500)', fontSize: 13 }}>
            Bienvenue {studentName}! Créez un nouveau mot de passe sécurisé
          </p>

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
                const met = formData.newPassword && validatePassword(formData.newPassword).isValid;
                return (
                  <li key={idx} style={{
                    fontSize: 13, color: met ? 'var(--success)' : 'var(--gray-400)',
                    display: 'flex', alignItems: 'center', gap: 8, transition: 'color 0.3s'
                  }}>
                    {met ? '✓' : '○'}
                    {req}
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
                setFormData((prev) => ({ ...prev, newPassword: '', confirmPassword: '' }));
              }}
              disabled={loading}
            >
              <FiArrowLeft size={14} /> Retour
            </button>
            <button
              type="submit"
              className="btn btn-primary"
              disabled={loading || !formData.newPassword || !formData.confirmPassword}
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
