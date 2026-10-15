import { useState, useRef } from 'react';
import { useNavigate } from 'react-router-dom';
import { authApi } from '../../api/auth';
import { useAuth } from '../../context/AuthContext';
import { FiUser, FiLock, FiEye, FiEyeOff, FiLoader } from 'react-icons/fi';
import { ForgotPasswordModal } from '../../components/etudiant/ForgotPasswordModal';
import logoIFP from '../../image/logo IFP.jpeg';
import bgImage from '../../image/TIBYA.png';
import '../../css/login.css';

export default function Login() {
  const [username, setUsername] = useState('');
  const [password, setPassword] = useState('');
  const [showPassword, setShowPassword] = useState(false);
  const [error, setError] = useState('');
  const [loading, setLoading] = useState(false);
  const [showForgotPassword, setShowForgotPassword] = useState(false);
  const { login } = useAuth();
  const navigate = useNavigate();
  const passwordRef = useRef(null);

  const validateUsername = (value) => /^[a-z0-9]+(?:\.[a-z0-9]+)*$/.test(value);

  const handleSubmit = async (e) => {
    e.preventDefault();
    setError('');

    const identifier = username.trim().toLowerCase();

    if (!identifier) {
      setError('Veuillez entrer votre identifiant');
      return;
    }

    if (!validateUsername(identifier)) {
      setError('L\'identifiant ne peut contenir que des lettres, chiffres et points');
      return;
    }

    if (!password) {
      setError('Veuillez entrer votre mot de passe');
      return;
    }

    if (password.length < 6) {
      setError('Le mot de passe doit contenir au moins 6 caractères');
      return;
    }

    setLoading(true);
    try {
      const res = await authApi.login(identifier, password);
      login(res.data.data);
      const r = res.data.data.role;
      setTimeout(() => {
        if (r === 'admin') navigate('/admin/dashboard');
        else if (r === 'formateur') navigate('/formateur/scanner');
        else if (r === 'etudiant') navigate('/etudiant/bulletin');
        else navigate('/login');
      }, 100);
    } catch (err) {
      if (err.response) {
        if (err.response.status === 401) {
          setError('Identifiant ou mot de passe incorrect');
        } else if (err.response.status === 429) {
          setError('Trop de tentatives. Veuillez réessayer plus tard.');
        } else if (err.response.status >= 500) {
          setError('Erreur serveur. Veuillez réessayer plus tard.');
        } else {
          setError('Erreur de connexion. Vérifiez vos identifiants.');
        }
      } else if (err.request) {
        setError('Impossible de se connecter au serveur. Vérifiez votre connexion internet.');
      } else {
        setError('Une erreur inattendue est survenue.');
      }
    } finally {
      setLoading(false);
    }
  };

  return (
    <div className="login-page">
      <div className="login-bg">
        <img src={bgImage} alt="" className="login-bg-image" />
        <div className="login-bg-overlay" />
      </div>

      <div className="login-left">
          <div className="login-left-content">
          <div className="login-brand-block">
            <img src={logoIFP} alt="IFP Logo" className="login-brand-logo" />
            <div className="login-brand-text">
              <span className="login-brand-name">Institut des Formations Paramédicales</span>
              <span className="login-brand-subtitle">Plateforme académique</span>
            </div>
          </div>
          <div className="login-left-footer">
            © 2026 IFP — Tous droits réservés
          </div>
        </div>
      </div>

      <div className="login-right">
        <div className="login-card-wrapper">
          <img src={logoIFP} alt="IFP Logo" className="login-brand-logo-mobile" />
          <div className="login-card">
            <div className="login-card-header">
              <h2>Accédez à votre espace</h2>
              <div className="login-card-accent" />
            </div>

            {error && (
              <div className="login-error">
                <span>{error}</span>
                <button type="button" onClick={() => setError('')} aria-label="Fermer">×</button>
              </div>
            )}

            <form onSubmit={handleSubmit} noValidate>
              <div className="login-field">
                <label htmlFor="login-username" className="login-field-label">
                  Identifiant
                </label>
                <div className="login-input-wrap">
                  <FiUser className="login-input-icon" />
                  <input
                    id="login-username"
                    type="text"
                    className="login-input"
                    placeholder="prenom.nom"
                    value={username}
                    onChange={e => setUsername(e.target.value)}
                    autoComplete="username"
                    autoCapitalize="none"
                    spellCheck="false"
                    required
                    aria-required="true"
                  />
                </div>
              </div>

              <div className="login-field">
                <label htmlFor="login-password" className="login-field-label">
                  Mot de passe
                </label>
                <div className="login-input-wrap">
                  <FiLock className="login-input-icon" />
                  <input
                    id="login-password"
                    ref={passwordRef}
                    type={showPassword ? 'text' : 'password'}
                    className="login-input"
                    placeholder="••••••••"
                    value={password}
                    onChange={e => setPassword(e.target.value)}
                    autoComplete="current-password"
                    required
                    aria-required="true"
                  />
                  <button
                    type="button"
                    className="login-eye-toggle"
                    onClick={() => setShowPassword(!showPassword)}
                    aria-label={showPassword ? 'Masquer' : 'Afficher'}
                  >
                    {showPassword ? <FiEyeOff size={18} /> : <FiEye size={18} />}
                  </button>
                </div>
              </div>

              <div className="login-options">
                <button
                  type="button"
                  className="login-forgot-link"
                  onClick={() => setShowForgotPassword(true)}
                >
                  Mot de passe oublié ?
                </button>
              </div>

              <button type="submit" className="login-btn-primary" disabled={loading}>
                {loading ? (
                  <>
                    <FiLoader className="login-btn-spinner" /> Connexion en cours...
                  </>
                ) : (
                  <span>Se connecter</span>
                )}
              </button>
            </form>
          </div>
        </div>
      </div>

      {showForgotPassword && (
        <ForgotPasswordModal
          onClose={() => setShowForgotPassword(false)}
          onSuccess={() => {
            setShowForgotPassword(false);
            setError('');
            setUsername('');
            setPassword('');
          }}
        />
      )}
    </div>
  );
}