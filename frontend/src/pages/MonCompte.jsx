import { useState, useEffect, useCallback } from 'react';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import { authApi } from '../api/auth';
import { passwordApi } from '../api/password';
import { useAuth } from '../context/AuthContext';
import { useAccountModal } from '../context/AccountModalContext';
import { validatePassword, getPasswordRequirements, getPasswordStrength } from '../utils/passwordValidator';
import Modal from '../components/common/Modal';
import {
  FiUser, FiMail, FiLock, FiEye, FiEyeOff, FiCheckCircle, FiTrash2,
} from 'react-icons/fi';
import '../css/components.css';
import '../css/layout.css';
import '../css/account.css';

const roleLabels = { admin: 'Administrateur', formateur: 'Formateur', etudiant: 'Étudiant' };

const SECTIONS = [
  { id: 'profil', label: 'Profil', icon: FiUser },
  { id: 'identifiant', label: 'Identifiant', icon: FiUser },
  { id: 'email', label: 'Adresse email', icon: FiMail },
  { id: 'motdepasse', label: 'Mot de passe', icon: FiLock },
];

export const accountQueryKey = ['account'];

export function MonCompte() {
  const { refreshUser } = useAuth();
  const { open, closeAccount } = useAccountModal();
  const queryClient = useQueryClient();

  const [section, setSection] = useState('profil');
  const [account, setAccount] = useState(null);
  const [email, setEmail] = useState('');
  const [currentPassword, setCurrentPassword] = useState('');
  const [code, setCode] = useState('');
  const [emailLoading, setEmailLoading] = useState(false);
  const [codeLoading, setCodeLoading] = useState(false);
  const [emailError, setEmailError] = useState('');
  const [emailSuccess, setEmailSuccess] = useState('');
  const [resendCooldown, setResendCooldown] = useState(0);
  const [confirmRemoveEmail, setConfirmRemoveEmail] = useState(false);
  const [pwForm, setPwForm] = useState({ oldPassword: '', newPassword: '', confirmPassword: '' });
  const [showPw, setShowPw] = useState({ old: false, next: false });
  const [pwErrors, setPwErrors] = useState([]);
  const [pwError, setPwError] = useState('');
  const [pwSuccess, setPwSuccess] = useState('');
  const [pwLoading, setPwLoading] = useState(false);

  const accountQuery = useQuery({
    queryKey: accountQueryKey,
    queryFn: async () => (await authApi.account()).data.data,
    staleTime: 5 * 60 * 1000,
    gcTime: 15 * 60 * 1000,
    // The overlay is always mounted; only fetch the account once it is opened.
    enabled: open,
  });

  const handleClose = () => closeAccount();
  const applyAccount = useCallback((data) => {
    setAccount(data);
    setEmail(data.contact_email || '');
    queryClient.setQueryData(accountQueryKey, data);
  }, [queryClient]);

  useEffect(() => { if (accountQuery.data) applyAccount(accountQuery.data); }, [accountQuery.data, applyAccount]);

  useEffect(() => {
    if (!open) {
      setSection('profil');
      setConfirmRemoveEmail(false);
    }
  }, [open]);

  // Keep the resend button disabled while the cooldown counts down.
  useEffect(() => {
    if (resendCooldown <= 0) return undefined;
    const timer = setTimeout(() => setResendCooldown((value) => value - 1), 1000);
    return () => clearTimeout(timer);
  }, [resendCooldown]);

  const emailVerified = Boolean(account?.contact_email_verified_at);
  const requirements = getPasswordRequirements();
  const strength = getPasswordStrength(pwForm.newPassword);
  const loading = accountQuery.isLoading && !account;
  const initials = (account?.name || '?')
    .split(/\s+/)
    .filter(Boolean)
    .slice(0, 2)
    .map((word) => word[0])
    .join('')
    .toUpperCase();

  const handleSaveEmail = async (e) => {
    e.preventDefault(); setEmailError(''); setEmailSuccess('');
    if (!email.trim()) { setEmailError('Veuillez saisir une adresse email'); return; }
    setEmailLoading(true);
    try {
      const res = await authApi.updateEmail(email.trim(), currentPassword);
      applyAccount(res.data.data); await refreshUser(); setCurrentPassword('');
      if (res.data.code_sent === false) setEmailError(res.data.message); else setEmailSuccess(res.data.message);
    } catch (err) {
      const data = err.response?.data;
      setEmailError(data?.errors?.email?.[0] || data?.message || 'Erreur lors de la mise à jour');
    } finally {
      setEmailLoading(false);
    }
  };

  const handleVerify = async (e) => {
    e.preventDefault(); setEmailError(''); setEmailSuccess('');
    setCodeLoading(true);
    try {
      const res = await authApi.verifyEmail(code);
      applyAccount(res.data.data);
      await refreshUser();
      setCode('');
      setEmailSuccess(res.data.message);
    } catch (err) {
      const data = err.response?.data;
      setEmailError(data?.errors?.code?.[0] || data?.message || 'Code invalide ou expiré');
    } finally {
      setCodeLoading(false);
    }
  };

  const handleResend = async () => {
    setEmailError('');
    setEmailSuccess('');
    setCodeLoading(true);
    try {
      const res = await authApi.resendEmailVerification();
      if (res.data.code_sent === false) setEmailError(res.data.message);
      else { setEmailSuccess(res.data.message); setResendCooldown(60); }
    } catch (err) {
      const data = err.response?.data;
      setEmailError(data?.message || "Impossible d'envoyer le code");
    } finally {
      setCodeLoading(false);
    }
  };

  const handleRemoveEmail = async () => {
    setConfirmRemoveEmail(false);
    setEmailError('');
    setEmailSuccess('');
    setEmailLoading(true);
    try {
      const res = await authApi.removeEmail();
      applyAccount(res.data.data);
      setCode('');
      setEmailSuccess(res.data.message);
    } catch (err) {
      const data = err.response?.data;
      setEmailError(data?.message || "Impossible de supprimer l'adresse email");
    } finally {
      setEmailLoading(false);
    }
  };

  const handleChangePassword = async (e) => {
    e.preventDefault();
    setPwErrors([]);
    setPwError('');
    setPwSuccess('');
    if (!pwForm.oldPassword) { setPwErrors(['Le mot de passe actuel est requis']); return; }
    const validation = validatePassword(pwForm.newPassword);
    if (!validation.isValid) { setPwErrors(validation.errors); return; }
    if (pwForm.newPassword !== pwForm.confirmPassword) { setPwErrors(['Les mots de passe ne correspondent pas']); return; }
    if (pwForm.oldPassword === pwForm.newPassword) { setPwErrors(['Le nouveau mot de passe doit être différent']); return; }
    setPwLoading(true);
    try {
      const res = await passwordApi.changePassword(pwForm.oldPassword, pwForm.newPassword, pwForm.confirmPassword);
      setPwSuccess(res.data.message || 'Mot de passe modifié.');
      setPwForm({ oldPassword: '', newPassword: '', confirmPassword: '' });
      setShowPw({ old: false, next: false });
    } catch (err) {
      const data = err.response?.data;
      if (data?.errors) setPwErrors(Object.values(data.errors).flat());
      else setPwError(data?.message || 'Impossible de modifier le mot de passe');
    } finally {
      setPwLoading(false);
    }
  };

  return (
    <Modal
      open={open}
      onClose={handleClose}
      title="Mon compte"
      style={{ maxWidth: 780 }}
      bodyClassName="custom-modal-body--flush"
    >
      <div className="acct">
        <nav className="acct-nav" aria-label="Sections du compte">
          {SECTIONS.map(({ id, label, icon: Icon }) => (
            <button
              key={id}
              type="button"
              className={`acct-nav-item${section === id ? ' active' : ''}`}
              onClick={() => setSection(id)}
            >
              <Icon size={16} />
              <span>{label}</span>
            </button>
          ))}
        </nav>

        <div className="acct-panel">
          {loading ? (
            <div className="acct-empty">Chargement…</div>
          ) : (
            <>
              {section === 'profil' && (
                <>
                  <h3 className="acct-title">Profil</h3>
                  <p className="acct-sub">Vos informations personnelles, gérées par l'administration.</p>
                  <div className="acct-identity">
                    <div className="acct-avatar">{initials}</div>
                    <div>
                      <div className="acct-identity-name">{account?.name || '—'}</div>
                      <div className="acct-identity-role">{roleLabels[account?.role] || account?.role || '—'}</div>
                    </div>
                  </div>
                  <div className="form-group">
                    <label className="form-label">Nom complet</label>
                    <input className="form-input" value={account?.name || ''} disabled />
                  </div>
                  <div className="form-group" style={{ marginBottom: 0 }}>
                    <label className="form-label">Rôle</label>
                    <input className="form-input" value={roleLabels[account?.role] || account?.role || ''} disabled />
                  </div>
                </>
              )}

              {section === 'identifiant' && (
                <>
                  <h3 className="acct-title">Identifiant de connexion</h3>
                  <p className="acct-sub">
                    Votre identifiant est généré une seule fois et ne peut pas être modifié.
                    Contactez l'administration si vous en avez besoin.
                  </p>
                  <div className="form-group">
                    <label className="form-label">Identifiant</label>
                    <input className="form-input" value={account?.username || ''} disabled readOnly />
                  </div>
                </>
              )}

              {section === 'email' && (
                <>
                  <h3 className="acct-title">Adresse email</h3>
                  <p className="acct-sub">
                    Elle sert à la récupération du mot de passe. Un code de vérification y est envoyé.
                  </p>
                  {emailError && <div className="acct-msg acct-msg--err">{emailError}</div>}
                  {emailSuccess && (
                    <div className="acct-msg acct-msg--ok"><FiCheckCircle size={15} /><span>{emailSuccess}</span></div>
                  )}
                  <form onSubmit={handleSaveEmail}>
                    <div className="form-group">
                      <label className="form-label">Email</label>
                      <input
                        type="email"
                        className="form-input"
                        value={email}
                        onChange={(e) => setEmail(e.target.value)}
                        placeholder="vous@exemple.com"
                        autoComplete="email"
                      />
                      {account?.contact_email && (
                        <div className="acct-status">
                          <span className={emailVerified ? 'acct-badge acct-badge--ok' : 'acct-badge'}>
                            {emailVerified ? 'Vérifiée' : 'Non vérifiée'}
                          </span>
                          <span className="acct-status-value">{account.contact_email}</span>
                        </div>
                      )}
                    </div>
                    {emailVerified && (
                      <div className="form-group">
                        <label className="form-label">Mot de passe actuel (confirmation)</label>
                        <input
                          type="password"
                          className="form-input"
                          value={currentPassword}
                          onChange={(e) => setCurrentPassword(e.target.value)}
                          autoComplete="current-password"
                        />
                      </div>
                    )}
                    <div className="acct-actions">
                      <button type="submit" className="btn btn-primary" disabled={emailLoading}>
                        {account?.contact_email ? 'Mettre à jour' : 'Enregistrer et envoyer le code'}
                      </button>
                      {account?.contact_email && !confirmRemoveEmail && (
                        <button
                          type="button"
                          className="btn btn-ghost"
                          onClick={() => setConfirmRemoveEmail(true)}
                          disabled={emailLoading}
                        >
                          <FiTrash2 size={14} /> Supprimer
                        </button>
                      )}
                    </div>
                    {confirmRemoveEmail && (
                      <div className="acct-confirm">
                        <span>Supprimer cette adresse email ?</span>
                        <button type="button" className="btn btn-danger btn-sm" onClick={handleRemoveEmail} disabled={emailLoading}>
                          Supprimer
                        </button>
                        <button type="button" className="btn btn-ghost btn-sm" onClick={() => setConfirmRemoveEmail(false)} disabled={emailLoading}>
                          Annuler
                        </button>
                      </div>
                    )}
                  </form>

                  {account?.contact_email && !emailVerified && (
                    <div className="acct-block">
                      <label className="form-label">Code de vérification</label>
                      <div className="acct-code">
                        <input
                          className="form-input"
                          value={code}
                          onChange={(e) => setCode(e.target.value.replace(/\D/g, ''))}
                          maxLength={6}
                          inputMode="numeric"
                          placeholder="000000"
                        />
                        <button
                          type="button"
                          className="btn btn-primary"
                          onClick={handleVerify}
                          disabled={codeLoading || code.length !== 6}
                        >
                          {codeLoading ? 'Vérification…' : 'Vérifier'}
                        </button>
                      </div>
                      <button
                        type="button"
                        className="acct-link"
                        onClick={handleResend}
                        disabled={codeLoading || resendCooldown > 0}
                      >
                        {resendCooldown > 0 ? `Renvoyer le code (${resendCooldown}s)` : 'Renvoyer le code'}
                      </button>
                    </div>
                  )}
                </>
              )}

              {section === 'motdepasse' && (
                <>
                  <h3 className="acct-title">Mot de passe</h3>
                  <p className="acct-sub">Choisissez un mot de passe long et unique.</p>
                  {pwSuccess && (
                    <div className="acct-msg acct-msg--ok"><FiCheckCircle size={15} /><span>{pwSuccess}</span></div>
                  )}
                  {pwError && <div className="acct-msg acct-msg--err">{pwError}</div>}
                  {pwErrors.length > 0 && (
                    <div className="acct-msg acct-msg--err">
                      <span>{pwErrors.map((error) => <span key={error} className="acct-msg-line">{error}</span>)}</span>
                    </div>
                  )}
                  <form onSubmit={handleChangePassword}>
                    <div className="form-group">
                      <label className="form-label">Mot de passe actuel</label>
                      <div className="acct-field">
                        <input
                          type={showPw.old ? 'text' : 'password'}
                          className="form-input"
                          value={pwForm.oldPassword}
                          onChange={(e) => setPwForm((p) => ({ ...p, oldPassword: e.target.value }))}
                          autoComplete="current-password"
                        />
                        <button type="button" className="acct-eye" onClick={() => setShowPw((p) => ({ ...p, old: !p.old }))} aria-label="Afficher ou masquer">
                          {showPw.old ? <FiEyeOff size={16} /> : <FiEye size={16} />}
                        </button>
                      </div>
                    </div>
                    <div className="form-group">
                      <label className="form-label">Nouveau mot de passe</label>
                      <div className="acct-field">
                        <input
                          type={showPw.next ? 'text' : 'password'}
                          className="form-input"
                          value={pwForm.newPassword}
                          onChange={(e) => setPwForm((p) => ({ ...p, newPassword: e.target.value }))}
                          autoComplete="new-password"
                        />
                        <button type="button" className="acct-eye" onClick={() => setShowPw((p) => ({ ...p, next: !p.next }))} aria-label="Afficher ou masquer">
                          {showPw.next ? <FiEyeOff size={16} /> : <FiEye size={16} />}
                        </button>
                      </div>
                      {pwForm.newPassword && strength.level > 0 && (
                        <div className="acct-strength" data-level={strength.level}>{strength.label}</div>
                      )}
                    </div>
                    <div className="form-group">
                      <label className="form-label">Confirmer le nouveau mot de passe</label>
                      <input
                        type={showPw.next ? 'text' : 'password'}
                        className="form-input"
                        value={pwForm.confirmPassword}
                        onChange={(e) => setPwForm((p) => ({ ...p, confirmPassword: e.target.value }))}
                        autoComplete="new-password"
                      />
                    </div>
                    <ul className="acct-reqs">
                      {requirements.map(({ text, check }) => (
                        <li key={text} className={check(pwForm.newPassword) ? 'ok' : ''}>
                          {text}
                        </li>
                      ))}
                    </ul>
                    <div className="acct-actions">
                      <button
                        type="submit"
                        className="btn btn-primary"
                        disabled={pwLoading || !pwForm.oldPassword || !pwForm.newPassword || !pwForm.confirmPassword}
                      >
                        {pwLoading ? 'Enregistrement…' : 'Changer le mot de passe'}
                      </button>
                    </div>
                  </form>
                </>
              )}
            </>
          )}
        </div>
      </div>
    </Modal>
  );
}

export default MonCompte;