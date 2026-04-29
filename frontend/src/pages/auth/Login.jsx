import { useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { authApi } from '../../api/auth';
import { useAuth } from '../../context/AuthContext';
import '../../css/login.css';
import '../../css/components.css';
import '../../css/variables.css';

export default function Login() {
  const [email, setEmail]       = useState('');
  const [password, setPassword] = useState('');
  const [error, setError]       = useState('');
  const [loading, setLoading]   = useState(false);
  const [showHint, setShowHint] = useState(false);
  const { login }               = useAuth();
  const navigate                = useNavigate();

  const handleSubmit = async (e) => {
    e.preventDefault();
    setError('');
    setLoading(true);
    try {
      const res = await authApi.login(email, password);
      login(res.data.data);
      const r = res.data.data.role;
      if (r === 'admin')     navigate('/admin/dashboard');
      if (r === 'formateur') navigate('/formateur/scanner');
      if (r === 'etudiant')  navigate('/etudiant/bulletin');
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
      <div className="login-left">
        <div className="login-logo">IFP</div>
        <div className="login-tagline">
          Institut des Formations<br />Paramédicales Privé
        </div>
        <div className="login-desc">
          Système de gestion des notes et bulletins des étudiants paramédicaux
        </div>
      </div>

      <div className="login-right">
        <div className="login-card">
          <h2>Connexion</h2>
          <p>Accédez à votre espace IFP</p>

          {error && (
            <div className="alert alert-error" style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
              <span>{error}</span>
              <button
                style={{ background: 'none', border: 'none', color: '#dc2626', cursor: 'pointer', fontSize: 18, padding: '0 4px' }}
                onClick={() => setError('')}
              >
                ×
              </button>
            </div>
          )}

          <form onSubmit={handleSubmit}>
            <div className="form-group">
              <label className="form-label">Identifiant</label>
              <input
                type="email"
                className="form-input"
                placeholder="CIN@ifp.ma"
                value={email}
                onChange={e => setEmail(e.target.value)}
                required
              />
            </div>

            <div className="form-group">
              <label className="form-label">Mot de passe</label>
              <input
                type="password"
                className="form-input"
                placeholder="••••••••"
                value={password}
                onChange={e => setPassword(e.target.value)}
                required
              />
            </div>

            <button
              type="submit"
              className="btn btn-primary"
              style={{ width: '100%', justifyContent: 'center' }}
              disabled={loading}
            >
              {loading ? 'Connexion en cours...' : 'Se connecter'}
            </button>
          </form>

          <button
            className="login-forgot"
            onClick={() => setShowHint(!showHint)}
          >
            Mot de passe oublié ?
          </button>

          {showHint && (
            <div className="login-hint">
              <strong>Identifiant:</strong> votre CIN suivi de @ifp.ma<br />
              <strong>Mot de passe:</strong> numéro d'inscription + 2 premiers caractères CIN<br />
              <span style={{ fontSize: '12px', opacity: 0.8 }}>Exemple: 01AS27AB</span>
            </div>
          )}
        </div>
      </div>
    </div>
  );
}
