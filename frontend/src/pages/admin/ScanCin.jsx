import { useEffect, useState } from 'react';
import { adminApi } from '../../api/admin';
import { scanApi } from '../../api/scan';
import { useToast } from '../../context/ToastContext';
import { useAnneeAcademique } from '../../context/AnneeAcademiqueContext';
import Spinner from '../../components/common/Spinner';
import Badge from '../../components/common/Badge';
import { formatNiveau, toWesternDigits, isoToArabicDate } from '../../utils/helpers';
import '../../css/components.css';
import '../../css/layout.css';

const DEFAULT_NATIONALITE = 'MAROCAINE';
const DEFAULT_NATIONALITE_AR = 'مغربية';

export default function ScanCin() {
  const [step, setStep]         = useState(1);
  const [filieres, setFilieres] = useState([]);
  const [niveaux, setNiveaux]   = useState([]);
  const [groupes, setGroupes]   = useState([]);
  const [selFiliere, setSelFiliere] = useState('');
  const [selNiveau, setSelNiveau]   = useState('');
  const [selGroupe, setSelGroupe]   = useState('');
  const [filiereCode, setFiliereCode] = useState('');
  const [images, setImages]     = useState([]);
  const [results, setResults]   = useState([]);
  const [scanning, setScanning] = useState(false);
  const [saving, setSaving]     = useState(false);
  const toast = useToast();
  const { currentAnnee } = useAnneeAcademique();

  useEffect(() => {
    adminApi.getFilieres()
      .then(res => setFilieres(res.data.data))
      .catch(() => setFilieres([]));
  }, []);

  useEffect(() => {
    if (selFiliere) {
      adminApi.getNiveaux(selFiliere)
        .then(res => setNiveaux(res.data.data))
        .catch(() => setNiveaux([]));
    } else {
      setNiveaux([]);
    }
    setSelNiveau('');
    setSelGroupe('');
  }, [selFiliere]);

  useEffect(() => {
    if (selFiliere) {
      const f = filieres.find(f => f.id == selFiliere);
      setFiliereCode(f?.code ?? '');
    }
  }, [selFiliere, filieres]);

  useEffect(() => {
    if (selNiveau) {
      const params = { niveau_id: selNiveau };
      if (currentAnnee?.id) {
        params.annee_academique_id = currentAnnee.id;
      }
      adminApi.getGroupes(params)
        .then(res => setGroupes(res.data.data))
        .catch(() => setGroupes([]));
    } else {
      setGroupes([]);
    }
  }, [selNiveau, currentAnnee]);

  const handleFiles = (e) => {
    setImages(Array.from(e.target.files));
  };

  const handleScan = async () => {
    if (images.length === 0 || !currentAnnee?.id || !selGroupe) return;
    setScanning(true);
    try {
      const fd = new FormData();
      images.forEach(img => fd.append('fichiers[]', img));
      fd.append('groupe_id', selGroupe);
      fd.append('annee_academique_id', currentAnnee.id);
      fd.append('filiere_code', filiereCode);

      const res = await scanApi.scanCin(fd);
      const data = res.data.data || {};
      const scanned = data.resultats || [];

      if (scanned.length === 0) {
        toast.error('Aucun étudiant trouvé. Vérifiez que les fichiers sont lisibles.');
      } else {
        setResults(scanned.map(withDefaults));
        setStep(3);
      }
    } catch (error) {
      toast.error(`Erreur de lecture: ${error.message || 'Erreur inconnue'}`);
      setResults([]);
    } finally {
      setScanning(false);
    }
  };

  const withDefaults = r => ({
    ...r,
    nationalite: r.nationalite || DEFAULT_NATIONALITE,
    nationalite_ar: r.nationalite_ar || DEFAULT_NATIONALITE_AR,
    nom_ar: r.nom_ar || r.nom_prenom || '',
    cin_ar: r.cin_ar || r.cin || '',
  });

  const handleConfirm = async () => {
    setSaving(true);
    try {
      const res = await adminApi.confirmScanCin({
        stagiaires: results.map(r => ({
          ...r,
          date_naissance: r.date_naissance || null,
          date_naissance_ar: r.date_naissance_ar || null,
          lieu_naissance: r.lieu_naissance || null,
          lieu_naissance_ar: r.lieu_naissance_ar || null,
          nationalite: r.nationalite || null,
          nationalite_ar: r.nationalite_ar || null,
          date_inscription: r.date_inscription || null,
          date_inscription_ar: r.date_inscription_ar || null,
          cin_ar: r.cin_ar || null,
          numero_inscription_ar: r.numero_inscription_ar || null,
        })),
        filiere_code: filiereCode,
      });
      toast.success(`${res.data.data.crees} étudiants enregistrés`);
      setResults([]);
      setImages([]);
      setStep(2);
    } catch {
      toast.error('Erreur de confirmation');
    } finally {
      setSaving(false);
    }
  };

  const updateResult = (i, key, val) => {
    setResults(prev => prev.map((r, idx) => {
      if (idx !== i) return r;
      const next = { ...r, [key]: val };
      if (key === 'cin') next.cin_ar = toWesternDigits(val).toUpperCase();
      if (key === 'date_naissance') next.date_naissance_ar = isoToArabicDate(val) || '';
      return next;
    }));
  };

  const removeResult = (i) => {
    setResults(prev => prev.filter((_, idx) => idx !== i));
  };

  return (
    <div className="page">
      <div className="page-header">
        <h2 className="page-title">Scan CIN — Ajout Stagiaires</h2>
      </div>

      <div className="steps" style={{ marginBottom: 28 }}>
        {['Sélection', 'Photos CIN', 'Vérification'].map((label, i) => (
          <div key={i} style={{ display: 'flex', alignItems: 'center', flex: 1 }}>
            <div style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
              <div className={`step-num ${step > i + 1 ? 'done' : step === i + 1 ? 'active' : ''}`}
                style={{
                  width: 28, height: 28, borderRadius: '50%',
                  background: step > i + 1 ? 'var(--success)' : step === i + 1 ? 'var(--primary)' : 'var(--gray-200)',
                  color: step >= i + 1 ? 'white' : 'var(--gray-500)',
                  display: 'flex', alignItems: 'center', justifyContent: 'center',
                  fontSize: 12, fontWeight: 600
                }}
              >
                {step > i + 1 ? '✓' : i + 1}
              </div>
              <span style={{ fontSize: 13, color: step === i + 1 ? 'var(--primary)' : 'var(--gray-400)', fontWeight: step === i + 1 ? 500 : 400 }}>
                {label}
              </span>
            </div>
            {i < 2 && <div style={{ flex: 1, height: 2, background: 'var(--gray-200)', margin: '0 12px' }} />}
          </div>
        ))}
      </div>

      {step === 1 && (
        <div className="card card-body" style={{ maxWidth: 500 }}>
          <div className="form-group">
            <label className="form-label">Filière</label>
            <select className="form-select" value={selFiliere} onChange={e => { setSelFiliere(e.target.value); setSelNiveau(''); setSelGroupe(''); }}>
              <option value="">Sélectionner</option>
              {filieres.map(f => <option key={f.id} value={f.id}>{f.nom}</option>)}
            </select>
          </div>
          <div className="form-group">
            <label className="form-label">Niveau</label>
            <select className="form-select" value={selNiveau} onChange={e => { setSelNiveau(e.target.value); setSelGroupe(''); }} disabled={!selFiliere}>
              <option value="">Sélectionner</option>
              {niveaux.map(n => <option key={n.id} value={n.id}>{formatNiveau(n.numero)}</option>)}
            </select>
          </div>
          <div className="form-group">
            <label className="form-label">Année académique</label>
            <select className="form-select" value={currentAnnee?.id || ''} disabled>
              <option value="">{currentAnnee?.label || 'Chargement...'}</option>
            </select>
          </div>
          <div className="form-group">
            <label className="form-label">Groupe</label>
            <select className="form-select" value={selGroupe} onChange={e => setSelGroupe(e.target.value)} disabled={!selNiveau || !currentAnnee}>
              <option value="">Sélectionner</option>
              {groupes.map(g => <option key={g.id} value={g.id}>{g.nom}</option>)}
            </select>
          </div>
          <button className="btn btn-primary" disabled={!selGroupe} onClick={() => setStep(2)}>
            Suivant →
          </button>
        </div>
      )}

      {step === 2 && (
        <div className="card card-body" style={{ maxWidth: 620 }}>
          <div className="upload-zone">
            <input
              type="file"
              accept="image/jpeg,image/png,image/webp,application/pdf"
              multiple
              onChange={handleFiles}
            />
            <div className="upload-zone-icon">🪪</div>
            <div className="upload-zone-text">
              <strong>Cliquer ou glisser</strong> les photos de la CIN ici
            </div>
            <div className="upload-zone-hint">
              (recto et verso de la même carte, ou un PDF). Une carte = un étudiant.
            </div>
          </div>

          {images.length > 0 && (
            <div style={{ marginTop: 12, fontSize: 13, color: 'var(--gray-600)' }}>
              {images.length} fichier(s) sélectionné(s)
            </div>
          )}

          <div style={{ marginTop: 10, fontSize: 12.5, color: 'var(--gray-500)' }}>
            Groupe {groupes.find(g => String(g.id) === String(selGroupe))?.nom || ''} conservé — vous pouvez enchaîner les CIN suivantes.
          </div>

          <div style={{ display: 'flex', gap: 8, marginTop: 16 }}>
            <button className="btn btn-outline" onClick={() => setStep(1)}>← Retour</button>
            <button
              className="btn btn-primary"
              disabled={images.length === 0 || scanning}
              onClick={handleScan}
            >
              {scanning ? 'Lecture en cours...' : 'Scanner les CIN'}
            </button>
          </div>

          {scanning && <Spinner />}
        </div>
      )}

      {step === 3 && (
        <div>
          <div className="alert alert-info" style={{ marginBottom: 16 }}>
            Corrigez si nécessaire, puis confirmez. La nationalité est réglée sur Marocaine par défaut.
          </div>

          <div className="cin-list">
            {results.map((r, i) => (
              <div key={i} className="cin-card">
                <div className="cin-card-head">
                  <span className="cin-card-index">{i + 1}</span>
                  <div className="cin-head-fields">
                    <input
                      className="form-input cin-name"
                      value={r.nom_prenom || ''}
                      onChange={e => updateResult(i, 'nom_prenom', toWesternDigits(e.target.value))}
                      placeholder="Nom et prénom"
                    />
                    <input
                      className="form-input cin-name-ar"
                      value={toWesternDigits(r.nom_ar || '')}
                      onChange={e => updateResult(i, 'nom_ar', e.target.value)}
                      placeholder="الاسم بالعربية"
                      style={{ direction: 'rtl', textAlign: 'right' }}
                    />
                  </div>
                  {r.existing && <Badge label="Déjà existant" color="red" />}
                  <button className="btn btn-sm btn-danger" onClick={() => removeResult(i)}>
                    Retirer
                  </button>
                </div>

                <div className="cin-card-grid">
                  <div className="cin-field">
                    <label className="form-label">CIN</label>
                    <input
                      className="form-input"
                      value={r.cin || ''}
                      onChange={e => updateResult(i, 'cin', toWesternDigits(e.target.value))}
                      style={{ textTransform: 'uppercase' }}
                    />
                    <span className="cin-ar-auto">الرقم: {toWesternDigits(r.cin_ar || '')}</span>
                  </div>

                  <div className="cin-field">
                    <label className="form-label">Date de naissance</label>
                    <input
                      type="date"
                      className="form-input"
                      value={r.date_naissance || ''}
                      onChange={e => updateResult(i, 'date_naissance', e.target.value)}
                    />
                    <span className="cin-ar-auto">{r.date_naissance_ar || ''}</span>
                  </div>

                  <div className="cin-field">
                    <label className="form-label">Lieu de naissance</label>
                    <input
                      className="form-input"
                      value={r.lieu_naissance || ''}
                      onChange={e => updateResult(i, 'lieu_naissance', toWesternDigits(e.target.value))}
                    />
                    <input
                      className="form-input cin-ar-input"
                      value={r.lieu_naissance_ar || ''}
                      onChange={e => updateResult(i, 'lieu_naissance_ar', e.target.value)}
                      style={{ direction: 'rtl', textAlign: 'right' }}
                    />
                  </div>

                  <div className="cin-field">
                    <label className="form-label">Nationalité</label>
                    <input
                      className="form-input"
                      value={r.nationalite || ''}
                      onChange={e => updateResult(i, 'nationalite', toWesternDigits(e.target.value))}
                    />
                    <input
                      className="form-input cin-ar-input"
                      value={r.nationalite_ar || ''}
                      onChange={e => updateResult(i, 'nationalite_ar', e.target.value)}
                      style={{ direction: 'rtl', textAlign: 'right' }}
                    />
                  </div>

                  <div className="cin-field">
                    <label className="form-label">N° inscription</label>
                    <input
                      className="form-input"
                      value={r.numero_inscription || ''}
                      readOnly
                      style={{ background: 'var(--gray-50)', color: 'var(--gray-500)' }}
                    />
                  </div>
                </div>
              </div>
            ))}
          </div>

          <div style={{ display: 'flex', gap: 8, alignItems: 'center', marginTop: 16 }}>
            <button className="btn btn-outline" onClick={() => setStep(2)}>← Retour</button>
            {results.some(r => r.existing) && (
              <span style={{ color: 'var(--danger)', fontSize: 13 }}>Retirez les étudiants en double pour confirmer</span>
            )}
            <button
              className="btn btn-primary"
              disabled={results.length === 0 || saving || results.some(r => r.existing)}
              onClick={handleConfirm}
            >
              {saving ? 'Enregistrement...' : `Confirmer ${results.length} étudiant(s)`}
            </button>
            <button className="btn btn-outline" style={{ marginLeft: 'auto' }} onClick={() => { setResults([]); setStep(1); }}>
              Terminer
            </button>
          </div>
        </div>
      )}
    </div>
  );
}
