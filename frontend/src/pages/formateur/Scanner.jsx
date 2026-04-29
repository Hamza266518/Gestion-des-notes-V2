import { useEffect, useState } from 'react';
import { adminApi } from '../../api/admin';
import { unitesApi } from '../../api/unites';
import { sequencesApi } from '../../api/sequences';
import { controlesApi } from '../../api/controles';
import { notesApi } from '../../api/notes';
import { scanNotesPaper } from '../../api/gemini';
import { useToast } from '../../context/ToastContext';
import Spinner from '../../components/common/Spinner';
import { handleApiError, showSuccess } from '../../utils/errorHandler';
import '../../css/components.css';
import '../../css/layout.css';

export default function Scanner() {
  const [step, setStep] = useState(1);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);
  const [sel, setSel] = useState({
    filiere_id: '', niveau_id: '', groupe_id: '',
    unite_id: '', sequence_id: '', controle_id: ''
  });
  const [filieres, setFilieres] = useState([]);
  const [niveaux, setNiveaux] = useState([]);
  const [groupes, setGroupes] = useState([]);
  const [unites, setUnites] = useState([]);
  const [sequences, setSequences] = useState([]);
  const [controles, setControles] = useState([]);

  const [images, setImages] = useState([]);
  const [results, setResults] = useState([]);
  const [scanning, setScanning] = useState(false);
  const [saving, setSaving] = useState(false);
  const [savingResults, setSavingResults] = useState(false);

  const toast = useToast();

  useEffect(() => {
    adminApi.getFilieres()
      .then(r => setFilieres(r.data.data))
      .catch((error) => handleApiError(error, toast))
      .finally(() => setLoading(false));
  }, []);

  useEffect(() => {
    if (sel.filiere_id) {
      adminApi.getNiveaux(sel.filiere_id)
        .then(r => setNiveaux(r.data.data))
        .catch((error) => handleApiError(error, toast));
    } else {
      setNiveaux([]);
    }
  }, [sel.filiere_id]);

  useEffect(() => {
    if (sel.niveau_id) {
      adminApi.getGroupes({ niveau_id: sel.niveau_id })
        .then(r => setGroupes(r.data.data))
        .catch((error) => handleApiError(error, toast));
    } else {
      setGroupes([]);
    }
  }, [sel.niveau_id]);

  useEffect(() => {
    if (sel.filiere_id) {
      unitesApi.getUnites({ filiere_id: sel.filiere_id })
        .then(r => setUnites(r.data.data))
        .catch((error) => handleApiError(error, toast));
    } else {
      setUnites([]);
    }
  }, [sel.filiere_id]);

  useEffect(() => {
    if (sel.unite_id) {
      sequencesApi.getSequences(sel.unite_id)
        .then(r => setSequences(r.data.data))
        .catch((error) => handleApiError(error, toast));
    } else {
      setSequences([]);
    }
  }, [sel.unite_id]);

  useEffect(() => {
    if (sel.sequence_id) {
      controlesApi.getControles(sel.sequence_id)
        .then(r => setControles(r.data.data))
        .catch((error) => handleApiError(error, toast));
    } else {
      setControles([]);
    }
  }, [sel.sequence_id]);

  const handleImages = (e) => {
    const files = Array.from(e.target.files);
    setImages(files);
  };

  const handleScan = async () => {
    if (images.length === 0) {
      toast.warning('Veuillez sélectionner au moins une image');
      return;
    }

    setScanning(true);
    setResults([]);

    try {
      const scanned = [];

      for (const img of images) {
        try {
          const data = await scanNotesPaper(img);
          if (Array.isArray(data)) {
            for (const row of data) {
              if (row.nom && row.note !== undefined) {
                scanned.push({
                  nom: row.nom,
                  note: row.note,
                  confidence: 'high'
                });
              }
            }
          }
        } catch (imgError) {
          console.error('Error scanning individual image:', imgError);
          // Continue with other images
        }
      }

      if (scanned.length === 0) {
        toast.error('Aucun résultat trouvé. Vérifiez que l\'image est lisible.');
      } else {
        showSuccess(toast, `${scanned.length} note(s) détectée(s)`);
      }

      setResults(scanned);
      setStep(3);
    } catch (error) {
      console.error('Scan error:', error);
      handleApiError(error, toast);
      setResults([]);
    } finally {
      setScanning(false);
    }
  };

  const updateResult = (i, key, val) => {
    setResults(prev => prev.map((r, idx) => idx === i ? { ...r, [key]: val } : r));
  };

  const removeResult = (i) => {
    setResults(prev => prev.filter((_, idx) => idx !== i));
  };

  const handleConfirm = async () => {
    if (!sel.controle_id || !sel.groupe_id) {
      toast.error('Veuillez sélectionner un contrôle et un groupe');
      return;
    }

    const validResults = results.filter(r => r.note >= 0 && r.note <= 20);
    if (validResults.length === 0) {
      toast.error('Aucune note valide à enregistrer');
      return;
    }

    setSavingResults(true);

    try {
      let savedCount = 0;
      let errorCount = 0;

      for (const r of validResults) {
        try {
          await notesApi.createNote({
            controle_id: sel.controle_id,
            etudiant_nom: r.nom,
            valeur: r.note,
            groupe_id: sel.groupe_id
          });
          savedCount++;
        } catch (noteError) {
          console.error('Error saving note for', r.nom, ':', noteError);
          errorCount++;
        }
      }

      if (errorCount > 0) {
        toast.warning(`${savedCount} note(s) enregistrée(s), ${errorCount} en échec`);
      } else {
        showSuccess(toast, `${savedCount} note(s) enregistrée(s)`);
      }

      setStep(1);
      setResults([]);
      setImages([]);
    } catch (error) {
      console.error('Confirm error:', error);
      handleApiError(error, toast);
    } finally {
      setSavingResults(false);
    }
  };

  if (loading) return (
    <div className="text-center mt-5">
      <Spinner />
      <p className="mt-2 text-muted">Chargement des données...</p>
    </div>
  );

  return (
    <div className="page">
      <div className="page-header">
        <h2 className="page-title">Scanner Notes</h2>
      </div>

      {error && (
        <div className="alert alert-danger" style={{ marginBottom: 16 }}>
          <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
            <span>{error}</span>
            <button className="btn btn-sm btn-outline" onClick={() => window.location.reload()}>Réessayer</button>
          </div>
        </div>
      )}

      {/* Steps */}
      <div className="steps" style={{ marginBottom: 28 }}>
        {['Sélection', 'Photos', 'Résultats', 'Vérification', 'Confirmer'].map((label, i) => (
          <div key={i} style={{ display: 'flex', alignItems: 'center', flex: 1 }}>
            <div style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
              <div style={{
                width: 28, height: 28, borderRadius: '50%',
                background: step > i + 1 ? 'var(--success)' : step === i + 1 ? 'var(--primary)' : 'var(--gray-200)',
                color: step >= i + 1 ? 'white' : 'var(--gray-500)',
                display: 'flex', alignItems: 'center', justifyContent: 'center',
                fontSize: 12, fontWeight: 600
              }}>
                {step > i + 1 ? '✓' : i + 1}
              </div>
              <span style={{ fontSize: 13, color: step === i + 1 ? 'var(--primary)' : 'var(--gray-400)' }}>
                {label}
              </span>
            </div>
            {i < 4 && <div style={{ flex: 1, height: 2, background: 'var(--gray-200)', margin: '0 12px' }} />}
          </div>
        ))}
      </div>

      {/* Step 1: Select */}
      {step === 1 && (
        <div className="card card-body" style={{ maxWidth: 800, width: '90vw' }}>
          {filieres.length === 0 && (
            <div className="alert alert-warning" style={{ marginBottom: 16 }}>
              <strong>Aucune filière trouvée.</strong> Veuillez contacter l'administrateur.
            </div>
          )}
          <div className="filter-bar" style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(200px, 1fr))', gap: 12 }}>
            <select className="form-select" value={sel.filiere_id} onChange={e => setSel(p => ({ ...p, filiere_id: e.target.value, unite_id: '', sequence_id: '', controle_id: '' }))}>
              <option value="">Filière *</option>
              {filieres.map(f => <option key={f.id} value={f.id}>{f.nom}</option>)}
            </select>
            <select className="form-select" value={sel.niveau_id} onChange={e => setSel(p => ({ ...p, niveau_id: e.target.value, groupe_id: '' }))} disabled={!sel.filiere_id}>
              <option value="">Niveau</option>
              {niveaux.map(n => <option key={n.id} value={n.id}>{n.numero}ère année</option>)}
            </select>
            <select className="form-select" value={sel.groupe_id} onChange={e => setSel(p => ({ ...p, groupe_id: e.target.value }))} disabled={!sel.niveau_id}>
              <option value="">Groupe</option>
              {groupes.map(g => <option key={g.id} value={g.id}>{g.nom}</option>)}
            </select>
            <select className="form-select" value={sel.unite_id} onChange={e => setSel(p => ({ ...p, unite_id: e.target.value, sequence_id: '', controle_id: '' }))} disabled={!sel.filiere_id}>
              <option value="">Unité *</option>
              {unites.map(u => <option key={u.id} value={u.id}>{u.nom}</option>)}
            </select>
            <select className="form-select" value={sel.sequence_id} onChange={e => setSel(p => ({ ...p, sequence_id: e.target.value, controle_id: '' }))} disabled={!sel.unite_id}>
              <option value="">Séquence *</option>
              {sequences.map(s => <option key={s.id} value={s.id}>{s.nom}</option>)}
            </select>
            <select className="form-select" value={sel.controle_id} onChange={e => setSel(p => ({ ...p, controle_id: e.target.value }))} disabled={!sel.sequence_id}>
              <option value="">Contrôle *</option>
              {controles.map(c => <option key={c.id} value={c.id}>Contrôle {c.numero}</option>)}
            </select>
          </div>
          <button className="btn btn-primary" style={{ marginTop: 16 }} disabled={!sel.controle_id} onClick={() => setStep(2)}>
            Suivant →
          </button>
        </div>
      )}

      {/* Step 2: Upload */}
      {step === 2 && (
        <div className="card card-body" style={{ maxWidth: 1000, width: '90vw' }}>
          <div className="upload-zone">
            <input type="file" accept="image/*" multiple onChange={handleImages} />
            <div className="upload-zone-icon">📷</div>
            <div className="upload-zone-text">
              <strong>Cliquer ou glisser</strong> les photos de notes
            </div>
          </div>
          {images.length > 0 && (
            <div style={{ marginTop: 12, fontSize: 13 }}>{images.length} image(s) sélectionnée(s)</div>
          )}
          <div style={{ display: 'flex', gap: 8, marginTop: 16 }}>
            <button className="btn btn-outline" onClick={() => setStep(1)}>← Retour</button>
            <button className="btn btn-primary" disabled={images.length === 0 || scanning} onClick={handleScan}>
              {scanning ? (
                <>
                  <Spinner /> Lecture en cours...
                </>
              ) : 'Scanner'}
            </button>
          </div>
        </div>
      )}

      {/* Step 3: Results */}
      {step === 3 && (
        <div style={{ maxWidth: 1200, width: '90vw', minWidth: '900px', margin: '0 auto' }}>
          <div className="alert alert-info" style={{ marginBottom: 16 }}>
            Vérifiez les résultats avant de confirmer. Vous pouvez modifier les noms et notes si nécessaire.
          </div>
          {results.length === 0 ? (
            <div className="text-center" style={{ padding: 40 }}>
              <div style={{ fontSize: 48, marginBottom: 16 }}>📄</div>
              <h4 style={{ color: '#666', marginBottom: 12 }}>Aucun résultat</h4>
              <p style={{ color: '#999', marginBottom: 24 }}>
                Aucune note n'a été détectée. Vérifiez que les photos sont claires et réessayez.
              </p>
              <button className="btn btn-primary" onClick={() => setStep(2)}>
                ← Retour aux photos
              </button>
            </div>
          ) : (
            <>
              <div style={{ overflowX: 'auto', marginBottom: 16, border: '1px solid #e5e7eb', borderRadius: 8 }}>
                <table style={{ fontSize: '15px', minWidth: '500px', width: '100%' }}>
                  <thead>
                    <tr style={{ backgroundColor: '#f9fafb' }}>
                      <th style={{ padding: '12px 16px', textAlign: 'left', fontWeight: 600, minWidth: '250px' }}>Nom</th>
                      <th style={{ padding: '12px 16px', textAlign: 'left', fontWeight: 600, minWidth: '100px' }}>Note /20</th>
                      <th style={{ padding: '12px 16px', textAlign: 'left', fontWeight: 600, minWidth: '100px' }}>Actions</th>
                    </tr>
                  </thead>
                  <tbody>
                    {results.map((r, i) => (
                      <tr key={i} style={{ borderBottom: '1px solid #e5e7eb' }}>
                        <td style={{ padding: '12px 16px' }}>
                          <input
                            className="form-input"
                            value={r.nom}
                            onChange={e => updateResult(i, 'nom', e.target.value)}
                            style={{ minWidth: '220px', width: '100%', padding: '8px 12px', fontSize: '15px' }}
                          />
                        </td>
                        <td style={{ padding: '12px 16px' }}>
                          <input
                            type="number"
                            className="form-input"
                            style={{ width: 70, padding: '8px 12px', fontSize: '15px' }}
                            value={r.note}
                            min={0}
                            max={20}
                            onChange={e => updateResult(i, 'note', Number(e.target.value))}
                          />
                        </td>
                        <td style={{ padding: '12px 16px' }}>
                          <button className="btn btn-sm btn-danger" onClick={() => removeResult(i)}>Retirer</button>
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
              <div style={{ display: 'flex', gap: 8 }}>
                <button className="btn btn-outline" onClick={() => setStep(2)}>← Retour</button>
                <button className="btn btn-primary" onClick={() => setStep(4)}>Suivant →</button>
              </div>
            </>
          )}
        </div>
      )}

      {/* Step 4: Review */}
      {step === 4 && (
        <div>
          <div className="alert alert-warning" style={{ marginBottom: 16 }}>
            <strong>Confirmez pour enregistrer les notes</strong> — toute modification sera impossible après.
          </div>
          <div style={{ display: 'flex', gap: 8 }}>
            <button className="btn btn-outline" onClick={() => setStep(3)}>← Retour</button>
            <button className="btn btn-primary" disabled={savingResults} onClick={handleConfirm}>
              {savingResults ? (
                <>
                  <Spinner /> Enregistrement...
                </>
              ) : `Confirmer ${results.length} note(s)`}
            </button>
          </div>
        </div>
      )}
    </div>
  );
}
