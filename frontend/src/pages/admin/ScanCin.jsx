import { useEffect, useState } from 'react';
import { adminApi } from '../../api/admin';
import { scanApi } from '../../api/scan';
import { useToast } from '../../context/ToastContext';
import { useAnneeAcademique } from '../../context/AnneeAcademiqueContext';
import Spinner from '../../components/common/Spinner';
import Badge from '../../components/common/Badge';
import { formatNiveau, toWesternDigits } from '../../utils/helpers';
import '../../css/components.css';
import '../../css/layout.css';

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
        .then(res => setNiveaux(res.data.data));
    } else {
      setNiveaux([]);
    }
    setSelNiveau('');
    setGroupes([]);
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

  const handleImages = (e) => {
    const files = Array.from(e.target.files);
    setImages(files);
  };

  const handleScan = async () => {
    if (images.length === 0 || !currentAnnee?.id || !selGroupe) return;
    setScanning(true);
    try {
      const fd = new FormData();
      images.forEach(img => fd.append('pdfs[]', img));
      fd.append('groupe_id', selGroupe);
      fd.append('annee_academique_id', currentAnnee.id);
      fd.append('filiere_code', filiereCode);

      const res = await scanApi.scanCin(fd);
      const data = res.data.data || {};
      const scanned = data.resultats || [];

      if (scanned.length === 0) {
        toast.error('Aucun étudiant trouvé. Vérifiez que les fichiers PDF sont lisibles.');
      } else {
        setResults(scanned);
        setStep(3);
      }
    } catch (error) {
      toast.error(`Erreur de lecture: ${error.message || 'Erreur inconnue'}`);
      setResults([]);
    } finally {
      setScanning(false);
    }
  };

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
        filiere_code: filiereCode
      });
      toast.success(`${res.data.data.crees} étudiants créés`);
      setStep(1);
      setResults([]);
      setImages([]);
    } catch {
      toast.error('Erreur de confirmation');
    } finally {
      setSaving(false);
    }
  };

  const updateResult = (i, key, val) => {
    setResults(prev => prev.map((r, idx) => idx === i ? { ...r, [key]: val } : r));
  };

  const removeResult = (i) => {
    setResults(prev => prev.filter((_, idx) => idx !== i));
  };

  return (
    <div className="page">
      <div className="page-header">
        <h2 className="page-title">Scan CIN — Ajout Stagiaires</h2>
      </div>

      {/* Steps */}
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

      {/* Step 1 */}
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
            <select className="form-select" value={selNiveau} onChange={e => setSelNiveau(e.target.value)} disabled={!selFiliere}>
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
          <button
            className="btn btn-primary"
            disabled={!selGroupe}
            onClick={() => setStep(2)}
          >
            Suivant →
          </button>
        </div>
      )}

      {/* Step 2 */}
      {step === 2 && (
        <div className="card card-body" style={{ maxWidth: 500 }}>
          <div className="upload-zone">
            <input
              type="file"
              accept=".pdf"
              multiple
              onChange={handleImages}
            />
            <div className="upload-zone-icon">📄</div>
            <div className="upload-zone-text">
              <strong>Cliquer ou glisser</strong> les fichiers PDF ici
            </div>
          </div>

          {images.length > 0 && (
            <div style={{ marginTop: 12, fontSize: 13, color: 'var(--gray-600)' }}>
              {images.length} fichier(s) PDF sélectionné(s)
            </div>
          )}

          <div style={{ display: 'flex', gap: 8, marginTop: 16 }}>
            <button className="btn btn-outline" onClick={() => setStep(1)}>← Retour</button>
            <button
              className="btn btn-primary"
              disabled={images.length === 0 || scanning}
              onClick={handleScan}
            >
              {scanning ? 'Lecture en cours...' : 'Scanner les PDF'}
            </button>
          </div>

          {scanning && <Spinner />}
        </div>
      )}

      {/* Step 3 */}
      {step === 3 && (
        <div>
          <div className="alert alert-info" style={{ marginBottom: 16 }}>
            Vérifiez et corrigez les informations avant de confirmer
          </div>

          <div className="table-wrap" style={{ marginBottom: 16, overflowX: 'auto' }}>
            <table style={{ minWidth: '800px' }}>
              <thead>
                <tr>
                  <th>Nom et Prénom</th>
                  <th>الاسم بالعربية</th>
                  <th>CIN</th>
                  <th>رقم البطاقة</th>
                  <th>تاريخ الميلاد</th>
                  <th>Date naissance</th>
                  <th>مكان الميلاد</th>
                  <th>Lieu naissance</th>
                  <th>الجنسية</th>
                  <th>Nationalité</th>
                  <th>Date inscription</th>
                  <th>تاريخ التسجيل</th>
                  <th>رقم التسجيل</th>
                  <th>Actions</th>
                </tr>
              </thead>
              <tbody>
                {results.length === 0 ? (
                  <tr><td colSpan={14} className="table-empty">Aucun résultat</td></tr>
                ) : results.map((r, i) => (
                  <tr key={i}>
                    <td>
                      <input
                        className="form-input"
                        value={r.nom_prenom}
                        onChange={e => updateResult(i, 'nom_prenom', e.target.value)}
                      />
                    </td>
                    <td>
                      <input
                        className="form-input"
                        value={toWesternDigits(r.nom_ar || '')}
                        onChange={e => updateResult(i, 'nom_ar', toWesternDigits(e.target.value))}
                        style={{ direction: 'rtl', textAlign: 'right' }}
                      />
                    </td>
                    <td>
                      <div style={{ display: 'flex', alignItems: 'center', gap: 4 }}>
                        <input
                          className="form-input"
                          value={r.cin}
                          onChange={e => updateResult(i, 'cin', e.target.value)}
                          style={{ width: '100px' }}
                        />
                        {r.existing && <Badge label="Exist" color="red" />}
                      </div>
                    </td>
                    <td>
                      <input
                        className="form-input"
                        value={toWesternDigits(r.cin_ar || '')}
                        onChange={e => updateResult(i, 'cin_ar', toWesternDigits(e.target.value))}
                        style={{ direction: 'rtl', textAlign: 'right', width: '120px' }}
                      />
                    </td>
                    <td>
                      <input
                        className="form-input"
                        value={toWesternDigits(r.date_naissance_ar || '')}
                        onChange={e => updateResult(i, 'date_naissance_ar', toWesternDigits(e.target.value))}
                        style={{ direction: 'rtl', textAlign: 'right', width: '130px' }}
                      />
                    </td>
                    <td>
                      <input
                        className="form-input"
                        value={r.date_naissance || ''}
                        onChange={e => updateResult(i, 'date_naissance', e.target.value)}
                        style={{ width: '130px' }}
                      />
                    </td>
                    <td>
                      <input
                        className="form-input"
                        value={toWesternDigits(r.lieu_naissance_ar || '')}
                        onChange={e => updateResult(i, 'lieu_naissance_ar', toWesternDigits(e.target.value))}
                        style={{ direction: 'rtl', textAlign: 'right', width: '130px' }}
                      />
                    </td>
                    <td>
                      <input
                        className="form-input"
                        value={r.lieu_naissance || ''}
                        onChange={e => updateResult(i, 'lieu_naissance', e.target.value)}
                        style={{ width: '130px' }}
                      />
                    </td>
                    <td>
                      <input
                        className="form-input"
                        value={toWesternDigits(r.nationalite_ar || '')}
                        onChange={e => updateResult(i, 'nationalite_ar', toWesternDigits(e.target.value))}
                        style={{ direction: 'rtl', textAlign: 'right', width: '130px' }}
                      />
                    </td>
                    <td>
                      <input
                        className="form-input"
                        value={r.nationalite || ''}
                        onChange={e => updateResult(i, 'nationalite', e.target.value)}
                        style={{ width: '130px' }}
                      />
                    </td>
                    <td>
                      <input
                        className="form-input"
                        value={r.date_inscription || ''}
                        onChange={e => updateResult(i, 'date_inscription', e.target.value)}
                        style={{ width: '130px' }}
                      />
                    </td>
                    <td>
                      <input
                        className="form-input"
                        value={toWesternDigits(r.date_inscription_ar || '')}
                        onChange={e => updateResult(i, 'date_inscription_ar', toWesternDigits(e.target.value))}
                        style={{ direction: 'rtl', textAlign: 'right', width: '130px' }}
                      />
                    </td>
                    <td>
                      <input
                        className="form-input"
                        value={toWesternDigits(r.numero_inscription_ar || '')}
                        onChange={e => updateResult(i, 'numero_inscription_ar', toWesternDigits(e.target.value))}
                        style={{ direction: 'rtl', textAlign: 'right', width: '130px' }}
                      />
                    </td>
                    <td>
                      <button className="btn btn-sm btn-danger" onClick={() => removeResult(i)}>
                        Retirer
                      </button>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>

          <div style={{ display: 'flex', gap: 8, alignItems: 'center' }}>
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
          </div>
        </div>
      )}
    </div>
  );
}
