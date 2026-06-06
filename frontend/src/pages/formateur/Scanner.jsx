import { useEffect, useState, useRef } from 'react';
import { FiX } from 'react-icons/fi';
import { formateursApi } from '../../api/formateurs';
import { scanApi } from '../../api/scan';
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

  const [pdfs, setPdfs] = useState([]);
  const [results, setResults] = useState([]);
  const [scanning, setScanning] = useState(false);
  const [savingResults, setSavingResults] = useState(false);
  const [pdfUrls, setPdfUrls] = useState([]);
  const [allConfirmed, setAllConfirmed] = useState(false);

  const toast = useToast();

  const [searchQueries, setSearchQueries] = useState({});
  const [searchMatches, setSearchMatches] = useState({});
  const [searchActiveIndex, setSearchActiveIndex] = useState(null);
  const [searchDropdownStyle, setSearchDropdownStyle] = useState({});
  const searchTimer = useRef(null);

  useEffect(() => {
    const handleClick = () => setSearchActiveIndex(null);
    document.addEventListener('click', handleClick);
    return () => document.removeEventListener('click', handleClick);
  }, []);

  const handleSearchChange = (index, value) => {
    setSearchQueries(prev => ({ ...prev, [index]: value }));
    if (searchTimer.current) clearTimeout(searchTimer.current);
    if (value.length < 2) {
      setSearchMatches(prev => ({ ...prev, [index]: [] }));
      return;
    }
    searchTimer.current = setTimeout(async () => {
      try {
        const res = await formateursApi.searchEtudiants(value);
        const data = res.data.data || [];
        if (data.length === 1) {
          selectStudent(index, data[0]);
        } else {
          setSearchMatches(prev => ({ ...prev, [index]: data }));
        }
      } catch {
        setSearchMatches(prev => ({ ...prev, [index]: [] }));
      }
    }, 300);
  };

  const selectStudent = (index, student) => {
    setResults(prev => prev.map((r, i) =>
      i === index ? {
        ...r,
        etudiant_id: student.id,
        nom: student.nom_prenom,
        nom_ar: student.nom_ar || '',
        numero_inscription: student.numero_inscription,
        groupe: student.groupe?.nom || '',
        found: true
      } : r
    ));
    setSearchQueries(prev => { const n = { ...prev }; delete n[index]; return n; });
    setSearchMatches(prev => { const n = { ...prev }; delete n[index]; return n; });
    setSearchActiveIndex(null);
  };

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
      pdfUrls.forEach(url => URL.revokeObjectURL(url));
    };
  }, []);

  const filteredSequences = sel.filiere_id
    ? sequences.filter(s => s.unite?.filiere_id == sel.filiere_id)
    : sequences;

  const filteredControles = sel.sequence_id
    ? (sequences.find(s => s.id == sel.sequence_id)?.controles || [])
    : [];

  const handleFiles = (e) => {
    const files = Array.from(e.target.files);
    setPdfs(files);
    const urls = files.map(f => URL.createObjectURL(f));
    setPdfUrls(urls);
  };

  const handleScan = async () => {
    if (pdfs.length === 0) {
      toast.warning('Veuillez sélectionner au moins un fichier PDF');
      return;
    }
    if (!sel.controle_id) {
      toast.warning('Veuillez sélectionner un contrôle');
      return;
    }

    setScanning(true);
    setResults([]);

    try {
      const formData = new FormData();
      pdfs.forEach(pdf => formData.append('pdfs[]', pdf));
      formData.append('controle_id', sel.controle_id);

      const res = await scanApi.scan(formData);
      const data = res.data;

      if (!data.success) {
        toast.error(data.message || 'Erreur lors du scan');
        setScanning(false);
        return;
      }

      const scanned = (data.data?.resultats || []).map(r => ({
        nom: r.nom_prenom || '',
        nom_ar: r.nom_ar || '',
        numero_inscription: r.numero_inscription || '',
        groupe: r.groupe || '',
        note: r.note,
        etudiant_id: r.etudiant_id || null,
        found: r.found || false,
        already_confirmed: r.already_confirmed || false,
        confidence: r.found ? 'high' : 'low'
      }));

      const unconfirmedResults = scanned.filter(r => !r.already_confirmed);

      if (scanned.length === 0) {
        setAllConfirmed(false);
        setResults([]);
        toast.error('Aucun résultat trouvé. Vérifiez que le fichier PDF est lisible.');
      } else if (unconfirmedResults.length === 0) {
        setAllConfirmed(true);
        setResults([]);
        toast.info('Toutes les notes pour ce contrôle ont déjà été confirmées. Modifiez-les depuis la page Mes Notes ou choisissez un autre contrôle.');
      } else {
        setAllConfirmed(false);
        const confirmedCount = scanned.length - unconfirmedResults.length;
        const unrecCount = unconfirmedResults.filter(r => !r.found).length;
        let msg = `${unconfirmedResults.length} note(s) détectée(s)`;
        if (confirmedCount > 0) msg += ` (${confirmedCount} déjà confirmée(s) ignorée(s))`;
        if (unrecCount > 0) msg += `, ${unrecCount} non reconnue(s)`;
        showSuccess(toast, msg);
        setResults(unconfirmedResults);
      }

      setStep(3);
    } catch (err) {
      handleApiError(err, toast);
      setResults([]);
    } finally {
      setScanning(false);
    }
  };

  const removePdf = (i) => {
    URL.revokeObjectURL(pdfUrls[i]);
    setPdfs(prev => prev.filter((_, idx) => idx !== i));
    setPdfUrls(prev => prev.filter((_, idx) => idx !== i));
  };

  const updateResult = (i, key, val) => {
    setResults(prev => prev.map((r, idx) => idx === i ? { ...r, [key]: val } : r));
  };

  const removeResult = (i) => {
    setResults(prev => prev.filter((_, idx) => idx !== i));
  };

  const handleNextFromResults = () => {
    const unrec = results.filter(r => !r.etudiant_id);
    if (unrec.length > 0) {
      toast.warning(`${unrec.length} étudiant(s) non reconnu(s). Retirez-les de la liste avant de continuer.`);
      return;
    }
    setStep(4);
  };

  const handleConfirm = async () => {
    if (results.length === 0 || !sel.controle_id) return;

    const missing = results.filter(r => !r.etudiant_id);
    if (missing.length > 0) {
      toast.warning(`${missing.length} étudiant(s) non reconnu(s) dans la base. Retirez-les de la liste.`);
      return;
    }

    const alreadyConfirmed = results.filter(r => r.already_confirmed);
    if (alreadyConfirmed.length > 0) {
      const names = alreadyConfirmed.map(r => r.nom).join(', ');
      toast.warning(`${alreadyConfirmed.length} étudiant(s) ont déjà une note confirmée : ${names}. Contactez l'administrateur si vous devez modifier ces notes.`);
      return;
    }

    setSavingResults(true);
    try {
      const notes = results.map(r => ({
        etudiant_id: r.etudiant_id,
        controle_id: parseInt(sel.controle_id),
        valeur: r.note,
      }));

      const res = await scanApi.confirm({ notes });
      const data = res.data;

      let msg = data.message || `${notes.length} note(s) enregistrée(s)`;
      if (data.already_confirmed?.length > 0) {
        const names = data.already_confirmed.map(r => r.nom_prenom).join(', ');
        msg += ` — ${data.already_confirmed.length} ignorée(s) (déjà confirmée(s)) : ${names}. Contactez l'admin pour les modifier.`;
        toast.warning(msg);
      } else {
        showSuccess(toast, msg);
      }
      setStep(1);
      setPdfs([]);
      setResults([]);
    } catch (err) {
      const resp = err.response?.data;
      if (resp?.already_confirmed?.length > 0) {
        const names = resp.already_confirmed.map(r => r.nom_prenom).join(', ');
        toast.warning(`Impossible d'enregistrer : ${names} — notes déjà confirmées. Contactez l'administrateur.`);
      } else {
        handleApiError(err, toast);
      }
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
      <div className="step-mobile">Étape {step} sur 5 : {['Sélection', 'PDF', 'Résultats', 'Vérification', 'Confirmer'][step - 1]}</div>
      <div className="steps" style={{ marginBottom: 28 }}>
        {['Sélection', 'PDF', 'Résultats', 'Vérification', 'Confirmer'].map((label, i) => (
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
              <span className="step-label" style={{ fontSize: 13, color: step === i + 1 ? 'var(--primary)' : 'var(--gray-400)' }}>
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
            <input type="file" accept=".pdf" multiple onChange={handleFiles} />
            <div className="upload-zone-text">
              <strong>Cliquer ou glisser</strong> les fichiers PDF de notes
            </div>
          </div>
          {pdfs.length > 0 && (
            <div style={{ marginTop: 20 }}>
              <div style={{ fontSize: 14, fontWeight: 600, marginBottom: 8 }}>{pdfs.length} fichier(s) PDF sélectionné(s)</div>
              <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fill, minmax(180px, 1fr))', gap: 12 }}>
                {pdfUrls.map((src, i) => (
                  <div key={i} style={{ position: 'relative', borderRadius: 8, overflow: 'hidden', border: '1px solid #e5e7eb', background: '#f9fafb', height: 200, display: 'flex', alignItems: 'center', justifyContent: 'center' }}>
                    <embed src={src} type="application/pdf" style={{ width: '100%', height: '100%' }} />
                    <div style={{ position: 'absolute', top: 6, right: 6, background: 'rgba(239,68,68,0.92)', color: '#fff', border: 'none', borderRadius: 6, width: 30, height: 30, display: 'flex', alignItems: 'center', justifyContent: 'center', cursor: 'pointer', boxShadow: '0 2px 6px rgba(0,0,0,0.3)' }} onClick={() => removePdf(i)}>
                        <FiX size={18} aria-label="Retirer ce fichier" />
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
            <button className="btn btn-primary" disabled={pdfs.length === 0 || scanning} onClick={handleScan}>
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
            Vérifiez les résultats avant de confirmer. Seule la note est modifiable.
          </div>
          {results.length === 0 ? (
            <div className="text-center" style={{ padding: 40 }}>
              {allConfirmed ? (
                <>
                  <h4 style={{ color: 'var(--warning)', marginBottom: 12 }}>Toutes les notes existent deja</h4>
                  <p style={{ color: '#999', marginBottom: 24 }}>
                    Tous les etudiants scannes ont deja des notes confirmees pour ce controle.
                    Modifiez-les depuis la page <strong>Mes Notes</strong> ou selectionnez un autre controle.
                  </p>
                  <button className="btn btn-primary" onClick={() => { setStep(1); setAllConfirmed(false); }}>
                    Choisir un autre controle
                  </button>
                </>
              ) : (
                <>
                  <h4 style={{ color: '#666', marginBottom: 12 }}>Aucune note detectee</h4>
                  <p style={{ color: '#999', marginBottom: 24 }}>
                    Verifiez le fichier PDF ou ressayer le scan.
                  </p>
                  <div style={{ display: 'flex', gap: 12, justifyContent: 'center' }}>
                    <button className="btn btn-primary" onClick={() => setStep(2)}>
                      Retour aux fichiers
                    </button>
                    <button className="btn btn-outline" onClick={handleScan}>
                      Ressayer le scan
                    </button>
                  </div>
                </>
              )}
            </div>
          ) : (
            <div style={{ display: 'grid', gridTemplateColumns: '320px 1fr', gap: 20 }}>
              <div>
                <h5 style={{ marginBottom: 8, fontWeight: 600 }}>Fichiers PDF uploadés</h5>
                <div style={{ display: 'flex', flexDirection: 'column', gap: 8, maxHeight: '70vh', overflowY: 'auto' }}>
                  {pdfUrls.map((src, i) => (
                    <div key={i} style={{ position: 'relative', borderRadius: 8, overflow: 'hidden', border: '1px solid #e5e7eb', background: '#fff' }}>
                      <embed src={src} type="application/pdf" style={{ width: '100%', height: 220, display: 'block' }} />
                      <div style={{ position: 'absolute', top: 4, left: 4, background: 'var(--primary)', color: '#fff', borderRadius: '50%', width: 24, height: 24, display: 'flex', alignItems: 'center', justifyContent: 'center', fontSize: 11, fontWeight: 700 }}>
                        {i + 1}
                      </div>
                      <button
                        onClick={() => removePdf(i)}
                        style={{ position: 'absolute', top: 4, right: 4, background: 'rgba(239,68,68,0.9)', color: '#fff', border: 'none', borderRadius: 4, width: 28, height: 28, display: 'flex', alignItems: 'center', justifyContent: 'center', cursor: 'pointer', fontSize: 16 }}
                        title="Retirer ce fichier"
                      >
                        <FiX />
                      </button>
                    </div>
                  ))}
                </div>
              </div>
              <div>
                <div style={{ overflowX: 'auto', marginBottom: 16, border: '1px solid #e5e7eb', borderRadius: 8 }}>
                  <table style={{ fontSize: '15px', minWidth: '700px', width: '100%' }}>
                    <thead>
                      <tr style={{ backgroundColor: '#f9fafb' }}>
                        <th style={{ padding: '12px 16px', textAlign: 'left', fontWeight: 600, minWidth: '200px' }}>Nom</th>
                        <th style={{ padding: '12px 16px', textAlign: 'left', fontWeight: 600, minWidth: '120px' }}>N Inscription</th>
                        <th style={{ padding: '12px 16px', textAlign: 'left', fontWeight: 600, minWidth: '100px' }}>Groupe</th>
                        <th style={{ padding: '12px 16px', textAlign: 'left', fontWeight: 600, minWidth: '100px' }}>Note /20</th>
                        <th style={{ padding: '12px 16px', textAlign: 'left', fontWeight: 600, minWidth: '140px' }}>Statut</th>
                        <th style={{ padding: '12px 16px', textAlign: 'left', fontWeight: 600, minWidth: '100px' }}>Actions</th>
                      </tr>
                    </thead>
                    <tbody>
                      {results.map((r, i) => (
                        <tr key={i} style={{ borderBottom: '1px solid #e5e7eb' }}>
                          <td style={{ padding: '12px 16px' }}>
                            {!r.etudiant_id && !r.already_confirmed ? (
                              <input
                                className="form-input"
                                style={{ width: '100%', padding: '6px 10px', fontSize: '14px' }}
                                value={searchQueries[i] ?? r.nom}
                                onChange={e => handleSearchChange(i, e.target.value)}
                                placeholder="Rechercher un étudiant..."
                                onClick={e => e.stopPropagation()}
                                onFocus={e => {
                                  const rect = e.target.getBoundingClientRect();
                                  setSearchDropdownStyle({ top: rect.bottom + 4, left: rect.left, width: rect.width });
                                  setSearchActiveIndex(i);
                                }}
                              />
                            ) : (
                              <span style={{ padding: '8px 12px', display: 'block' }}>{r.nom}</span>
                            )}
                          </td>
                          <td style={{ padding: '12px 16px' }}>
                            <span style={{ padding: '8px 12px', display: 'block' }}>{r.numero_inscription}</span>
                          </td>
                          <td style={{ padding: '12px 16px' }}>
                            <span style={{ padding: '8px 12px', display: 'block' }}>{r.groupe}</span>
                          </td>
                          <td style={{ padding: '12px 16px' }}>
                            {r.already_confirmed || !r.etudiant_id ? (
                              <span style={{ padding: '8px 12px', display: 'block', color: '#999' }}>{r.note}</span>
                            ) : (
                              <input
                                type="number"
                                className="form-input"
                                style={{ width: 70, padding: '8px 12px', fontSize: '15px' }}
                                value={r.note}
                                min={0}
                                max={20}
                                onChange={e => updateResult(i, 'note', Number(e.target.value))}
                              />
                            )}
                          </td>
                          <td style={{ padding: '12px 16px' }}>
                            {r.already_confirmed ? (
                              <span style={{ color: 'var(--warning)', fontWeight: 600, fontSize: 13 }}>🔒 Déjà confirmé</span>
                            ) : r.etudiant_id ? (
                              <span style={{ color: 'var(--success)', fontWeight: 600, fontSize: 13 }}>✓ Reconnu</span>
                            ) : (
                              <span style={{ color: 'var(--danger)', fontWeight: 600, fontSize: 13 }}>✗ Introuvable</span>
                            )}
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
                  <button className="btn btn-primary" onClick={handleNextFromResults}>Suivant →</button>
                </div>
              </div>
            </div>
          )}

          {searchActiveIndex !== null && searchMatches[searchActiveIndex]?.length > 0 && (
            <div
              style={{
                position: 'fixed',
                top: searchDropdownStyle.top,
                left: searchDropdownStyle.left,
                width: searchDropdownStyle.width,
                background: '#fff',
                border: '1px solid #e5e7eb',
                borderRadius: 6,
                zIndex: 9999,
                maxHeight: 200,
                overflowY: 'auto',
                boxShadow: '0 4px 12px rgba(0,0,0,0.12)'
              }}
              onClick={e => e.stopPropagation()}
            >
              {searchMatches[searchActiveIndex].map(s => (
                <div
                  key={s.id}
                  style={{ padding: '8px 10px', cursor: 'pointer', borderBottom: '1px solid #f3f4f6', fontSize: 13 }}
                  onClick={() => selectStudent(searchActiveIndex, s)}
                  onMouseEnter={e => e.currentTarget.style.background = '#f9fafb'}
                  onMouseLeave={e => e.currentTarget.style.background = 'transparent'}
                >
                  <div style={{ fontWeight: 500 }}>{s.nom_prenom}</div>
                  <div style={{ fontSize: 11, color: '#999' }}>
                    {s.numero_inscription || ''}{s.groupe?.nom ? ` — ${s.groupe.nom}` : ''}
                  </div>
                </div>
              ))}
            </div>
          )}
        </div>
      )}

      {/* Step 4: Review */}
      {step === 4 && (
        <div>
          {results.some(r => !r.etudiant_id) && (
            <div className="alert alert-danger" style={{ marginBottom: 16 }}>
              <strong>Étudiants introuvables :</strong> certains étudiants ne sont pas dans la base de données.
              Retirez-les de la liste.
            </div>
          )}
          {results.some(r => r.already_confirmed) && (
            <div className="alert alert-danger" style={{ marginBottom: 16 }}>
              <strong>Notes déjà confirmées :</strong> certains étudiants ont déjà une note confirmée.
              Contactez l'administrateur pour les modifier.
            </div>
          )}
          <div className="alert alert-warning" style={{ marginBottom: 16 }}>
            <strong>Confirmez pour enregistrer les notes</strong> — toute modification sera impossible après.
          </div>
          <div style={{ display: 'flex', gap: 8 }}>
            <button className="btn btn-outline" onClick={() => setStep(3)}>← Retour</button>
            <button className="btn btn-primary" disabled={savingResults || results.some(r => !r.etudiant_id || r.already_confirmed)} onClick={handleConfirm}>
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
