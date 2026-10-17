import { useEffect, useState, useRef, useMemo } from 'react';
import { FiX } from 'react-icons/fi';
import { adminApi } from '../../api/admin';
import { scanApi } from '../../api/scan';
import { useToast } from '../../context/ToastContext';
import { useAnneeAcademique } from '../../context/AnneeAcademiqueContext';
import Spinner from '../../components/common/Spinner';
import FilterSelect from '../../components/common/FilterSelect';
import { handleApiError, showSuccess } from '../../utils/errorHandler';
import { MESSAGE_SEMESTRE_PUBLIE, formatNiveau, invalidNoteHint } from '../../utils/helpers';
import '../../css/components.css';
import '../../css/layout.css';

// A French locale renders the decimal separator as a comma, and `Number('12,5')`
// is NaN. Anything that is not a finite number becomes null so a grade is never
// stored as NaN.
const toGrade = (v) => {
  if (v === null || v === undefined || String(v).trim() === '') return null;
  const n = Number(String(v).replace(',', '.').trim());
  return Number.isFinite(n) ? n : null;
};

// Stage and Soutenance are sequence-free units graded outside the exam scanner
// (their own page / grids). Offering them here would let the scan write
// théorique/pratique Examen rows that no average ever reads.
const UNITES_HORS_SCAN_EXAMENS = ['Stage', 'Soutenance'];

export default function ScanExamens() {
  const toast = useToast();
  const { currentAnnee } = useAnneeAcademique();

  const [step, setStep] = useState(1);
  const [filieres, setFilieres] = useState([]);
  const [allUnites, setAllUnites] = useState([]);
  const [niveaux, setNiveaux] = useState([]);
  const [groupes, setGroupes] = useState([]);
  const [selFiliere, setSelFiliere] = useState('');
  const [selAnnee, setSelAnnee] = useState('');
  const [selUnite, setSelUnite] = useState('');
  const [selType, setSelType] = useState('');
  const [selGroupe, setSelGroupe] = useState('');

  const [pdfs, setPdfs] = useState([]);
  const [pdfUrls, setPdfUrls] = useState([]);
  const [results, setResults] = useState([]);
  const [scanning, setScanning] = useState(false);
  const [saving, setSaving] = useState(false);
  const [searchQueries, setSearchQueries] = useState({});
  const [searchMatches, setSearchMatches] = useState({});
  // Every row carries a stable rowId, and all per-row UI state is keyed by it:
  // deleting one row must not shift the search state onto another student.
  const [searchActiveRowId, setSearchActiveRowId] = useState(null);
  const [searchDropdownStyle, setSearchDropdownStyle] = useState({});
  const searchTimers = useRef({});
  const liveRowIds = useRef(new Set());
  const rowIdSeq = useRef(0);
  const newRowId = () => `r${++rowIdSeq.current}`;

  const loading = scanning || saving;

  const annees = useMemo(
    () => [...new Set(allUnites.map(u => Number(u.numero_annee)).filter(Boolean))].sort((a, b) => a - b),
    [allUnites]
  );
  const unites = useMemo(
    () => selAnnee ? allUnites.filter(u => Number(u.numero_annee) === Number(selAnnee)) : allUnites,
    [allUnites, selAnnee]
  );
  const selectedUnite = unites.find(u => u.id == selUnite) || allUnites.find(u => u.id == selUnite);
  const semestre = selectedUnite?.semestre || '';
  const bloc = semestre;

  useEffect(() => {
    adminApi.getFilieres().then(r => setFilieres(r.data.data)).catch(() => {});
    return () => { pdfUrls.forEach(u => URL.revokeObjectURL(u)); };
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  useEffect(() => {
    if (selFiliere) {
      adminApi.getNiveaux(selFiliere).then(r => setNiveaux(r.data.data || [])).catch(() => setNiveaux([]));
      adminApi.getUnites({ filiere_id: selFiliere })
        .then(r => {
          const data = (r.data.data || []).filter(u => !UNITES_HORS_SCAN_EXAMENS.includes(u.nom));
          setAllUnites(data);
          const years = [...new Set(data.map(u => Number(u.numero_annee)).filter(Boolean))];
          if (years.length === 1) setSelAnnee(String(years[0]));
        })
        .catch(() => setAllUnites([]));
    } else {
      setAllUnites([]);
      setNiveaux([]);
      setGroupes([]);
    }
    setSelAnnee('');
    setSelUnite('');
    setSelGroupe('');
    setResults([]);
  }, [selFiliere]);

  // The list the scan reconciles against: groupes of the unite's filière/année
  // for the current academic year.
  useEffect(() => {
    setGroupes([]);
    setSelGroupe('');
    if (!selUnite || !selFiliere || !currentAnnee?.id) return;
    const unite = unites.find(u => u.id == selUnite);
    const niveau = (niveaux || []).find(n => Number(n.numero) === Number(unite?.numero_annee));
    if (!niveau) return;
    adminApi.getGroupes({ niveau_id: niveau.id, annee_academique_id: currentAnnee.id })
      .then(r => {
        const data = r.data.data || [];
        setGroupes(data);
        if (data.length === 1) setSelGroupe(String(data[0].id));
      })
      .catch(() => setGroupes([]));
  }, [selUnite, selFiliere, currentAnnee, unites, niveaux]);

  useEffect(() => {
    liveRowIds.current = new Set(results.map(r => r.rowId));
  }, [results]);

  useEffect(() => {
    const handleClick = () => setSearchActiveRowId(null);
    document.addEventListener('click', handleClick);
    return () => document.removeEventListener('click', handleClick);
  }, []);

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

  const handleScan = async () => {
    if (pdfs.length === 0 || !selUnite || !selType || !selGroupe || !currentAnnee?.id) {
      toast.warning('Veuillez remplir tous les champs et ajouter des PDFs');
      return;
    }
    if (results.length > 0) {
      const confirmed = window.confirm(
        `Un nouveau scan remplace les ${results.length} ligne(s) déjà affichées, ` +
        `y compris les notes saisies à la main. Continuer ?`
      );
      if (!confirmed) return;
    }
    setScanning(true);
    setResults([]);
    setSearchQueries({});
    setSearchMatches({});
    setSearchActiveRowId(null);
    try {
      const fd = new FormData();
      pdfs.forEach(p => fd.append('pdfs[]', p));
      fd.append('unite_id', selUnite);
      fd.append('bloc', bloc);
      fd.append('type', selType);
      fd.append('semestre', semestre);
      fd.append('annee_academique_id', currentAnnee.id);
      fd.append('filiere_id', selFiliere);
      fd.append('groupe_id', selGroupe);

      const res = await scanApi.scanExamens(fd);
      const data = res.data;
      if (!data.success) {
        toast.error(data.message || 'Erreur lors du scan');
        setScanning(false);
        return;
      }

      const scanned = (data.data?.resultats || []).map(r => ({
        rowId: newRowId(),
        nom: r.nom_prenom || '',
        nom_ar: r.nom_ar || '',
        numero_inscription: r.numero_inscription || '',
        groupe: r.groupe || '',
        note: r.note,
        est_absent: !!r.est_absent,
        raw_note: r.raw_note ?? null,
        note_invalide: !!r.note_invalide,
        etudiant_id: r.etudiant_id || null,
        groupe_id: r.groupe_id || null,
        verrouille: r.verrouille || false,
        already_confirmed: r.already_confirmed || false,
        found: r.found || false,
      }));

      if (scanned.length === 0) {
        toast.error('Aucun résultat trouvé. Vérifiez que les PDF sont lisibles.');
      } else {
        const unrec = scanned.filter(r => !r.found).length;
        const absent = scanned.filter(r => r.est_absent).length;
        const dejaNote = scanned.filter(r => r.already_confirmed).length;
        let msg = `${scanned.length} note(s) détectée(s)`;
        if (unrec > 0) msg += `, ${unrec} non reconnue(s)`;
        if (absent > 0) msg += `, ${absent} absent(s)`;
        if (dejaNote > 0) msg += `, ${dejaNote} déjà notée(s) (seront écrasées)`;
        showSuccess(toast, msg);
        setResults(scanned);
        setStep(3);
      }
    } catch (err) {
      handleApiError(err, toast);
      setResults([]);
    } finally {
      setScanning(false);
    }
  };

  const updateResult = (rowId, key, val) => {
    setResults(prev => prev.map(r => r.rowId === rowId ? { ...r, [key]: val } : r));
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
    setResults(prev => prev.filter(r => r.rowId !== row.rowId));
    setSearchQueries(prev => { const n = { ...prev }; delete n[row.rowId]; return n; });
    setSearchMatches(prev => { const n = { ...prev }; delete n[row.rowId]; return n; });
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
        const res = await scanApi.searchScanExamensEtudiants({
          search: value,
          annee_academique_id: currentAnnee?.id,
          filiere_id: selFiliere,
          niveau_numero: selectedUnite?.numero_annee,
          semestre: semestre || undefined,
          groupe_id: selGroupe || undefined,
        });
        const data = res.data.data || [];
        if (!liveRowIds.current.has(rowId)) return;
        setSearchMatches(prev => ({ ...prev, [rowId]: data }));
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
      nom_ar: student.nom_ar ?? r.nom_ar,
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
      toast.warning(`${unrec.length} étudiant(s) introuvable(s) : leur nom ne correspond à aucun étudiant de la liste. Reliez-les par recherche ou cliquez « Ignorer ».`);
      return;
    }
    const unreadable = results.filter(r => r.note_invalide && !r.est_absent);
    if (unreadable.length > 0) {
      toast.warning(`${unreadable.length} note(s) illisible(s). Saisissez-les ou cochez « Absent » avant de continuer.`);
      return;
    }
    setStep(4);
  };

  const handleConfirm = async () => {
    if (results.length === 0) return;
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
        resultats: results.map(r => ({
          etudiant_id: r.etudiant_id,
          note: r.est_absent ? null : r.note,
          est_absent: !!r.est_absent,
        })),
        unite_id: selUnite,
        bloc: bloc,
        type: selType,
        semestre: semestre,
        annee_academique_id: currentAnnee?.id,
        groupe_id: selGroupe,
      });
      showSuccess(toast, res.data.message || 'Notes enregistrées');
      // A refused row (14,7 entered by hand, blank cell) is not saved: say so
      // instead of letting the lower count pass for a clean result.
      const skipped = res.data.skipped || [];
      if (skipped.length > 0) {
        const raisons = [...new Set(skipped.map(s => s.raison).filter(Boolean))];
        toast.warning(`${skipped.length} ligne(s) non enregistrée(s)${raisons.length ? ' — ' + raisons.join(' · ') : ''}`);
      }
      setStep(1);
      setPdfs([]);
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
            <FilterSelect
              block
              label="Filière"
              value={selFiliere}
              options={filieres.map(f => ({ id: f.id, label: f.nom }))}
              onChange={(v) => { setSelFiliere(v); }}
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
              onChange={(v) => { setSelAnnee(v); setSelUnite(''); setSelGroupe(''); }}
              disabled={!selFiliere || annees.length === 0}
              placeholder="Sélectionner"
              allLabel="Toutes"
            />
          </div>
          <div className="form-group">
            <FilterSelect
              block
              label="Unité"
              value={selUnite}
              options={unites.map(u => ({ id: u.id, label: `${formatNiveau(u.numero_annee)} — ${u.nom} (S${u.semestre})` }))}
              onChange={(v) => { setSelUnite(v); setSelGroupe(''); }}
              disabled={!selFiliere}
              placeholder="Sélectionner"
              allLabel="Sélectionner"
            />
          </div>
          <div className="form-group">
            <FilterSelect
              block
              label="Type d'examen"
              value={selType}
              options={[
                { id: 'theorique', label: 'Théorique' },
                { id: 'pratique', label: 'Pratique' },
              ]}
              onChange={setSelType}
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
              disabled={!selUnite || groupes.length === 0}
              placeholder="Sélectionner"
              allLabel="Sélectionner"
            />
          </div>
          {bloc && <p style={{ margin: '8px 0', fontSize: 13, color: '#666' }}>Session (Bloc) : {bloc} (détecté depuis l'unité)</p>}
          {selUnite && groupes.length === 0 && (
            <p style={{ margin: '8px 0', fontSize: 13, color: '#b45309' }}>
              Aucun groupe trouvé pour cette filière/année. Vérifiez l'année académique.
            </p>
          )}
          {selUnite && groupes.length > 1 && !selGroupe && (
            <div className="alert alert-warning" style={{ padding: '12px 16px', fontSize: 13 }}>
              <strong>Choisissez la liste (groupe)</strong> de cette feuille : la lecture des noms se fait
              uniquement à l'intérieur de la liste choisie.
            </div>
          )}
          <button className="btn btn-primary" disabled={!selUnite || !selType || !selGroupe} onClick={() => setStep(2)}>
            Suivant →
          </button>
        </div>
      )}

      {step === 2 && (
        <div className="card card-body" style={{ maxWidth: 720, width: '100%' }}>
          <div style={{ marginBottom: 16, padding: '14px 18px', borderRadius: 10, background: '#eff6ff', border: '1px solid #bfdbfe', fontSize: 13, lineHeight: 1.7 }}>
            <div style={{ fontWeight: 700, color: '#1d4ed8', marginBottom: 6 }}>Important</div>
            <div style={{ display: 'flex', flexDirection: 'column', gap: 4, color: '#1e3a5f' }}>
              <span>1. Uploadez les <strong>feuilles de copies corrigées</strong> (liste des étudiants + note)</span>
              <span>2. Le scan lit les <strong>noms</strong> sur la feuille et les rapproche de la liste du groupe sélectionné</span>
            </div>
            <div style={{ marginTop: 6, padding: '6px 10px', background: '#dbeafe', borderRadius: 6, fontSize: 12, color: '#1e40af' }}>
              Aucune feuille de correspondance ni code anonyme n'est nécessaire.
            </div>
          </div>
          <div className="upload-zone">
            <input type="file" accept=".pdf" multiple onChange={handleFiles} disabled={loading} />
            <div className="upload-zone-text">
              <strong>Cliquer ou glisser</strong> les fichiers PDF des copies corrigées
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
        </div>
      )}

      {step === 3 && (
        <div style={{ maxWidth: 1200, width: '100%', margin: '0 auto' }}>
          <div className="alert alert-info" style={{ marginBottom: 16 }}>
            Vérifiez les résultats. Seule la note est modifiable. Pour les étudiants non reconnus, tapez le nom pour les retrouver dans la liste.
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
                <table style={{ fontSize: '15px', minWidth: '760px', width: '100%' }}>
                  <thead>
                    <tr style={{ backgroundColor: '#f9fafb' }}>
                      <th style={{ padding: '12px 16px', fontWeight: 600, minWidth: '220px' }}>Nom</th>
                      <th style={{ padding: '12px 16px', fontWeight: 600, minWidth: '120px' }}>N° Inscription</th>
                      <th style={{ padding: '12px 16px', fontWeight: 600, minWidth: '110px' }}>Groupe</th>
                      <th style={{ padding: '12px 16px', fontWeight: 600, minWidth: '100px' }}>Note /20</th>
                      <th style={{ padding: '12px 16px', fontWeight: 600, minWidth: '90px' }}>Absence</th>
                      <th style={{ padding: '12px 16px', fontWeight: 600, minWidth: '140px' }}>Statut</th>
                      <th style={{ padding: '12px 16px', fontWeight: 600, minWidth: '110px' }}>Actions</th>
                    </tr>
                  </thead>
                  <tbody>
                    {results.map((r) => {
                      const isUnmatched = !r.etudiant_id;
                      return (
                        <tr key={r.rowId} style={{
                          borderBottom: '1px solid #e5e7eb',
                          background: isUnmatched ? '#fff7ed' : r.note_invalide && !r.est_absent ? '#fef2f2' : 'transparent'
                        }}>
                          <td style={{ padding: '12px 16px' }}>
                            {isUnmatched ? (
                              <input
                                className="form-input"
                                style={{ width: '100%', padding: '6px 10px', fontSize: '14px' }}
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
                              <span style={{ padding: '8px 12px', display: 'block' }}>{r.nom}</span>
                            )}
                          </td>
                          <td style={{ padding: '12px 16px' }}>
                            <span style={{ padding: '8px 12px', display: 'block' }}>{r.numero_inscription || <span style={{ color: '#999' }}>—</span>}</span>
                          </td>
                          <td style={{ padding: '12px 16px' }}>
                            <span style={{ padding: '8px 12px', display: 'block' }}>{r.groupe}</span>
                          </td>
                          <td style={{ padding: '12px 16px' }}>
                            {r.verrouille ? (
                              <span style={{ padding: '8px 12px', display: 'block', color: '#b91c1c', fontWeight: 600 }} title={MESSAGE_SEMESTRE_PUBLIE}>
                                {r.est_absent ? 'Absent' : (r.note ?? '—')}
                              </span>
                            ) : !r.etudiant_id ? (
                              <span style={{ padding: '8px 12px', display: 'block', color: '#999' }}>{r.est_absent ? 'Absent' : (r.note ?? '—')}</span>
                            ) : r.est_absent ? (
                              <span style={{
                                display: 'inline-flex', alignItems: 'center', gap: 6, padding: '6px 10px',
                                background: '#fee2e2', color: '#991b1b', borderRadius: 6, fontWeight: 600, fontSize: 13
                              }}>
                                Absent
                              </span>
                            ) : (
                              <input
                                type="number"
                                className="form-input"
                                style={{ width: 70, padding: '8px 12px', fontSize: '15px' }}
                                value={r.note ?? ''}
                                min={0}
                                max={20}
                                step="0.25"
                                disabled={loading}
                                onChange={e => {
                                  const v = e.target.value;
                                  updateResult(r.rowId, 'note', toGrade(v));
                                  if (v !== '') updateResult(r.rowId, 'note_invalide', false);
                                }}
                              />
                            )}
                            {r.note_invalide && !r.est_absent && r.etudiant_id && (
                              <div style={{ fontSize: 11, color: 'var(--danger)', marginTop: 4 }}>
                                {invalidNoteHint(r.raw_note)}
                              </div>
                            )}
                          </td>
                          <td style={{ padding: '12px 16px' }}>
                            {r.etudiant_id && !r.verrouille && (
                              <label style={{ display: 'flex', alignItems: 'center', gap: 6, fontSize: 13, cursor: 'pointer' }}>
                                <input
                                  type="checkbox"
                                  checked={!!r.est_absent}
                                  disabled={loading}
                                  onChange={e => {
                                    const on = e.target.checked;
                                    setResults(prev => prev.map(row => row.rowId === r.rowId
                                      ? on
                                        ? { ...row, est_absent: true, note_avant_absent: row.note ?? null, note: 0, note_invalide: false }
                                        : { ...row, est_absent: false, note: row.note_avant_absent ?? null, note_avant_absent: null, note_invalide: (row.note_avant_absent ?? null) === null }
                                      : row));
                                  }}
                                />
                                Absent
                              </label>
                            )}
                          </td>
                          <td style={{ padding: '12px 16px' }}>
                            {r.verrouille ? (
                              <span style={{ color: '#b45309', fontWeight: 600, fontSize: 13 }} title={MESSAGE_SEMESTRE_PUBLIE}>
                                Publié
                              </span>
                            ) : r.etudiant_id ? (
                              <span style={{ color: 'var(--success)', fontWeight: 600, fontSize: 13 }}>
                                ✓ Reconnu{r.already_confirmed && <span style={{ color: 'var(--warning)', marginLeft: 6 }}>· déjà noté</span>}
                              </span>
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
                boxShadow: '0 4px 12px rgba(0,0,0,0.12)'
              }}
              onClick={e => e.stopPropagation()}
            >
              {searchMatches[searchActiveRowId].map(s => (
                <div
                  key={s.id}
                  style={{ padding: '8px 10px', cursor: 'pointer', borderBottom: '1px solid #f3f4f6', fontSize: 13 }}
                  onClick={() => selectStudent(searchActiveRowId, s)}
                  onMouseEnter={e => e.currentTarget.style.background = '#f9fafb'}
                  onMouseLeave={e => e.currentTarget.style.background = 'transparent'}
                >
                  <div style={{ fontWeight: 500 }}>{s.nom_prenom}</div>
                  <div style={{ fontSize: 11, color: '#999' }}>
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
            <strong>Confirmez pour enregistrer les notes</strong> — cela enregistrera {results.length} note(s) d'examen
            {bloc ? ` (session ${bloc})` : ''} pour l'unité sélectionnée.
          </div>
          {results.some(r => r.already_confirmed) && (
            <div className="alert alert-warning" style={{ marginBottom: 16 }}>
              {results.filter(r => r.already_confirmed).length} étudiant(s) avaient déjà une note d'examen : elles seront
              remplacées par ce scan.
            </div>
          )}
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
              {saving ? <><Spinner /> Enregistrement...</> : `Confirmer ${results.length} note(s)`}
            </button>
          </div>
        </div>
      )}
    </div>
  );
}