import { useEffect, useMemo, useRef, useState } from 'react';
import { FiX } from 'react-icons/fi';
import { adminApi } from '../../api/admin';
import { soutenanceApi } from '../../api/soutenance';
import { useToast } from '../../context/ToastContext';
import { useAnneeAcademique } from '../../context/AnneeAcademiqueContext';
import Spinner from '../../components/common/Spinner';
import FilterSelect from '../../components/common/FilterSelect';
import { handleApiError, showSuccess } from '../../utils/errorHandler';
import { MESSAGE_SEMESTRE_PUBLIE, formatNiveau, invalidNoteHint } from '../../utils/helpers';
import '../../css/components.css';
import '../../css/layout.css';

const STEPS = ['Sélection', 'PDF', 'Résultats', 'Confirmer'];

/**
 * Soutenance (NSTI) marks come from the jury sheet only: one row per student,
 * one grade per criterion. This follows the same four steps as the exam
 * scanner. The filters are built from the programmes that actually run a
 * soutenance, so a year whose notes are calculated normally can never be
 * picked, and no grid appears before a scan.
 */
export default function Soutenance() {
  const toast = useToast();
  const { currentAnnee } = useAnneeAcademique();

  const [step, setStep] = useState(1);

  const [programmes, setProgrammes] = useState([]);
  const [selFiliere, setSelFiliere] = useState('');
  const [selAnnee, setSelAnnee] = useState('');
  const [selGroupe, setSelGroupe] = useState('');
  const [groupes, setGroupes] = useState([]);

  const [pdfs, setPdfs] = useState([]);
  const [pdfUrls, setPdfUrls] = useState([]);
  const [results, setResults] = useState([]);
  const [scanning, setScanning] = useState(false);
  const [saving, setSaving] = useState(false);

  const [searchQueries, setSearchQueries] = useState({});
  const [searchMatches, setSearchMatches] = useState({});
  const [searchActiveRowId, setSearchActiveRowId] = useState(null);
  const [searchDropdownStyle, setSearchDropdownStyle] = useState({});
  const searchTimers = useRef({});
  const liveRowIds = useRef(new Set());
  const rowIdSeq = useRef(0);
  const newRowId = () => `r${++rowIdSeq.current}`;

  const loading = scanning || saving;

  // Only the filières/années that run a soutenance are offered at all.
  const filieres = useMemo(() => {
    const seen = new Map();
    programmes.forEach(p => {
      if (!seen.has(p.filiere_id)) seen.set(p.filiere_id, { id: p.filiere_id, nom: p.filiere_nom });
    });
    return [...seen.values()];
  }, [programmes]);

  const annees = useMemo(() => {
    const nums = programmes
      .filter(p => String(p.filiere_id) === String(selFiliere))
      .map(p => Number(p.niveau_numero));
    return [...new Set(nums)].sort((a, b) => a - b);
  }, [programmes, selFiliere]);

  const selectedProgramme = useMemo(
    () => programmes.find(
      p => String(p.filiere_id) === String(selFiliere) && Number(p.niveau_numero) === Number(selAnnee)
    ) || null,
    [programmes, selFiliere, selAnnee]
  );

  const criteres = selectedProgramme?.criteres ?? [];
  const uniteId = selectedProgramme?.unite_id ?? '';
  const semestre = selectedProgramme?.unite_semestre ?? 2;

  useEffect(() => {
    soutenanceApi.programmes()
      .then(res => setProgrammes(res.data?.data || []))
      .catch(() => setProgrammes([]));
    return () => { pdfUrls.forEach(u => URL.revokeObjectURL(u)); };
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  // Changing the filière resets everything downstream; a filière with a single
  // soutenance year selects it straight away (that is the usual case).
  useEffect(() => {
    setSelAnnee('');
    setSelGroupe('');
    setResults([]);
    if (!selFiliere) return;
    const nums = [...new Set(
      programmes.filter(p => String(p.filiere_id) === String(selFiliere)).map(p => Number(p.niveau_numero))
    )];
    if (nums.length === 1) setSelAnnee(String(nums[0]));
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [selFiliere]);

  useEffect(() => {
    setGroupes([]);
    setSelGroupe('');
    if (!selectedProgramme || !currentAnnee?.id) return;
    adminApi.getGroupes({ niveau_id: selectedProgramme.niveau_id, annee_academique_id: currentAnnee.id })
      .then(res => {
        const data = res.data.data || [];
        setGroupes(data);
        if (data.length === 1) setSelGroupe(String(data[0].id));
      })
      .catch(() => setGroupes([]));
  }, [selectedProgramme, currentAnnee]);

  useEffect(() => {
    liveRowIds.current = new Set(results.map(r => r.rowId));
  }, [results]);

  useEffect(() => {
    const close = () => setSearchActiveRowId(null);
    document.addEventListener('click', close);
    return () => document.removeEventListener('click', close);
  }, []);

  const handleFiles = (e) => {
    const files = Array.from(e.target.files || []);
    setPdfs(files);
    setPdfUrls(prev => { prev.forEach(u => URL.revokeObjectURL(u)); return files.map(f => URL.createObjectURL(f)); });
  };

  const removePdf = (i) => {
    URL.revokeObjectURL(pdfUrls[i]);
    setPdfs(prev => prev.filter((_, idx) => idx !== i));
    setPdfUrls(prev => prev.filter((_, idx) => idx !== i));
  };

  const handleScan = async () => {
    if (pdfs.length === 0 || !uniteId || !selGroupe || !currentAnnee?.id) {
      toast.warning('Choisissez la liste (groupe) et ajoutez au moins un PDF.');
      return;
    }
    if (results.length > 0) {
      const ok = window.confirm(
        `Un nouveau scan remplace les ${results.length} ligne(s) déjà affichée(s), ` +
        'y compris les notes corrigées à la main. Continuer ?'
      );
      if (!ok) return;
    }

    setScanning(true);
    try {
      const fd = new FormData();
      pdfs.forEach(p => fd.append('pdfs[]', p));
      fd.append('unite_id', uniteId);
      fd.append('annee_academique_id', currentAnnee.id);
      fd.append('filiere_id', selFiliere);
      fd.append('groupe_id', selGroupe);

      const res = await soutenanceApi.scan(fd);
      const data = res.data;
      if (!data.success) {
        toast.error(data.message || 'Erreur lors du scan');
        return;
      }

      const rows = (data.data?.resultats || []).map(r => {
        const notesBrutes = Array.isArray(r.notes) ? r.notes : [];
        return {
          rowId: newRowId(),
          nom: r.nom_prenom || '',
          numero_inscription: r.numero_inscription || '',
          groupe: r.groupe || '',
          etudiant_id: r.etudiant_id || null,
          groupe_id: r.groupe_id || null,
          verrouille: r.verrouille || false,
          found: r.found || false,
          // One cell per criterion, in jury order. An absent criterion shows
          // "AB"; a misread keeps its raw text and stays flagged.
          notes: criteres.map((c, i) => {
            const cell = notesBrutes[i] || {};
            const absent = !!cell.est_absent;
            const invalid = !!cell.invalid;
            let valeur = '';
            if (absent) valeur = 'AB';
            else if (invalid) valeur = cell.raw ?? '';
            else if (cell.valeur != null) valeur = String(cell.valeur);
            return {
              controle_id: cell.controle_id ?? c.id,
              valeur,
              invalid: invalid && !absent,
              raw: cell.raw ?? null,
            };
          }),
        };
      });

      if (rows.length === 0) {
        toast.error('Aucun résultat. Vérifiez que les PDF sont lisibles.');
        return;
      }
      const unrec = rows.filter(r => !r.etudiant_id).length;
      showSuccess(toast, `${rows.length} étudiant(s) lu(s)${unrec > 0 ? `, ${unrec} non reconnu(s)` : ''}`);
      setResults(rows);
      setStep(3);
    } catch (err) {
      handleApiError(err, toast);
      setResults([]);
    } finally {
      setScanning(false);
    }
  };

  const updateCell = (rowId, index, text) => {
    setResults(prev => prev.map(r => {
      if (r.rowId !== rowId) return r;
      const notes = r.notes.map((c, i) => (i === index ? { ...c, valeur: text, invalid: false } : c));
      return { ...r, notes };
    }));
  };

  const removeResult = (rowId) => {
    setResults(prev => prev.filter(r => r.rowId !== rowId));
    setSearchQueries(prev => { const n = { ...prev }; delete n[rowId]; return n; });
    setSearchMatches(prev => { const n = { ...prev }; delete n[rowId]; return n; });
    if (searchTimers.current[rowId]) {
      clearTimeout(searchTimers.current[rowId]);
      delete searchTimers.current[rowId];
    }
  };

  const skipStudent = (row) => {
    removeResult(row.rowId);
    setSearchActiveRowId(cur => (cur === row.rowId ? null : cur));
    toast.info(`« ${row.nom} » ignoré.`);
  };

  const handleSearchChange = (rowId, value) => {
    setSearchQueries(prev => ({ ...prev, [rowId]: value }));
    if (searchTimers.current[rowId]) {
      clearTimeout(searchTimers.current[rowId]);
      delete searchTimers.current[rowId];
    }
    if (value.length < 2) {
      setSearchMatches(prev => ({ ...prev, [rowId]: [] }));
      return;
    }
    searchTimers.current[rowId] = setTimeout(async () => {
      delete searchTimers.current[rowId];
      try {
        // Suggestions stay inside the chosen groupe, so a hand-linked row can
        // never pull in a student from a sibling cohort.
        const res = await soutenanceApi.searchEtudiants({
          search: value,
          annee_academique_id: currentAnnee?.id,
          filiere_id: selFiliere,
          niveau_numero: selectedProgramme?.niveau_numero,
          semestre,
          groupe_id: selGroupe || undefined,
        });
        if (!liveRowIds.current.has(rowId)) return;
        setSearchMatches(prev => ({ ...prev, [rowId]: res.data.data || [] }));
      } catch {
        setSearchMatches(prev => ({ ...prev, [rowId]: [] }));
      }
    }, 300);
  };

  const selectStudent = (rowId, student) => {
    setResults(prev => prev.map(r => r.rowId === rowId ? {
      ...r,
      etudiant_id: student.id,
      groupe_id: student.groupe_id ?? r.groupe_id,
      verrouille: student.verrouille ?? r.verrouille,
      nom: student.nom_prenom,
      numero_inscription: student.numero_inscription || '',
      groupe: student.groupe || '',
      found: true,
    } : r));
    setSearchQueries(prev => { const n = { ...prev }; delete n[rowId]; return n; });
    setSearchMatches(prev => { const n = { ...prev }; delete n[rowId]; return n; });
    setSearchActiveRowId(cur => (cur === rowId ? null : cur));
  };

  const resultatsVerrouilles = results.filter(r => r.etudiant_id && r.verrouille);

  const handleNextFromResults = () => {
    const unrec = results.filter(r => !r.etudiant_id);
    if (unrec.length > 0) {
      toast.warning(`${unrec.length} étudiant(s) introuvable(s) : reliez-les par recherche ou cliquez « Ignorer ».`);
      return;
    }
    const unreadable = results.filter(r => r.notes.some(c => c.invalid && (c.valeur ?? '').trim() !== ''));
    if (unreadable.length > 0) {
      toast.warning(`${unreadable.length} ligne(s) avec une note illisible. Corrigez-la (ou videz la case) avant de continuer.`);
      return;
    }
    setStep(4);
  };

  const handleConfirm = async () => {
    if (results.length === 0) return;
    const missing = results.filter(r => !r.etudiant_id);
    if (missing.length > 0) {
      toast.warning(`${missing.length} étudiant(s) non reconnu(s). Reliez-les ou ignorez-les avant d'enregistrer.`);
      return;
    }
    if (resultatsVerrouilles.length > 0) {
      toast.warning(MESSAGE_SEMESTRE_PUBLIE);
      return;
    }

    const notes = [];
    results.forEach(r => r.notes.forEach(c => {
      const text = (c.valeur ?? '').trim();
      if (text === '') return;
      notes.push({ etudiant_id: r.etudiant_id, controle_id: c.controle_id, valeur: text });
    }));

    if (notes.length === 0) {
      toast.warning('Aucune note à enregistrer.');
      return;
    }

    setSaving(true);
    try {
      const res = await soutenanceApi.confirm({
        unite_id: uniteId,
        annee_academique_id: currentAnnee?.id,
        groupe_id: selGroupe,
        notes,
      });
      showSuccess(toast, res.data.message || 'Notes de soutenance enregistrées');
      const skipped = res.data.skipped || [];
      if (skipped.length > 0) {
        const raisons = [...new Set(skipped.map(s => s.raison).filter(Boolean))];
        toast.warning(`${skipped.length} case(s) non enregistrée(s)${raisons.length ? ' — ' + raisons.join(' · ') : ''}`);
      }
      setStep(1);
      setPdfs([]);
      setPdfUrls([]);
      setResults([]);
      setSearchQueries({});
      setSearchMatches({});
      setSearchActiveRowId(null);
    } catch (err) {
      handleApiError(err, toast);
    } finally {
      setSaving(false);
    }
  };

  return (
    <div className="page">
      <div className="page-header">
        <h2 className="page-title">Soutenance</h2>
      </div>

      <div className="steps" style={{ marginBottom: 28 }}>
        {STEPS.map((label, i) => (
          <div key={i} style={{ display: 'flex', alignItems: 'center', flex: 1 }}>
            <div style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
              <div style={{
                width: 28, height: 28, borderRadius: '50%',
                background: step > i + 1 ? 'var(--success)' : step === i + 1 ? 'var(--primary)' : 'var(--gray-200)',
                color: step >= i + 1 ? 'white' : 'var(--gray-500)',
                display: 'flex', alignItems: 'center', justifyContent: 'center',
                fontSize: 12, fontWeight: 600,
              }}>
                {step > i + 1 ? '✓' : i + 1}
              </div>
              <span style={{ fontSize: 13, color: step === i + 1 ? 'var(--primary)' : 'var(--gray-400)', fontWeight: step === i + 1 ? 500 : 400 }}>
                {label}
              </span>
            </div>
          </div>
        ))}
      </div>

      {step === 1 && (
        programmes.length === 0 ? (
          <div className="card card-body" style={{ maxWidth: 720 }}>
            <div className="alert alert-info" style={{ margin: 0 }}>
              Aucune soutenance n'est configurée. Seules les filières de dernière année en ont une.
            </div>
          </div>
        ) : (
          <div className="card card-body" style={{ maxWidth: 600 }}>
            <div className="form-group">
              <FilterSelect
                block
                label="Filière"
                value={selFiliere}
                options={filieres.map(f => ({ id: f.id, label: f.nom }))}
                onChange={setSelFiliere}
                placeholder="Sélectionner"
                allLabel="Sélectionner"
              />
            </div>
            <div className="form-group">
              <FilterSelect
                block
                label="Année"
                value={selAnnee}
                options={annees.map(a => ({ id: a, label: formatNiveau(a) }))}
                onChange={v => { setSelAnnee(v); setSelGroupe(''); setResults([]); }}
                disabled={!selFiliere || annees.length === 0}
                placeholder="Sélectionner"
                allLabel="Sélectionner"
              />
            </div>
            <div className="form-group">
              <FilterSelect
                block
                label="Liste (groupe)"
                value={selGroupe}
                options={groupes.map(g => ({ id: g.id, label: g.nom }))}
                onChange={setSelGroupe}
                disabled={!selectedProgramme || groupes.length === 0}
                placeholder="Sélectionner"
                allLabel="Sélectionner"
              />
            </div>
            {selectedProgramme && groupes.length === 0 && (
              <p style={{ margin: '8px 0', fontSize: 13, color: '#b45309' }}>
                Aucun groupe trouvé pour cette filière/année. Vérifiez l'année académique.
              </p>
            )}
            {selectedProgramme && groupes.length > 1 && !selGroupe && (
              <div className="alert alert-warning" style={{ padding: '12px 16px', fontSize: 13 }}>
                <strong>Choisissez la liste (groupe)</strong> de cette feuille : la lecture des noms se fait
                uniquement à l'intérieur de la liste choisie.
              </div>
            )}
            {selectedProgramme && (
              <p style={{ margin: '8px 0', fontSize: 13, color: '#666' }}>
                {criteres.length} critère(s) de jury à noter sur 20 (soutenance de fin de cursus).
              </p>
            )}
            <button
              className="btn btn-primary"
              disabled={!selectedProgramme || !selGroupe}
              onClick={() => setStep(2)}
            >
              Suivant →
            </button>
          </div>
        )
      )}

      {step === 2 && (
        <div className="card card-body" style={{ maxWidth: 720, width: '100%' }}>
          <div style={{ marginBottom: 16, padding: '14px 18px', borderRadius: 10, background: '#eff6ff', border: '1px solid #bfdbfe', fontSize: 13, lineHeight: 1.7 }}>
            <div style={{ fontWeight: 700, color: '#1d4ed8', marginBottom: 6 }}>Important</div>
            <div style={{ display: 'flex', flexDirection: 'column', gap: 4, color: '#1e3a5f' }}>
              <span>1. Uploadez la <strong>feuille du jury</strong> (liste des étudiants + notes des critères)</span>
              <span>2. Chaque note se lit par quart de point (0,25) ; un critère absent s'écrit « AB »</span>
            </div>
          </div>
          <div className="upload-zone">
            <input
              type="file"
              accept=".pdf,application/pdf"
              multiple
              onChange={handleFiles}
              disabled={loading}
            />
            <div className="upload-zone-text">
              <strong>Cliquer ou glisser</strong> les fichiers PDF de la feuille du jury
            </div>
          </div>
          {pdfUrls.length > 0 && (
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
        </div>
      )}

      {step === 3 && (
        <div style={{ maxWidth: 1400, width: '100%', margin: '0 auto' }}>
          <div className="alert alert-info" style={{ marginBottom: 16 }}>
            Vérifiez les résultats. Toutes les notes sont modifiables ; « AB » marque un critère absent.
            Pour un nom non reconnu, tapez-le pour le retrouver dans la liste.
          </div>

          {results.length === 0 ? (
            <div className="text-center" style={{ padding: 40 }}>
              <h4 style={{ color: '#666', marginBottom: 12 }}>Aucune note détectée</h4>
              <p style={{ color: '#999', marginBottom: 24 }}>Vérifiez les PDFs ou réessayez le scan.</p>
              <button className="btn btn-primary" onClick={() => setStep(2)} disabled={loading}>Retour aux fichiers</button>
            </div>
          ) : (
            <div>
              <div style={{ overflowX: 'auto', marginBottom: 16, border: '1px solid #e5e7eb', borderRadius: 8 }}>
                <table style={{ fontSize: 14, minWidth: 220 + criteres.length * 90, width: '100%' }}>
                  <thead>
                    <tr style={{ backgroundColor: '#f9fafb' }}>
                      <th style={{ padding: '12px 16px', fontWeight: 600, minWidth: 200, textAlign: 'left' }}>Nom</th>
                      <th style={{ padding: '12px 16px', fontWeight: 600, minWidth: 120, textAlign: 'left' }}>N° Inscription</th>
                      <th style={{ padding: '12px 16px', fontWeight: 600, minWidth: 100, textAlign: 'left' }}>Groupe</th>
                      {criteres.map(c => (
                        <th key={c.id} style={{ padding: '12px 8px', fontWeight: 600, minWidth: 84, textAlign: 'center' }} title={c.nom}>
                          {c.numero}
                        </th>
                      ))}
                      <th style={{ padding: '12px 16px', fontWeight: 600, minWidth: 130, textAlign: 'left' }}>Statut</th>
                      <th style={{ padding: '12px 16px', fontWeight: 600, minWidth: 90, textAlign: 'left' }}>Actions</th>
                    </tr>
                  </thead>
                  <tbody>
                    {results.map(r => {
                      const isUnmatched = !r.etudiant_id;
                      const hasInvalid = r.notes.some(c => c.invalid && (c.valeur ?? '').trim() !== '');
                      return (
                        <tr key={r.rowId} style={{
                          borderBottom: '1px solid #e5e7eb',
                          background: isUnmatched ? '#fff7ed' : hasInvalid ? '#fef2f2' : 'transparent',
                        }}>
                          <td style={{ padding: '12px 16px' }}>
                            {isUnmatched ? (
                              <input
                                className="form-input"
                                style={{ width: '100%', padding: '6px 10px', fontSize: 14 }}
                                value={searchQueries[r.rowId] ?? r.nom}
                                onChange={e => handleSearchChange(r.rowId, e.target.value)}
                                placeholder="Rechercher un étudiant..."
                                disabled={loading}
                                onClick={e => e.stopPropagation()}
                                onFocus={e => {
                                  const rect = e.target.getBoundingClientRect();
                                  setSearchDropdownStyle({ top: rect.bottom + 4, left: rect.left, width: rect.width });
                                  setSearchActiveRowId(r.rowId);
                                }}
                              />
                            ) : (
                              <span style={{ display: 'block' }}>{r.nom}</span>
                            )}
                          </td>
                          <td style={{ padding: '12px 16px' }}>{r.numero_inscription || <span style={{ color: '#999' }}>—</span>}</td>
                          <td style={{ padding: '12px 16px' }}>{r.groupe}</td>
                          {criteres.map((c, i) => {
                            const cell = r.notes[i] || {};
                            const hint = cell.invalid ? invalidNoteHint(cell.raw) : '';
                            return (
                              <td key={c.id} style={{ padding: '12px 8px', textAlign: 'center' }}>
                                {r.verrouille ? (
                                  <span style={{ display: 'block', color: '#b91c1c', fontWeight: 600 }} title={MESSAGE_SEMESTRE_PUBLIE}>
                                    {cell.valeur || '—'}
                                  </span>
                                ) : isUnmatched ? (
                                  <span style={{ display: 'block', color: '#999' }}>{cell.valeur || '—'}</span>
                                ) : (
                                  <input
                                    type="text"
                                    inputMode="decimal"
                                    className="form-input"
                                    style={{ width: 64, padding: '8px 6px', fontSize: 14, textAlign: 'center' }}
                                    value={cell.valeur ?? ''}
                                    placeholder="—"
                                    disabled={loading}
                                    onChange={e => updateCell(r.rowId, i, e.target.value)}
                                  />
                                )}
                                {hint && (
                                  <div style={{ fontSize: 11, color: 'var(--danger)', marginTop: 4 }}>{hint}</div>
                                )}
                              </td>
                            );
                          })}
                          <td style={{ padding: '12px 16px' }}>
                            {r.verrouille ? (
                              <span style={{ color: '#b45309', fontWeight: 600, fontSize: 13 }} title={MESSAGE_SEMESTRE_PUBLIE}>Publié</span>
                            ) : r.etudiant_id ? (
                              <span style={{ color: 'var(--success)', fontWeight: 600, fontSize: 13 }}>✓ Reconnu</span>
                            ) : (
                              <span style={{ color: 'var(--danger)', fontWeight: 600, fontSize: 13 }}>✗ Introuvable</span>
                            )}
                          </td>
                          <td style={{ padding: '12px 16px' }}>
                            {isUnmatched ? (
                              <button className="btn btn-sm btn-danger" onClick={() => skipStudent(r)} disabled={loading}>Ignorer</button>
                            ) : (
                              <button className="btn btn-sm btn-danger" onClick={() => removeResult(r.rowId)} disabled={loading}>Retirer</button>
                            )}
                          </td>
                        </tr>
                      );
                    })}
                  </tbody>
                </table>
              </div>
              <div style={{ display: 'flex', gap: 8 }}>
                <button className="btn btn-outline" onClick={() => setStep(2)} disabled={loading}>← Retour</button>
                <button className="btn btn-primary" onClick={handleNextFromResults} disabled={loading}>Suivant →</button>
              </div>
            </div>
          )}

          {searchActiveRowId !== null && searchMatches[searchActiveRowId]?.length > 0 && (
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
                boxShadow: '0 4px 12px rgba(0,0,0,0.15)',
              }}
            >
              {searchMatches[searchActiveRowId].map(s => (
                <div
                  key={s.id}
                  style={{ padding: '8px 12px', cursor: 'pointer', borderBottom: '1px solid #f3f4f6' }}
                  onClick={() => selectStudent(searchActiveRowId, s)}
                >
                  <div style={{ fontWeight: 600, fontSize: 13 }}>{s.nom_prenom}</div>
                  <div style={{ fontSize: 12, color: '#777' }}>
                    {s.numero_inscription || ''}{s.groupe ? ` — ${s.groupe}` : ''}
                  </div>
                </div>
              ))}
            </div>
          )}
        </div>
      )}

      {step === 4 && (
        <div className="card card-body" style={{ maxWidth: 600 }}>
          <div className="alert alert-warning" style={{ marginBottom: 16 }}>
            <strong>Confirmez pour enregistrer les notes</strong> — cela écrira les notes de soutenance des{' '}
            {results.length} étudiant(s) affiché(s) sur les critères du jury.
          </div>
          {resultatsVerrouilles.length > 0 && (
            <div className="alert alert-danger" style={{ marginBottom: 16 }}>
              {MESSAGE_SEMESTRE_PUBLIE}
              <ul style={{ margin: '8px 0 0 18px', padding: 0 }}>
                {resultatsVerrouilles.map(r => (
                  <li key={r.etudiant_id}>{r.nom} — {r.groupe}</li>
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
              {saving ? <><Spinner /> Enregistrement...</> : `Confirmer ${results.length} ligne(s)`}
            </button>
          </div>
        </div>
      )}
    </div>
  );
}
