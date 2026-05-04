import { useEffect, useState } from 'react';
import { FiX } from 'react-icons/fi';
import { formateursApi } from '../../api/formateurs';
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
  const [filieres, setFilieres] = useState([]);
  const [sequences, setSequences] = useState([]);
  const [sel, setSel] = useState({
    filiere_id: '', sequence_id: '', controle_id: ''
  });

  const [images, setImages] = useState([]);
  const [results, setResults] = useState([]);
  const [scanning, setScanning] = useState(false);
  const [savingResults, setSavingResults] = useState(false);

  const toast = useToast();

  useEffect(() => {
    setLoading(true);
    formateursApi.getScanData()
      .then(r => {
        const data = r.data.data;
        setFilieres(data.filieres || []);
        setSequences(data.sequences || []);
      })
      .catch((err) => {
        const info = handleApiError(err, toast, { showToast: false });
        setError(info.message);
      })
      .finally(() => setLoading(false));

    return () => {
      imagePreviews.forEach(url => URL.revokeObjectURL(url));
    };
  }, []);

  const filteredSequences = sel.filiere_id
    ? sequences.filter(s => s.unite?.filiere_id == sel.filiere_id)
    : sequences;

  const filteredControles = sel.sequence_id
    ? (sequences.find(s => s.id == sel.sequence_id)?.controles || [])
    : [];

  const [imagePreviews, setImagePreviews] = useState([]);

  const handleImages = (e) => {
    const files = Array.from(e.target.files);
    setImages(files);
    const previews = files.map(f => URL.createObjectURL(f));
    setImagePreviews(previews);
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
    } catch (err) {
      handleApiError(err, toast);
      setResults([]);
    } finally {
      setScanning(false);
    }
  };

  const removeImage = (i) => {
    URL.revokeObjectURL(imagePreviews[i]);
    setImages(prev => prev.filter((_, idx) => idx !== i));
    setImagePreviews(prev => prev.filter((_, idx) => idx !== i));
  };

  const updateResult = (i, key, val) => {
    setResults(prev => prev.map((r, idx) => idx === i ? { ...r, [key]: val } : r));
  };

  const removeResult = (i) => {
    setResults(prev => prev.filter((_, idx) => idx !== i));
  };

  const handleConfirm = async () => {
    if (results.length === 0 || !sel.controle_id) return;

    setSavingResults(true);
    try {
      for (const r of results) {
        await notesApi.createNote({
          controle_id: sel.controle_id,
          etudiant_nom: r.nom,
          valeur: r.note,
        });
      }
      showSuccess(toast, `${results.length} note(s) enregistrée(s)`);
      setStep(1);
      setImages([]);
      setResults([]);
    } catch (err) {
      handleApiError(err, toast);
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
            <select className="form-select" value={sel.filiere_id} onChange={e => setSel(p => ({ ...p, filiere_id: e.target.value, sequence_id: '', controle_id: '' }))}>
              <option value="">Filière</option>
              {filieres.map(f => <option key={f.id} value={f.id}>{f.nom}</option>)}
            </select>
            <select className="form-select" value={sel.sequence_id} onChange={e => setSel(p => ({ ...p, sequence_id: e.target.value, controle_id: '' }))} disabled={!sel.filiere_id}>
              <option value="">Séquence</option>
              {filteredSequences.map(s => <option key={s.id} value={s.id}>{s.unite?.nom} — {s.nom}</option>)}
            </select>
            <select className="form-select" value={sel.controle_id} onChange={e => setSel(p => ({ ...p, controle_id: e.target.value }))} disabled={!sel.sequence_id}>
              <option value="">Contrôle</option>
              {filteredControles.map(c => <option key={c.id} value={c.id}>Contrôle {c.numero}</option>)}
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
            <div className="upload-zone-text">
              <strong>Cliquer ou glisser</strong> les photos de notes
            </div>
          </div>
          {images.length > 0 && (
            <div style={{ marginTop: 20 }}>
              <div style={{ fontSize: 14, fontWeight: 600, marginBottom: 8 }}>{images.length} image(s) sélectionnée(s)</div>
              <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fill, minmax(180px, 1fr))', gap: 12 }}>
                {imagePreviews.map((src, i) => (
                  <div key={i} style={{ position: 'relative', borderRadius: 8, overflow: 'hidden', border: '1px solid #e5e7eb' }}>
                    <img src={src} alt={`Page ${i + 1}`} style={{ width: '100%', height: 200, objectFit: 'cover', display: 'block' }} />
                    <div style={{ position: 'absolute', top: 6, right: 6, background: 'rgba(239,68,68,0.92)', color: '#fff', border: 'none', borderRadius: 6, width: 30, height: 30, display: 'flex', alignItems: 'center', justifyContent: 'center', cursor: 'pointer', boxShadow: '0 2px 6px rgba(0,0,0,0.3)' }} onClick={() => removeImage(i)}>
                      <FiX size={18} />
                    </div>
                    <div style={{ position: 'absolute', bottom: 0, left: 0, right: 0, background: 'rgba(0,0,0,0.5)', color: '#fff', padding: '4px 8px', fontSize: 12, textAlign: 'center' }}>
                      Page {i + 1}
                    </div>
                  </div>
                ))}
              </div>
            </div>
          )}
          <div style={{ display: 'flex', gap: 8, marginTop: 16 }}>
            <button className="btn btn-outline" onClick={() => setStep(1)}>Retour</button>
            <button className="btn btn-primary" disabled={images.length === 0 || scanning} onClick={handleScan}>
              {scanning ? 'Lecture en cours...' : 'Scanner'}
            </button>
          </div>
          {scanning && (
            <div style={{ textAlign: 'center', padding: 20, marginTop: 16 }}>
              <Spinner />
              <p style={{ marginTop: 8, color: '#666' }}>Lecture des notes en cours...</p>
            </div>
          )}
        </div>
      )}

      {/* Step 3: Results */}
      {step === 3 && (
        <div style={{ maxWidth: 1400, width: '95vw', margin: '0 auto' }}>
          <div className="alert alert-info" style={{ marginBottom: 16 }}>
            Vérifiez les résultats avant de confirmer. Vous pouvez modifier les noms et notes si nécessaire.
          </div>
          {results.length === 0 ? (
            <div className="text-center" style={{ padding: 40 }}>
              <h4 style={{ color: '#666', marginBottom: 12 }}>Aucune note detectee</h4>
              <p style={{ color: '#999', marginBottom: 24 }}>
                Verifiez l'image ou ressayer le scan.
              </p>
              <div style={{ display: 'flex', gap: 12, justifyContent: 'center' }}>
                <button className="btn btn-primary" onClick={() => setStep(2)}>
                  Retour aux photos
                </button>
                <button className="btn btn-outline" onClick={handleScan}>
                  Ressayer le scan
                </button>
              </div>
            </div>
          ) : (
            <div style={{ display: 'grid', gridTemplateColumns: '320px 1fr', gap: 20 }}>
              <div>
                <h5 style={{ marginBottom: 8, fontWeight: 600 }}>Images uploadées</h5>
                <div style={{ display: 'flex', flexDirection: 'column', gap: 8, maxHeight: '70vh', overflowY: 'auto' }}>
                  {imagePreviews.map((src, i) => (
                    <div key={i} style={{ position: 'relative', borderRadius: 8, overflow: 'hidden', border: '1px solid #e5e7eb', background: '#fff' }}>
                      <img src={src} alt={`Page ${i + 1}`} style={{ width: '100%', height: 220, objectFit: 'contain', display: 'block', background: '#f9fafb' }} />
                      <div style={{ position: 'absolute', top: 4, left: 4, background: 'var(--primary)', color: '#fff', borderRadius: '50%', width: 24, height: 24, display: 'flex', alignItems: 'center', justifyContent: 'center', fontSize: 11, fontWeight: 700 }}>
                        {i + 1}
                      </div>
                      <button
                        onClick={() => removeImage(i)}
                        style={{ position: 'absolute', top: 4, right: 4, background: 'rgba(239,68,68,0.9)', color: '#fff', border: 'none', borderRadius: 4, width: 28, height: 28, display: 'flex', alignItems: 'center', justifyContent: 'center', cursor: 'pointer', fontSize: 16 }}
                        title="Retirer cette image"
                      >
                        <FiX />
                      </button>
                    </div>
                  ))}
                </div>
              </div>
              <div>
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
              </div>
            </div>
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
