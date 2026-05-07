import { useEffect, useState } from 'react';
import { adminApi } from '../../api/admin';
import { scanStudentList } from '../../api/gemini';
import { useToast } from '../../context/ToastContext';
import { useAnneeAcademique } from '../../context/AnneeAcademiqueContext';
import Spinner from '../../components/common/Spinner';
import { formatNiveau } from '../../utils/helpers';
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
    if (images.length === 0 || !currentAnnee?.id) return;
    setScanning(true);
    const scanned = [];
    try {
      for (const img of images) {
        const data = await scanStudentList(img);
        if (Array.isArray(data)) {
          for (const student of data) {
            if (student.nom_prenom) {
              scanned.push({
                nom_prenom: student.nom_prenom ?? '',
                nom_ar: student.nom_ar ?? '',
                cin: student.cin ?? '',
                cin_ar: student.cin_ar ?? '',
                date_naissance_ar: student.date_naissance_ar ?? '',
                lieu_naissance_ar: student.lieu_naissance_ar ?? '',
                nationalite_ar: student.nationalite_ar ?? '',
                numero_inscription_ar: student.numero_inscription_ar ?? '',
                date_inscription_ar: student.date_inscription_ar ?? '',
                numero_inscription: '',
                groupe_id: selGroupe,
                annee_academique_id: currentAnnee.id,
              });
            }
          }
        }
      }
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
          date_naissance_ar: r.date_naissance_ar || null,
          lieu_naissance_ar: r.lieu_naissance_ar || null,
          cin_ar: r.cin_ar || null,
          nationalite_ar: r.nationalite_ar || null,
          numero_inscription_ar: r.numero_inscription_ar || null,
          date_inscription_ar: r.date_inscription_ar || null,
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
                  <th>مكان الميلاد</th>
                  <th>الجنسية</th>
                  <th>Actions</th>
                </tr>
              </thead>
              <tbody>
                {results.length === 0 ? (
                  <tr><td colSpan={8} className="table-empty">Aucun résultat</td></tr>
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
                        value={r.nom_ar || ''}
                        onChange={e => updateResult(i, 'nom_ar', e.target.value)}
                        style={{ direction: 'rtl', textAlign: 'right' }}
                      />
                    </td>
                    <td>
                      <input
                        className="form-input"
                        value={r.cin}
                        onChange={e => updateResult(i, 'cin', e.target.value)}
                        style={{ width: '120px' }}
                      />
                    </td>
                    <td>
                      <input
                        className="form-input"
                        value={r.cin_ar || ''}
                        onChange={e => updateResult(i, 'cin_ar', e.target.value)}
                        style={{ direction: 'rtl', textAlign: 'right', width: '120px' }}
                      />
                    </td>
                    <td>
                      <input
                        className="form-input"
                        value={r.date_naissance_ar || ''}
                        onChange={e => updateResult(i, 'date_naissance_ar', e.target.value)}
                        style={{ direction: 'rtl', textAlign: 'right', width: '130px' }}
                      />
                    </td>
                    <td>
                      <input
                        className="form-input"
                        value={r.lieu_naissance_ar || ''}
                        onChange={e => updateResult(i, 'lieu_naissance_ar', e.target.value)}
                        style={{ direction: 'rtl', textAlign: 'right', width: '130px' }}
                      />
                    </td>
                    <td>
                      <input
                        className="form-input"
                        value={r.nationalite_ar || ''}
                        onChange={e => updateResult(i, 'nationalite_ar', e.target.value)}
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

          <div style={{ display: 'flex', gap: 8 }}>
            <button className="btn btn-outline" onClick={() => setStep(2)}>← Retour</button>
            <button
              className="btn btn-primary"
              disabled={results.length === 0 || saving}
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
