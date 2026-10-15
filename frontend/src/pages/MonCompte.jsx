import { useState, useEffect, useCallback } from 'react';
import { useNavigate } from 'react-router-dom';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import { authApi } from '../api/auth';
import { passwordApi } from '../api/password';
import { useAuth } from '../context/AuthContext';
import { validatePassword, getPasswordRequirements, getPasswordStrength } from '../utils/passwordValidator';
import Modal from '../components/common/Modal';
import {
  FiUser, FiMail, FiLock, FiEye, FiEyeOff, FiCheckCircle,
  FiRefreshCw, FiTrash2, FiShield,
} from 'react-icons/fi';
import '../css/components.css';
import '../css/layout.css';

const roleLabels = { admin: 'Administrateur', formateur: 'Formateur', etudiant: 'Étudiant' };
const homePath = { admin: '/admin/journal', formateur: '/formateur/scanner', etudiant: '/etudiant/bulletin' };

export const accountQueryKey = ['account'];

export function MonCompte() {
  const navigate = useNavigate();
  const { role, refreshUser } = useAuth();
  const queryClient = useQueryClient();

  const [open, setOpen] = useState(true);
  const [account, setAccount] = useState(null);
  const [email, setEmail] = useState('');
  const [currentPassword, setCurrentPassword] = useState('');
  const [code, setCode] = useState('');
  const [emailLoading, setEmailLoading] = useState(false);
  const [codeLoading, setCodeLoading] = useState(false);
  const [emailError, setEmailError] = useState('');
  const [emailSuccess, setEmailSuccess] = useState('');
  const [resendCooldown, setResendCooldown] = useState(0);
  const [username, setUsername] = useState('');
  const [usernameError, setUsernameError] = useState('');
  const [usernameSuccess, setUsernameSuccess] = useState('');
  const [usernameLoading, setUsernameLoading] = useState(false);
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
  });

  const handleClose = () => { setOpen(false); navigate(homePath[role] ?? '/login'); };
  const applyAccount = useCallback((d) => {
    setAccount(d);
    setEmail(d.contact_email ?? '');
    setUsername(d.username ?? '');
    queryClient.setQueryData(accountQueryKey, d);
  }, [queryClient]);

  useEffect(() => {
    if (accountQuery.data) applyAccount(accountQuery.data);
  }, [accountQuery.data, applyAccount]);

  useEffect(() => {
    if (accountQuery.isError) setEmailError('Impossible de charger votre compte.');
  }, [accountQuery.isError]);

  useEffect(() => {
    if (resendCooldown <= 0) return;
    const t = setTimeout(() => setResendCooldown(c => c - 1), 1000);
    return () => clearTimeout(t);
  }, [resendCooldown]);

  const canEditUsername = role === 'admin';
  const emailVerified = Boolean(account?.contact_email_verified_at);
  const requirements = getPasswordRequirements();
  const strength = getPasswordStrength(pwForm.newPassword);
  const loading = accountQuery.isLoading && !account;

  const handleSaveEmail = async (e) => {
    e.preventDefault(); setEmailError(''); setEmailSuccess('');
    if (!email.trim()) { setEmailError('Veuillez saisir une adresse email'); return; }
    setEmailLoading(true);
    try {
      const res = await authApi.updateEmail(email.trim(), currentPassword);
      applyAccount(res.data.data); await refreshUser(); setCurrentPassword(''); setEmailSuccess(res.data.message);
    } catch (err) {
      const data = err.response?.data;
      setEmailError(data?.errors?.email?.[0] || data?.message || "Erreur lors de l'enregistrement");
    } finally { setEmailLoading(false); }
  };

  const handleVerify = async (e) => {
    e.preventDefault(); setEmailError(''); setEmailSuccess('');
    if (!/^\d{6}$/.test(code.trim())) { setEmailError('Le code est composé de 6 chiffres'); return; }
    setCodeLoading(true);
    try {
      const res = await authApi.verifyEmail(code.trim());
      applyAccount(res.data.data); await refreshUser(); setCode(''); setEmailSuccess(res.data.message);
    } catch (err) { setEmailError(err.response?.data?.message || 'Code invalide ou expiré'); }
    finally { setCodeLoading(false); }
  };

  const handleResend = async () => {
    setEmailError(''); setEmailSuccess('');
    try { const res = await authApi.resendEmailVerification(); setEmailSuccess(res.data.message); setResendCooldown(60); }
    catch (err) { setEmailError(err.response?.data?.message || 'Impossible de renvoyer le code'); }
  };

  const handleRemoveEmail = async () => {
    setEmailError(''); setEmailSuccess('');
    if (!window.confirm('Supprimer votre adresse email ?')) return;
    setEmailLoading(true);
    try { const res = await authApi.removeEmail(); applyAccount(res.data.data); await refreshUser(); setCode(''); setEmailSuccess(res.data.message); }
    catch (err) { setEmailError(err.response?.data?.message || 'Erreur lors de la suppression'); }
    finally { setEmailLoading(false); }
  };

  const handleSaveUsername = async (e) => {
    e.preventDefault(); setUsernameError(''); setUsernameSuccess('');
    const v = username.trim().toLowerCase();
    if (!/^[a-z0-9]+(?:\.[a-z0-9]+)*$/.test(v)) { setUsernameError("Format invalide"); return; }
    setUsernameLoading(true);
    try { const res = await authApi.updateUsername(v); applyAccount(res.data.data); await refreshUser(); setUsernameSuccess(res.data.message); }
    catch (err) { const d=err.response?.data; setUsernameError(d?.errors?.username?.[0]||d?.message||'Erreur'); }
    finally { setUsernameLoading(false); }
  };

  const handleChangePassword = async (e) => {
    e.preventDefault(); setPwErrors([]); setPwError(''); setPwSuccess('');
    if (!pwForm.oldPassword) { setPwErrors(["L'ancien mot de passe est requis"]); return; }
    const val = validatePassword(pwForm.newPassword);
    if (!val.isValid) { setPwErrors(val.errors); return; }
    if (pwForm.newPassword !== pwForm.confirmPassword) { setPwErrors(['Les mots de passe ne correspondent pas']); return; }
    if (pwForm.oldPassword === pwForm.newPassword) { setPwErrors(['Le nouveau mot de passe doit être différent']); return; }
    setPwLoading(true);
    try { const res = await passwordApi.changePassword(pwForm.oldPassword,pwForm.newPassword,pwForm.confirmPassword); setPwSuccess(res.data.message||'Modifié'); setPwForm({oldPassword:'',newPassword:'',confirmPassword:''}); }
    catch (err) { const d=err.response?.data; if(d?.errors) setPwErrors(Object.values(d.errors).flat()); else setPwError(d?.message||'Erreur'); }
    finally { setPwLoading(false); }
  };

  if (loading) {
    return <Modal open onClose={handleClose} title="Mon compte"><div style={{ padding:24, textAlign:'center' }}>Chargement...</div></Modal>;
  }

  return (
    <Modal open={open} onClose={handleClose} title="Mon compte">
      <div style={{ display:'flex', flexDirection:'column', gap:16, maxHeight:'80vh', overflowY:'auto', padding:4 }}>
        <div className="card"><div className="card-body">
          <h3 style={{ margin:'0 0 12px', fontSize:14, fontWeight:600 }}>Identité</h3>
          <div className="form-group"><label className="form-label">Nom complet</label><input className="form-input" value={account?.name??''} disabled /></div>
          <div className="form-group" style={{ marginBottom:0 }}><label className="form-label">Rôle</label><input className="form-input" value={roleLabels[account?.role]??account?.role??''} disabled /></div>
        </div></div>

        <div className="card"><div className="card-body">
          <h3 style={{ margin:'0 0 6px', fontSize:14, fontWeight:600 }}>Identifiant de connexion</h3>
          <p style={{ margin:'0 0 12px', color:'var(--gray-500)', fontSize:12 }}>{canEditUsername ? "Identifiant modifiable (admin uniquement)." : "Identifiant généré automatiquement."}</p>
          {usernameError && <div className="alert alert-error" style={{ marginBottom:10 }}>{usernameError}</div>}
          {usernameSuccess && <div className="alert alert-success" style={{ marginBottom:10 }}><FiCheckCircle size={14}/> {usernameSuccess}</div>}
          <form onSubmit={handleSaveUsername}>
            <div className="form-group" style={{ marginBottom: canEditUsername?12:0 }}><label className="form-label">Identifiant</label>
              <div style={{ position:'relative' }}><FiUser size={16} style={{ position:'absolute',left:10,top:10,color:'var(--gray-400)' }}/><input className="form-input" style={{ paddingLeft:34 }} value={username} onChange={e=>setUsername(e.target.value.toLowerCase())} disabled={!canEditUsername||usernameLoading} spellCheck={false} /></div>
            </div>
            {canEditUsername && <button type="submit" className="btn btn-primary btn-sm" disabled={usernameLoading||!username.trim()}>{usernameLoading?'Enregistrement...':'Enregistrer'}</button>}
          </form>
        </div></div>

        <div className="card"><div className="card-body">
          <h3 style={{ margin:'0 0 6px', fontSize:14, fontWeight:600 }}>Adresse email de contact</h3>
          <p style={{ margin:'0 0 12px', color:'var(--gray-500)', fontSize:12 }}>Permet la récupération du mot de passe (avec code vérifié).</p>
          {emailError && <div className="alert alert-error" style={{ marginBottom:10 }}>{emailError}</div>}
          {emailSuccess && <div className="alert alert-success" style={{ marginBottom:10 }}><FiCheckCircle size={14}/> {emailSuccess}</div>}
          {account?.contact_email && <div style={{ display:'flex',alignItems:'center',justifyContent:'space-between',gap:8,padding:10,marginBottom:12,background:'var(--gray-50)',border:'1px solid var(--gray-200)',borderRadius:8 }}>
            <div style={{ display:'flex',alignItems:'center',gap:8 }}><FiMail size={14}/><span style={{ fontSize:13 }}>{account.contact_email}</span></div>
            <span style={{ fontSize:11,padding:'2px 8px',borderRadius:20,background:emailVerified?'var(--primary-faint)':'var(--gray-200)',color:emailVerified?'var(--primary)':'var(--gray-600)' }}>{emailVerified?'Vérifiée':'Non vérifiée'}</span>
          </div>}
          <form onSubmit={handleSaveEmail}>
            <div className="form-group"><label className="form-label">Email</label><input type="email" className="form-input" value={email} onChange={e=>setEmail(e.target.value)} placeholder="vous@exemple.com" /></div>
            {emailVerified && <div className="form-group"><label className="form-label">Mot de passe actuel (confirmation)</label><input type="password" className="form-input" value={currentPassword} onChange={e=>setCurrentPassword(e.target.value)} /></div>}
            <div style={{ display:'flex',gap:8,flexWrap:'wrap' }}><button type="submit" className="btn btn-primary btn-sm" disabled={emailLoading}>{emailLoading ? (account?.contact_email ? 'Mettre à jour' : 'Enregistrer et envoyer le code') : (account?.contact_email ? 'Mettre à jour' : 'Enregistrer et envoyer le code')}</button>{account?.contact_email && <button type="button" className="btn btn-ghost btn-sm" onClick={handleRemoveEmail} disabled={emailLoading}><FiTrash2 size={14}/> Supprimer</button>}</div>
          </form>
          {account?.contact_email && !emailVerified && <div style={{ marginTop:12,paddingTop:12,borderTop:'1px solid var(--gray-100)' }}>
            <label className="form-label">Code de vérification</label>
            <div style={{ display:'flex',gap:8,alignItems:'center',flexWrap:'wrap' }}><input className="form-input" style={{ maxWidth:120,letterSpacing:4 }} value={code} onChange={e=>setCode(e.target.value)} maxLength={6} inputMode="numeric" /><button type="button" className="btn btn-primary btn-sm" onClick={handleVerify} disabled={codeLoading||code.length!==6}>{codeLoading?'Vérif...':'Vérifier'}</button><button type="button" className="btn btn-ghost btn-sm" onClick={handleResend} disabled={resendCooldown>0}><FiRefreshCw size={14}/> {resendCooldown>0?`(${resendCooldown}s)`:''} Renvoyer</button></div>
          </div>}
        </div></div>

        <div className="card"><div className="card-body">
          <h3 style={{ margin:'0 0 6px', fontSize:14, fontWeight:600, display:'flex',alignItems:'center',gap:6 }}><FiShield size={14}/> Mot de passe</h3>
          <p style={{ margin:'0 0 12px', color:'var(--gray-500)', fontSize:12 }}>Modifiez votre mot de passe en toute sécurité.</p>
          {pwSuccess && <div className="alert alert-success" style={{ marginBottom:10 }}><FiCheckCircle size={14}/> {pwSuccess}</div>}
          {pwError && <div className="alert alert-error" style={{ marginBottom:10 }}>{pwError}</div>}
          {pwErrors.length>0 && <div className="alert alert-error" style={{ marginBottom:10 }}>{pwErrors.map((e,i)=><div key={i}>• {e}</div>)}</div>}
          <form onSubmit={handleChangePassword}>
            <div className="form-group"><label className="form-label">Mot de passe actuel</label>
              <div style={{ position:'relative' }}><input type={showPw.old?'text':'password'} className="form-input" style={{ paddingRight:36 }} value={pwForm.oldPassword} onChange={e=>setPwForm(p=>({...p,oldPassword:e.target.value}))} /><button type="button" style={{ position:'absolute',right:6,top:6,background:'none',border:'none',cursor:'pointer' }} onClick={()=>setShowPw(p=>({...p,old:!p.old}))}>{showPw.old?<FiEyeOff size={16}/>:<FiEye size={16}/>}</button></div>
            </div>
            <div className="form-group"><label className="form-label">Nouveau mot de passe</label>
              <div style={{ position:'relative' }}><input type={showPw.next?'text':'password'} className="form-input" style={{ paddingRight:36 }} value={pwForm.newPassword} onChange={e=>setPwForm(p=>({...p,newPassword:e.target.value}))} /><button type="button" style={{ position:'absolute',right:6,top:6,background:'none',border:'none',cursor:'pointer' }} onClick={()=>setShowPw(p=>({...p,next:!p.next}))}>{showPw.next?<FiEyeOff size={16}/>:<FiEye size={16}/>}</button></div>
              {pwForm.newPassword && strength.level>0 && <div style={{ marginTop:6,fontSize:11,color:strength.level>=3?'var(--success)':'var(--warning)' }}>{strength.label}</div>}
            </div>
            <div className="form-group"><label className="form-label">Confirmer le mot de passe</label><input type={showPw.next?'text':'password'} className="form-input" value={pwForm.confirmPassword} onChange={e=>setPwForm(p=>({...p,confirmPassword:e.target.value}))} /></div>
            <button type="submit" className="btn btn-primary btn-sm" disabled={pwLoading||!pwForm.oldPassword||!pwForm.newPassword||!pwForm.confirmPassword}>{pwLoading?'Traitement...':'Changer le mot de passe'}</button>
          </form>
        </div></div>
      </div>
    </Modal>
  );
}

export default MonCompte;
