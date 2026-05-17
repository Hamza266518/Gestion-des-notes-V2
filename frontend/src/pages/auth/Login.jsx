import { useState, useRef } from 'react';
import { useNavigate } from 'react-router-dom';
import { authApi } from '../../api/auth';
import { useAuth } from '../../context/AuthContext';
import { FiMail, FiLock, FiEye, FiEyeOff, FiLoader } from 'react-icons/fi';
import logoIFP from '../../image/logo IFP.jpeg';
import bgImage from '../../image/TIBYA.png';
import '../../css/login.css';

export default function Login() {
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [showPassword, setShowPassword] = useState(false);
  const [error, setError] = useState('');
  const [loading, setLoading] = useState(false);
  const [showHint, setShowHint] = useState(false);
  const { login } = useAuth();
  const navigate = useNavigate();
  const passwordRef = useRef(null);

  const validateEmail = (email) => {
    const re = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
    return re.test(email);
  };

  const handleSubmit = async (e) => {
    e.preventDefault();
    setError('');
    
    if (!email.trim()) {
      setError('Veuillez entrer votre adresse email');
      return;
    }
    
    if (!validateEmail(email)) {
      setError('Veuillez entrer une adresse email valide');
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
      const res = await authApi.login(email, password);
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
          setError('Email ou mot de passe incorrect');
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
                <label htmlFor="login-email" className="login-field-label">
                  Adresse email
                </label>
                <div className="login-input-wrap">
                  <FiMail className="login-input-icon" />
                  <input
                    id="login-email"
                    type="email"
                    className="login-input"
                    placeholder="CIN@ifp.ma"
                    value={email}
                    onChange={e => setEmail(e.target.value)}
                    autoComplete="email"
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
                  onClick={() => setShowHint(!showHint)}
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

            {showHint && (
              <div className="login-hint">
                <p><strong>Identifiant :</strong> votre CIN suivi de @ifp.ma</p>
                <p><strong>Mot de passe :</strong> n° inscription + 2 premiers caractères CIN</p>
                <p className="login-hint-example">Exemple : 01AS27AB</p>
              </div>
            )}
          </div>
        </div>
      </div>
    </div>
  );
}