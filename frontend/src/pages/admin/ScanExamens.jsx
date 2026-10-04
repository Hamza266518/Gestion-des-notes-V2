import { useEffect, useState, useRef } from 'react';
import { FiX } from 'react-icons/fi';
import { adminApi } from '../../api/admin';
import { scanApi } from '../../api/scan';
import { useToast } from '../../context/ToastContext';
import { useAnneeAcademique } from '../../context/AnneeAcademiqueContext';
import Spinner from '../../components/common/Spinner';
import { handleApiError, showSuccess } from '../../utils/errorHandler';
import { MESSAGE_SEMESTRE_PUBLIE } from '../../utils/helpers';
import '../../css/components.css';
import '../../css/layout.css';

export default function ScanExamens() {
  const toast = useToast();
  const { currentAnnee } = useAnneeAcademique();

  const [step, setStep] = useState(1);
  const [filieres, setFilieres] = useState([]);
  const [unites, setUnites] = useState([]);
  const [selFiliere, setSelFiliere] = useState('');
  const [selUnite, setSelUnite] = useState('');
  const [selType, setSelType] = useState('');

  const [pdfs, setPdfs] = useState([]);
  const [pdfUrls, setPdfUrls] = useState([]);
  const [results, setResults] = useState([]);
  const [scanning, setScanning] = useState(false);
  const [saving, setSaving] = useState(false);
  const [searchQueries, setSearchQueries] = useState([]);
  const [searchMatches, setSearchMatches] = useState([]);
  const [searchLoading, setSearchLoading] = useState(false);
  const [dropdownIndex, setDropdownIndex] = useState(null);
  const searchTimers = useRef({});
  const searchRefs = useRef([]);
  const loading = scanning || saving;

  const selectedUnite = unites.find(u => u.id == selUnite);
  const semestre = selectedUnite?.semestre || '';
  const bloc = semestre;

  useEffect(() => {
    adminApi.getFilieres().then(r => setFilieres(r.data.data)).catch(() => {});
    return () => { pdfUrls.forEach(u => URL.revokeObjectURL(u)); };
  }, []);

  useEffect(() => {
    if (selFiliere) {
      adminApi.getUnites({ filiere_id: selFiliere })
        .then(r => setUnites(r.data.data))
        .catch(() => setUnites([]));
    } else {
      setUnites([]);
    }
    setSelUnite('');
  }, [selFiliere]);

  const handleFiles = (e) => {
    const files = Array.from(e.target.files);
    setPdfs(files);
    setPdfUrls(prev => { prev.forEach(u => URL.revokeObjectURL(u)); return files.map(f => URL.createObjectURL(f)); });
  };

  const removePdf = (i) => {
    URL.revokeObjectURL(pdfUrls[i]);
    setPdfs(prev => prev.filter((_, idx) => idx !== i));
    setPdfUrls(prev => prev.filter((_, idx) => idx !== i));
  };

  useEffect(() => {
    const handler = (e) => {
      if (!searchRefs.current.some(el => el?.contains(e.target))) setDropdownIndex(null);
    };
    document.addEventListener('mousedown', handler);
    return () => document.removeEventListener('mousedown', handler);
  }, []);

  const handleScan = async () => {
    if (pdfs.length === 0 || !selUnite || !selType || !currentAnnee?.id) {
      toast.warning('Veuillez remplir tous les champs et ajouter des PDFs');
      return;
    }
    setScanning(true);
    setResults([]);
    setSearchQueries([]);
    setSearchMatches([]);
    setDropdownIndex(null);
    try {
      const fd = new FormData();
      pdfs.forEach(p => fd.append('pdfs[]', p));
      fd.append('unite_id', selUnite);
      fd.append('bloc', bloc);
      fd.append('type', selType);
      fd.append('semestre', semestre);
      fd.append('annee_academique_id', currentAnnee.id);
      fd.append('filiere_id', selFiliere);

      const res = await scanApi.scanExamens(fd);
      const data = res.data;
      if (!data.success) {
        toast.error(data.message || 'Erreur lors du scan');
        setScanning(false);
        return;
      }

      const scanned = (data.data?.resultats || []).map(r => ({
        code: r.code || '',
        nom_prenom: r.nom_prenom || '',
        cin: r.cin || '',
        note: r.note ?? '',
        etudiant_id: r.etudiant_id || null,
        numero_inscription: r.numero_inscription || '',
        groupe: r.groupe || '',
        groupe_id: r.groupe_id || null,
        verrouille: r.verrouille || false,
        found: r.found || false,
      }));

      if (scanned.length === 0) {
        toast.error('Aucun résultat trouvé. Vérifiez les PDFs.');
      } else {
        const unrec = scanned.filter(r => !r.found).length;
        let msg = `${scanned.length} note(s) détectée(s)`;
        if (unrec > 0) msg += `, ${unrec} non reconnue(s)`;
        const locked = scanned.filter(r => r.verrouille).length;
        if (locked > 0) msg += `, ${locked} groupe(s) publié(s)`;
        showSuccess(toast, msg);
        setResults(scanned);
        setStep(3);
      }
    } catch (err) {
      handleApiError(err, toast);
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

  const handleSearchChange = (i, value) => {
    setSearchQueries(prev => { const n = [...prev]; n[i] = value; return n; });
    setDropdownIndex(i);
    if (searchTimers.current[i]) clearTimeout(searchTimers.current[i]);
    if (value.length < 2) { setSearchMatches(prev => { const n = [...prev]; n[i] = []; return n; }); return; }
    searchTimers.current[i] = setTimeout(async () => {
      try {
        setSearchLoading(true);
        const res = await scanApi.searchScanExamensEtudiants({
          search: value,
          annee_academique_id: currentAnnee.id,
          filiere_id: selFiliere,
          niveau_numero: selectedUnite?.numero_annee,
          semestre: semestre || undefined,
        });
        const data = res.data.data || [];
        setSearchMatches(prev => { const n = [...prev]; n[i] = data; return n; });
        if (data.length === 1) selectStudent(i, data[0]);
      } catch { /* ignore */ } finally { setSearchLoading(false); }
    }, 300);
  };

  const selectStudent = (i, student) => {
    setResults(prev => prev.map((r, idx) => idx === i ? {
      ...r,
      etudiant_id: student.id,
      groupe_id: student.groupe_id ?? r.groupe_id,
      verrouille: student.verrouille ?? r.verrouille,
      nom_prenom: student.nom_prenom,
      cin: student.cin || r.cin,
      numero_inscription: student.numero_inscription || '',
      groupe: student.groupe || '',
      found: true,
    } : r));
    setSearchQueries(prev => { const n = [...prev]; n[i] = ''; return n; });
    setSearchMatches(prev => { const n = [...prev]; n[i] = []; return n; });
    setDropdownIndex(null);
  };

  const resultatsVerrouilles = results.filter(r => r.etudiant_id && r.verrouille);

  const handleConfirm = async () => {
    const missing = results.filter(r => !r.etudiant_id);
    if (missing.length > 0) {
      toast.warning(`${missing.length} étudiant(s) non reconnu(s). Retirez-les ou recherchez-les manuellement.`);
      return;
    }
    // Checked here as well as on the server: the button being clickable and
    // then refused is worse than saying so before the request is sent.
    if (resultatsVerrouilles.length > 0) {
      toast.warning(MESSAGE_SEMESTRE_PUBLIE);
      return;
    }
    setSaving(true);
    try {
      const res = await scanApi.confirmScanExamens({
        resultats: results.map(r => ({ etudiant_id: r.etudiant_id, note: r.note })),
        unite_id: selUnite,
        bloc: bloc,
        type: selType,
        semestre: semestre,
        annee_academique_id: currentAnnee.id,
      });
      showSuccess(toast, res.data.message || 'Notes enregistrées');
      setStep(1);
      setPdfs([]);
      setResults([]);
      setSearchQueries([]);
      setSearchMatches([]);
      setDropdownIndex(null);
    } catch (err) {
      handleApiError(err, toast);
    } finally {
      setSaving(false);
    }
  };

  return (
    <div className="page">
      <div className="page-header">
        <h2 className="page-title">Scan Examens</h2>
      </div>

      <div className="steps" style={{ marginBottom: 28 }}>
        {['Sélection', 'PDF', 'Résultats', 'Confirmer'].map((label, i) => (
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
              <span style={{ fontSize: 13, color: step === i + 1 ? 'var(--primary)' : 'var(--gray-400)', fontWeight: step === i + 1 ? 500 : 400 }}>
                {label}
              </span>
            </div>
            {i < 3 && <div style={{ flex: 1, height: 2, background: 'var(--gray-200)', margin: '0 12px' }} />}
          </div>
        ))}
      </div>

      {step === 1 && (
        <div className="card card-body" style={{ maxWidth: 600 }}>
          <div className="form-group">
            <label className="form-label">Filière</label>
            <select className="form-select" value={selFiliere} onChange={e => { setSelFiliere(e.target.value); setSelUnite(''); }}>
              <option value="">Sélectionner</option>
              {filieres.map(f => <option key={f.id} value={f.id}>{f.nom}</option>)}
            </select>
          </div>
          <div className="form-group">
            <label className="form-label">Unité</label>
            <select className="form-select" value={selUnite} onChange={e => setSelUnite(e.target.value)} disabled={!selFiliere}>
              <option value="">Sélectionner</option>
              {unites.map(u => <option key={u.id} value={u.id}>{u.nom} (S{u.semestre})</option>)}
            </select>
          </div>
          <div className="form-group">
            <label className="form-label">Type d'examen</label>
            <select className="form-select" value={selType} onChange={e => setSelType(e.target.value)}>
              <option value="">Sélectionner</option>
              <option value="theorique">Théorique</option>
              <option value="pratique">Pratique</option>
            </select>
          </div>
          {bloc && <p style={{margin:'8px 0', fontSize:13, color:'#666'}}>Session (Bloc) : {bloc} (détecté depuis l'unité)</p>}
          <button className="btn btn-primary" disabled={!selUnite || !selType} onClick={() => setStep(2)}>
            Suivant →
          </button>
        </div>
      )}

      {step === 2 && (
        <div className="card card-body" style={{ maxWidth: 1000, width: '90vw' }}>
          <div style={{ marginBottom: 16, padding: '14px 18px', borderRadius: 10, background: '#eff6ff', border: '1px solid #bfdbfe', fontSize: 13, lineHeight: 1.7 }}>
            <div style={{ fontWeight: 700, color: '#1d4ed8', marginBottom: 6 }}>Important</div>
            <div style={{ display: 'flex', flexDirection: 'column', gap: 4, color: '#1e3a5f' }}>
              <span>1. Uploadez la <strong>feuille de correspondance</strong> (codes → étudiants)</span>
              <span>2. Uploadez les <strong>copies corrigées</strong> (codes → notes)</span>
            </div>
            <div style={{ marginTop: 6, padding: '6px 10px', background: '#dbeafe', borderRadius: 6, fontSize: 12, color: '#1e40af' }}>
              Les deux fichiers ensemble dans le même upload. L'IA fera le lien automatiquement.
            </div>
          </div>
          <div className="upload-zone">
            <input type="file" accept=".pdf" multiple onChange={handleFiles} disabled={loading} />
            <div className="upload-zone-text">
              <strong>Cliquer ou glisser</strong> les fichiers PDF (correspondance + copies)
            </div>
          </div>
          {pdfs.length > 0 && (
            <div style={{ marginTop: 20 }}>
              <div style={{ fontSize: 14, fontWeight: 600, marginBottom: 8 }}>{pdfs.length} fichier(s) PDF sélectionné(s)</div>
              <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fill, minmax(180px, 1fr))', gap: 12 }}>
                {pdfUrls.map((src, i) => (
                  <div key={i} style={{ position: 'relative', borderRadius: 8, overflow: 'hidden', border: '1px solid #e5e7eb', background: '#f9fafb', height: 200, display: 'flex', alignItems: 'center', justifyContent: 'center' }}>
                    <embed src={src} type="application/pdf" style={{ width: '100%', height: '100%' }} />
                    <div style={{ position: 'absolute', top: 6, right: 6, background: 'rgba(239,68,68,0.92)', color: '#fff', border: 'none', borderRadius: 6, width: 30, height: 30, display: 'flex', alignItems: 'center', justifyContent: 'center', cursor: loading ? 'not-allowed' : 'pointer', boxShadow: '0 2px 6px rgba(0,0,0,0.3)', opacity: loading ? 0.5 : 1 }} onClick={() => !loading && removePdf(i)}>
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
            <button className="btn btn-outline" onClick={() => setStep(1)} disabled={loading}>← Retour</button>
            <button className="btn btn-primary" disabled={loading || pdfs.length === 0} onClick={handleScan}>
              {scanning ? 'Lecture en cours...' : 'Scanner'}
            </button>
          </div>
          {scanning && (
            <div style={{ textAlign: 'center', padding: 20, marginTop: 16 }}>
              <Spinner />
              <p style={{ marginTop: 8, color: '#666' }}>Analyse des PDFs en cours...</p>
            </div>
          )}
        </div>
      )}

      {step === 3 && (
        <div style={{ maxWidth: 1200, width: '95vw', margin: '0 auto' }}>
          <div className="alert alert-info" style={{ marginBottom: 16 }}>
            Vérifiez les résultats. La note est modifiable. Pour les étudiants non reconnus, tapez le nom pour les retrouver.
          </div>

          {results.length === 0 ? (
            <div className="text-center" style={{ padding: 40 }}>
              <h4 style={{ color: '#666', marginBottom: 12 }}>Aucune note détectée</h4>
              <p style={{ color: '#999', marginBottom: 24 }}>Vérifiez les PDFs ou réessayez le scan.</p>
              <div style={{ display: 'flex', gap: 12, justifyContent: 'center' }}>
                <button className="btn btn-primary" onClick={() => setStep(2)} disabled={loading}>Retour aux fichiers</button>
                <button className="btn btn-outline" onClick={handleScan} disabled={loading}>
                  {scanning ? 'Scan en cours...' : 'Réessayer'}
                </button>
              </div>
            </div>
          ) : (
            <div>
              <div style={{ overflowX: 'auto', marginBottom: 16, border: '1px solid #e5e7eb', borderRadius: 8 }}>
                <table style={{ fontSize: '15px', minWidth: '700px', width: '100%' }}>
                  <thead>
                    <tr style={{ backgroundColor: '#f9fafb' }}>
                      <th style={{ padding: '12px 16px', fontWeight: 600, minWidth: '70px' }}>Code</th>
                      <th style={{ padding: '12px 16px', fontWeight: 600, minWidth: '220px' }}>Nom et Prénom</th>
                      <th style={{ padding: '12px 16px', fontWeight: 600, minWidth: '110px' }}>CIN</th>
                      <th style={{ padding: '12px 16px', fontWeight: 600, minWidth: '130px' }}>N° Inscription</th>
                      <th style={{ padding: '12px 16px', fontWeight: 600, minWidth: '90px' }}>Note /20</th>
                      <th style={{ padding: '12px 16px', fontWeight: 600, minWidth: '110px' }}>Statut</th>
                      <th style={{ padding: '12px 16px', fontWeight: 600, minWidth: '80px' }}>Actions</th>
                    </tr>
                  </thead>
                  <tbody>
                    {results.map((r, i) => (
                      <tr key={i} style={{ borderBottom: '1px solid #e5e7eb' }}>
                        <td style={{ padding: '12px 16px' }}>{r.code}</td>
                        <td style={{ padding: '12px 16px', position: 'relative' }} ref={el => searchRefs.current[i] = el}>
                          {r.found ? (
                            <span>{r.nom_prenom}</span>
                          ) : (
                            <div>
                              <input className="form-input" style={{ width: '100%', minWidth: 200, padding: '6px 10px', fontSize: 14 }}
                                value={searchQueries[i] ?? r.nom_prenom}
                                onChange={e => handleSearchChange(i, e.target.value)}
                                onFocus={() => setDropdownIndex(i)}
                                placeholder="Rechercher un étudiant..." />
                              {dropdownIndex === i && searchMatches[i]?.length > 0 && (
                                <div style={{ position: 'absolute', top: '100%', left: 0, right: 0, zIndex: 100, background: '#fff', border: '1px solid #d1d5db', borderRadius: 6, boxShadow: '0 4px 12px rgba(0,0,0,0.15)', maxHeight: 220, overflowY: 'auto' }}>
                                  {searchMatches[i].map(s => (
                                    <div key={s.id} style={{ padding: '8px 12px', cursor: 'pointer', borderBottom: '1px solid #f3f4f6', fontSize: 14 }}
                                      onClick={() => selectStudent(i, s)}
                                      onMouseDown={e => e.preventDefault()}>
                                      <div style={{ fontWeight: 500 }}>{s.nom_prenom}</div>
                                      <div style={{ fontSize: 12, color: '#999' }}>{s.numero_inscription} — {s.groupe}</div>
                                    </div>
                                  ))}
                                </div>
                              )}
                            </div>
                          )}
                        </td>
                        <td style={{ padding: '12px 16px' }}>{r.cin}</td>
                        <td style={{ padding: '12px 16px' }}>{r.numero_inscription}</td>
                        <td style={{ padding: '12px 16px' }}>
                          {r.verrouille ? (
                            <span style={{ color: '#b91c1c', fontWeight: 600 }} title={MESSAGE_SEMESTRE_PUBLIE}>
                              {r.note ?? '—'}
                            </span>
                          ) : r.found ? (
                            <input type="number" className="form-input" style={{ width: 70, padding: '8px 12px', fontSize: '15px' }}
                              value={r.note} min={0} max={20} step={0.5}
                              onChange={e => updateResult(i, 'note', e.target.value)} />
                          ) : (
                            <span style={{ color: '#999' }}>{r.note}</span>
                          )}
                        </td>
                        <td style={{ padding: '12px 16px' }}>
                          {r.verrouille ? (
                            <span style={{ color: '#b45309', fontWeight: 600, fontSize: 13 }} title={MESSAGE_SEMESTRE_PUBLIE}>
                              Publié
                            </span>
                          ) : r.found ? (
                            <span style={{ color: 'var(--success)', fontWeight: 600, fontSize: 13 }}>✓ Reconnu</span>
                          ) : (
                            <span style={{ color: 'var(--danger)', fontWeight: 600, fontSize: 13 }}>✗ Introuvable</span>
                          )}
                        </td>
                        <td style={{ padding: '12px 16px' }}>
                          <button className="btn btn-sm btn-danger" onClick={() => removeResult(i)} disabled={loading}>Retirer</button>
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
              <div style={{ display: 'flex', gap: 8 }}>
                <button className="btn btn-outline" onClick={() => setStep(2)} disabled={loading}>← Retour</button>
                <button className="btn btn-primary" onClick={() => {
                  const unrec = results.filter(r => !r.etudiant_id);
                  if (unrec.length > 0) {
                    toast.warning(`${unrec.length} étudiant(s) non reconnu(s). Recherchez-les ou retirez-les.`);
                    return;
                  }
                  setStep(4);
                }} disabled={loading}>Suivant →</button>
              </div>
            </div>
          )}
        </div>
      )}

      {step === 4 && (
        <div className="card card-body" style={{ maxWidth: 600 }}>
          <div className="alert alert-warning" style={{ marginBottom: 16 }}>
            <strong>Confirmez pour enregistrer les notes</strong> — cela enregistrera {results.length} note(s) d'examen pour l'unité sélectionnée.
          </div>
          {resultatsVerrouilles.length > 0 && (
            <div className="alert alert-danger" style={{ marginBottom: 16 }}>
              {MESSAGE_SEMESTRE_PUBLIE}
              <ul style={{ margin: '8px 0 0 18px', padding: 0 }}>
                {resultatsVerrouilles.map(r => (
                  <li key={r.etudiant_id}>{r.nom_prenom} — {r.groupe}</li>
                ))}
              </ul>
            </div>
          )}
          <div style={{ display: 'flex', gap: 8 }}>
            <button className="btn btn-outline" onClick={() => setStep(3)} disabled={loading}>← Retour</button>
            <button
              className="btn btn-primary"
              style={{ minWidth: 220, justifyContent: 'center' }}
              disabled={loading || resultatsVerrouilles.length > 0}
              title={resultatsVerrouilles.length > 0 ? MESSAGE_SEMESTRE_PUBLIE : undefined}
              onClick={handleConfirm}
            >
              {saving ? <><Spinner /> Enregistrement...</> : `Confirmer ${results.length} note(s)`}
            </button>
          </div>
        </div>
      )}
    </div>
  );
}
