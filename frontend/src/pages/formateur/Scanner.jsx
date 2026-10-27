import { useEffect, useState, useRef, useCallback, useMemo } from 'react';
import { FiX, FiUploadCloud, FiCheckCircle } from 'react-icons/fi';
import { formateursApi } from '../../api/formateurs';
import { scanApi } from '../../api/scan';
import { useToast } from '../../context/ToastContext';
import Spinner from '../../components/common/Spinner';
import Modal from '../../components/common/Modal';
import FilterSelect from '../../components/common/FilterSelect';
import { handleApiError, showSuccess } from '../../utils/errorHandler';
import { controlLabel, invalidNoteHint, formatNiveau } from '../../utils/helpers';

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

// Step header shared by every card so the wizard reads as one page, with the
// step number, a title and a short description. Uses existing palette vars.
const ScanStepHead = ({ n, title, subtitle }) => (
  <div style={{ display: 'flex', alignItems: 'center', gap: 12, marginBottom: 18 }}>
    <div style={{
      width: 36, height: 36, borderRadius: '50%', flexShrink: 0,
      background: 'var(--primary-faint)', color: 'var(--primary)',
      display: 'flex', alignItems: 'center', justifyContent: 'center',
      fontWeight: 700, fontSize: 16,
    }}>{n}</div>
    <div>
      <h4 style={{ margin: 0, fontSize: 16, fontWeight: 700, color: 'var(--gray-800)' }}>{title}</h4>
      {subtitle && <div style={{ fontSize: 13, color: 'var(--gray-500)', marginTop: 2 }}>{subtitle}</div>}
    </div>
  </div>
);

export default function Scanner() {
  const [step, setStep] = useState(1);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);
  // Bumped by the error banner's "Réessayer" to re-run the loader in place,
  // instead of a full page reload that threw away the whole screen.
  const [reloadKey, setReloadKey] = useState(0);
  const [filieres, setFilieres] = useState([]);
  const [sequences, setSequences] = useState([]);
  const [sel, setSel] = useState({
    filiere_id: '', annee: '', sequence_id: '', controle_id: '', groupe_id: ''
  });
  const [groupesParPerimetre, setGroupesParPerimetre] = useState({});
  // Mirror of `sel` readable inside async callbacks, where the state value
  // captured by the closure would still be the stale one.
  const selRef = useRef(sel);
  useEffect(() => { selRef.current = sel; }, [sel]);

  const [pdfs, setPdfs] = useState([]);
  const [results, setResults] = useState([]);
  const [scanning, setScanning] = useState(false);
  const [savingResults, setSavingResults] = useState(false);
  const [confirmRescan, setConfirmRescan] = useState(false);
  const [pdfUrls, setPdfUrls] = useState([]);
  const [allConfirmed, setAllConfirmed] = useState(false);
  const [publicationMap, setPublicationMap] = useState({});
  // Locked groups per control. publicationMap only marks a control as dead when
  // *every* group in its perimeter is published, so the actual blocking of a
  // scan has to be resolved against the group the sheet belongs to.
  const [lockedGroupes, setLockedGroupes] = useState({});
  const [savedSummary, setSavedSummary] = useState(null);

  const processing = scanning || savingResults;

  const toast = useToast();

  const [searchQueries, setSearchQueries] = useState({});
  const [searchMatches, setSearchMatches] = useState({});
  // Every row carries a stable rowId, and all per-row UI state is keyed by it.
  // Addressing rows by array index meant that deleting one row shifted every
  // later row down by one: a half-typed search, its suggestion list and any
  // pending debounce stayed on the old index and silently moved onto a different
  // student, so clicking a suggestion overwrote that other student's row and
  // their grade was filed under the wrong name.
  const [searchActiveRowId, setSearchActiveRowId] = useState(null);
  const [searchDropdownStyle, setSearchDropdownStyle] = useState({});
  // One timer per row: a single shared timer meant typing in one row cancelled
  // another row's pending lookup, which was then dropped without a word.
  const searchTimers = useRef({});
  // Ids of the rows that currently exist, readable from async callbacks where
  // the `results` captured by the closure would be stale.
  const liveRowIds = useRef(new Set());
  const rowIdSeq = useRef(0);
  const newRowId = () => `r${++rowIdSeq.current}`;

  // A scanned name that does not match a student inside the control's
  // filière/année is simply "introuvable". Nothing is ever created from a
  // scan: the formateur can link the row by searching, or skip it, and an
  // admin adds any genuinely new student from the Students page.

  // Keep the scanned rows across client-side navigation (and a refresh), so a
  // half-done sheet is never lost by clicking away.
  //
  // Security: sessionStorage is per-tab, cleared when the tab closes, and is
  // never sent to the server. Only the parsed results and the target control are
  // stored — never the PDF itself, never credentials. The confirm step still
  // goes through the normal authenticated endpoint, so this grants no extra
  // access; a restored session can do exactly what the user could already do.
  const SCAN_KEY = 'scan_workflow_state';

  useEffect(() => {
    try {
      const raw = window.sessionStorage.getItem(SCAN_KEY);
      if (!raw) return;
      const saved = JSON.parse(raw);
      if (saved?.sel?.controle_id) setSel({ ...saved.sel, annee: saved.sel.annee ?? '', groupe_id: saved.sel.groupe_id ?? '' });
      // step 3 (results) and 4 (summary) are the ones worth restoring; going
      // back to 1/2 is harmless but the user may prefer to pick a control first.
      if (Array.isArray(saved.results) && saved.results.length) {
        // Sheets persisted before rowIds existed would all carry `undefined` and
        // collide, so any row without one gets a fresh id here.
        setResults(saved.results.map(r => (r.rowId ? r : { ...r, rowId: newRowId() })));
        // Restored alongside the rows, otherwise step 3 comes back as if work
        // were still pending when in fact every grade was already confirmed.
        setAllConfirmed(saved.allConfirmed === true);
        setStep(saved.step === 4 ? 4 : 3);
      }
    } catch {
      // Corrupt entry: ignore rather than block the scan.
      window.sessionStorage.removeItem(SCAN_KEY);
    }
  }, []);

  useEffect(() => {
    try {
      // Only persist once there is real work, so a plain visit leaves no trace.
      if (!results.length) {
        window.sessionStorage.removeItem(SCAN_KEY);
        return;
      }
      window.sessionStorage.setItem(SCAN_KEY, JSON.stringify({ sel, results, step, allConfirmed }));
    } catch {
      // Quota exceeded or storage disabled: the scan still works in memory.
    }
  }, [sel, results, step, allConfirmed]);

  useEffect(() => {
    liveRowIds.current = new Set(results.map(r => r.rowId));
  }, [results]);

  const clearScanWork = () => {
    try { window.sessionStorage.removeItem(SCAN_KEY); } catch { /* ignore */ }
    setResults([]);
    setPdfs([]);
    setPdfUrls(prev => {
      prev.forEach(url => URL.revokeObjectURL(url));
      return [];
    });
    setSavedSummary(null);
    setStep(1);
  };

  const skipStudent = (row) => {
    setResults(prev => prev.filter(r => r !== row));
    // Drop this row's search state too, otherwise it leaks onto the row that
    // takes the freed index.
    setSearchQueries(prev => { const n = { ...prev }; delete n[row.rowId]; return n; });
    setSearchMatches(prev => { const n = { ...prev }; delete n[row.rowId]; return n; });
    setSearchActiveRowId(cur => (cur === row.rowId ? null : cur));
    toast.info(`« ${row.nom} » ignoré.`);
  };

  useEffect(() => {
    const handleClick = () => setSearchActiveRowId(null);
    document.addEventListener('click', handleClick);
    return () => document.removeEventListener('click', handleClick);
  }, []);

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
      // Read the selection when the lookup runs, not when it was scheduled:
      // changing the control mid-debounce used to search the previous one.
      const current = selRef.current;
      try {
        // Suggestions are scoped to the control's périmètre (and the chosen group),
        // so a hand-linked row cannot pull in a student from a sibling cohort.
        const res = await formateursApi.searchEtudiants(value, {
          controle_id: current?.controle_id || undefined,
          groupe_id: current?.groupe_id || undefined,
        });
        const data = res.data.data || [];
        // The row may have been removed while the lookup was in flight; applying
        // a suggestion to it would then be a silent no-op at best.
        if (!liveRowIds.current.has(rowId)) return;
        // The dropdown is always shown, even for a single hit. Auto-linking a
        // lone partial match could grade the wrong student with no way for the
        // instructor to notice before confirming.
        setSearchMatches(prev => ({ ...prev, [rowId]: data }));
      } catch {
        setSearchMatches(prev => ({ ...prev, [rowId]: [] }));
      }
    }, 300);
  };

  const selectStudent = (rowId, student) => {
    setResults(prev => prev.map((r) =>
      r.rowId === rowId ? {
        ...r,
        etudiant_id: student.id,
        nom: student.nom_prenom,
        nom_ar: student.nom_ar || '',
        numero_inscription: student.numero_inscription,
        groupe: student.groupe?.nom || '',
        found: true
      } : r
    ));
    setSearchQueries(prev => { const n = { ...prev }; delete n[rowId]; return n; });
    setSearchMatches(prev => { const n = { ...prev }; delete n[rowId]; return n; });
    setSearchActiveRowId(cur => (cur === rowId ? null : cur));
  };

  // `toast` changes identity whenever a toast appears. Listing it in the initial
  // load effect would refetch the whole page each time a message was shown.
  const toastRef = useRef(toast);
  useEffect(() => {
    toastRef.current = toast;
  }, [toast]);

  // True when the group this sheet targets is already published. When no group
  // is chosen yet the perimeter-wide map is the best answer available.
  const groupeVerrouille = useCallback((controleId, groupeId) => {
    if (!controleId) return false;
    if (groupeId) {
      const locked = lockedGroupes[controleId];
      return Array.isArray(locked) && locked.includes(Number(groupeId));
    }
    return !!publicationMap[controleId];
  }, [lockedGroupes, publicationMap]);

  const selectionVerrouillee = useMemo(
    () => groupeVerrouille(sel.controle_id, sel.groupe_id),
    [groupeVerrouille, sel.controle_id, sel.groupe_id]
  );


  useEffect(() => {
    setLoading(true);
    setError(null);
    formateursApi.getScanData()
      .then(r => {
        const data = r.data.data;
        setFilieres(data.filieres || []);
        setSequences(data.sequences || []);
        setPublicationMap(data.publication_map || {});
        setLockedGroupes(data.publication_map_groupes || {});
        setGroupesParPerimetre(data.groupes_par_perimetre || {});

        // A restored session can outlive the control it targeted: the formateur
        // may have been unassigned, or the notes published meanwhile. Those
        // rows are unusable, so drop them rather than showing a step-3 screen
        // that cannot be saved. Done outside the setState updater so the
        // updater stays pure.
        const controleIds = (data.sequences || [])
          .flatMap(s => s.controles || [])
          .map(c => c.id);

        const groupsLocked = data.publication_map_groupes || {};
        const bloque = (controleId, groupeId) => {
          const locked = groupsLocked[controleId];
          return Array.isArray(locked)
            ? locked.includes(Number(groupeId))
            : !!data.publication_map?.[controleId];
        };

        setSel(prev => {
          if (!prev.controle_id) return prev;
          const existe = controleIds.some(id => String(id) === String(prev.controle_id));
          if (existe && !bloque(prev.controle_id, prev.groupe_id)) return prev;
          return { filiere_id: '', annee: '', sequence_id: '', controle_id: '', groupe_id: '' };
        });

        if (selRef.current?.controle_id) {
          const cur = selRef.current;
          const id = cur.controle_id;
          const existe = controleIds.some(cid => String(cid) === String(id));
          if (!existe || bloque(id, cur.groupe_id)) {
            setResults([]);
            setAllConfirmed(false);
            setStep(1);
          }
        }
      })
      .catch((err) => {
        const info = handleApiError(err, toastRef.current, { showToast: false });
        setError(info.message);
      })
      .finally(() => setLoading(false));
  }, [reloadKey]);

  // Preview URLs must be released on unmount. The load effect above closed over
  // the first render's (empty) list, so leaving the page leaked every blob.
  const pdfUrlsRef = useRef([]);
  useEffect(() => {
    pdfUrlsRef.current = pdfUrls;
  }, [pdfUrls]);

  useEffect(() => () => {
    pdfUrlsRef.current.forEach(url => URL.revokeObjectURL(url));
  }, []);

  // Distinct levels (numero_annee) a filière offers. The séquence list is scoped
  // to one of them so the instructor always knows which year they are grading:
  // an unfiltered list mixes 1ère and 2ème année under the same filière.
  const anneesDeFiliere = useCallback((filiereId) => {
    if (!filiereId) return [];
    const set = new Set(
      sequences
        .filter(s => String(s.unite?.filiere_id) === String(filiereId))
        .map(s => Number(s.unite?.numero_annee))
        .filter(Boolean)
    );
    return [...set].sort((a, b) => a - b);
  }, [sequences]);

  const anneesFiliere = useMemo(
    () => anneesDeFiliere(sel.filiere_id),
    [anneesDeFiliere, sel.filiere_id]
  );

  // A filière that only offers one year selects it automatically, and a restored
  // session adopts the year of its already-chosen séquence, so the list is never
  // empty for a reason the instructor cannot see.
  useEffect(() => {
    if (!sel.filiere_id) return;
    const years = anneesDeFiliere(sel.filiere_id);
    if (years.length === 1) {
      if (String(sel.annee) !== String(years[0])) setSel(p => ({ ...p, annee: String(years[0]) }));
      return;
    }
    if (!sel.annee && sel.sequence_id) {
      const seq = sequences.find(s => String(s.id) === String(sel.sequence_id));
      const year = Number(seq?.unite?.numero_annee);
      if (year) setSel(p => ({ ...p, annee: String(year) }));
    }
  }, [anneesDeFiliere, sequences, sel.filiere_id, sel.annee, sel.sequence_id]);

  const filteredSequences = sel.filiere_id
    ? sequences.filter(s =>
        String(s.unite?.filiere_id) === String(sel.filiere_id) &&
        (!sel.annee || String(s.unite?.numero_annee) === String(sel.annee)))
    : sequences;

  const filteredControles = sel.sequence_id
    ? (sequences.find(s => s.id == sel.sequence_id)?.controles || [])
    : [];

  // The scanned control's own scale. Hardcoding 20 let a 15/10 grade through the
  // input and the backend then rejected the whole confirmation.
  const noteMax = (filteredControles.find(c => String(c.id) === String(sel.controle_id))?.note_max) || 20;

  // Groups of the chosen control's filière/année. Matching is restricted to one
  // of these, so a sibling cohort's student can never absorb a scanned grade.
  const selectedUnite = sel.sequence_id
    ? sequences.find(s => s.id == sel.sequence_id)?.unite
    : null;

  const filteredGroupes = selectedUnite
    ? (groupesParPerimetre[`${selectedUnite.filiere_id}|${selectedUnite.numero_annee}`] || [])
    : [];

  // A control that maps to several groups needs an explicit choice; a single
  // group is unambiguous, so it is pre-selected.
  const requiresGroupChoice = filteredGroupes.length > 1;

  const handleFiles = (e) => {
    const files = Array.from(e.target.files);
    // Release the previews of the previous pick first: picking files again used
    // to leak one blob URL per selection.
    setPdfUrls(prev => {
      prev.forEach(url => URL.revokeObjectURL(url));
      return files.map(f => URL.createObjectURL(f));
    });
    setPdfs(files);
  };

  const handleScan = () => {
    if (pdfs.length === 0) {
      toast.warning('Veuillez sélectionner au moins un fichier PDF');
      return;
    }
    if (!sel.controle_id) {
      toast.warning('Veuillez sélectionner un contrôle');
      return;
    }
    if (requiresGroupChoice && !sel.groupe_id) {
      toast.warning('Veuillez sélectionner le groupe de cette feuille de notes');
      return;
    }

    if (selectionVerrouillee) {
      toast.warning('Les notes/bulletins ont déjà été publiés pour ce groupe. Scan impossible.');
      return;
    }

    // Rescanning used to drop the current table without warning, taking with it
    // every grade typed by hand and every row linked manually.
    if (results.length > 0) {
      setConfirmRescan(true);
      return;
    }

    runScan();
  };

  const runScan = async () => {
    setConfirmRescan(false);
    setScanning(true);
    setResults([]);

    try {
      const formData = new FormData();
      pdfs.forEach(pdf => formData.append('pdfs[]', pdf));
      formData.append('controle_id', sel.controle_id);
      // Narrow the matching to one cohort when the control maps to several.
      if (sel.groupe_id) formData.append('groupe_id', sel.groupe_id);

      const res = await scanApi.scan(formData);
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
        gemini_nom: r.gemini_nom || '',
        gemini_nom_ar: r.gemini_nom_ar || '',
        numero_inscription: r.numero_inscription || '',
        groupe: r.groupe || '',
        note: r.note,
        est_absent: !!r.est_absent,
        raw_note: r.raw_note ?? null,
        note_invalide: !!r.note_invalide,
        etudiant_id: r.etudiant_id || null,
        found: r.found || false,
        introuvable: r.introuvable ?? !(r.found || r.etudiant_id),
        already_confirmed: r.already_confirmed || false,
        readOnly: !!r.already_confirmed,
        confidence: r.found ? 'high' : 'low'
      }));

      const confirmable = scanned.filter(r => !r.readOnly && r.etudiant_id);
      const confirmedReadOnly = scanned.filter(r => r.readOnly);

      if (scanned.length === 0) {
        setAllConfirmed(false);
        setResults([]);
        toast.error('Aucun résultat trouvé. Vérifiez que le fichier PDF est lisible.');
        setStep(3);
        return;
      }

      if (confirmable.length === 0) {
        setAllConfirmed(true);
        setResults(scanned.map(r => ({ ...r, readOnly: true })));
        toast.info('Toutes les notes sont déjà confirmées. Affichage en lecture seule pour comparaison.');
        setStep(3);
        return;
      }

      setAllConfirmed(false);
      const toShow = [...confirmable, ...confirmedReadOnly];
      const unrecCount = confirmable.filter(r => !r.found).length;
      const absentCount = confirmable.filter(r => r.est_absent).length;
      const unreadableCount = confirmable.filter(r => r.note_invalide).length;
      let msg = `${confirmable.length} note(s) à confirmer`;
      if (confirmedReadOnly.length > 0) msg += `, ${confirmedReadOnly.length} déjà confirmé(e)(s) affiché(e)(s) pour comparaison`;
      if (unrecCount > 0) msg += `, ${unrecCount} non reconnue(s)`;
      if (absentCount > 0) msg += `, ${absentCount} absent(s)`;
      if (unreadableCount > 0) msg += `, ${unreadableCount} illisible(s)`;
      showSuccess(toast, msg);
      setResults(toShow);

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

  const updateResult = (rowId, key, val) => {
    setResults(prev => prev.map((r) => r.rowId === rowId ? { ...r, [key]: val } : r));
  };

  const removeResult = (rowId) => {
    setResults(prev => prev.filter((r) => r.rowId !== rowId));
    // Free the row's search state with the row, or it lands on whatever row
    // slides into the freed position.
    setSearchQueries(prev => { const n = { ...prev }; delete n[rowId]; return n; });
    setSearchMatches(prev => { const n = { ...prev }; delete n[rowId]; return n; });
    setSearchActiveRowId(cur => (cur === rowId ? null : cur));
    if (searchTimers.current[rowId]) {
      clearTimeout(searchTimers.current[rowId]);
      delete searchTimers.current[rowId];
    }
  };

  const handleNextFromResults = () => {
    const confirmable = results.filter(r => !r.readOnly && !r.already_confirmed);
    const unrec = confirmable.filter(r => !r.etudiant_id);
    if (unrec.length > 0) {
      toast.warning(`${unrec.length} étudiant(s) à confirmer sont introuvable(s). Reliez-les par recherche ou cliquez « Ignorer ».`);
      return;
    }
    const unreadable = confirmable.filter(r => r.note_invalide && !r.est_absent);
    if (unreadable.length > 0) {
      toast.warning(`${unreadable.length} note(s) à confirmer sont illisibles. Saisissez-les ou cochez « Absent » avant de continuer.`);
      return;
    }
    if (confirmable.length === 0) {
      toast.info('Aucune note à confirmer. Seuls les étudiants déjà confirmés sont affichés.');
      return;
    }
    setStep(4);
  };

  const handleConfirm = async () => {
    if (results.length === 0 || !sel.controle_id) return;

    const toConfirm = results.filter(r => !r.readOnly && !r.already_confirmed && r.etudiant_id);
    if (toConfirm.length === 0) {
      toast.info('Aucune note à confirmer. Seuls les étudiants déjà confirmés sont affichés.');
      return;
    }

    const missing = toConfirm.filter(r => !r.etudiant_id);
    if (missing.length > 0) {
      toast.warning(`${missing.length} étudiant(s) introuvable(s). Reliez-les ou ignorez-les avant d'enregistrer.`);
      return;
    }

    setSavingResults(true);
    try {
      const notes = toConfirm.map(r => ({
        etudiant_id: r.etudiant_id,
        controle_id: parseInt(sel.controle_id),
        valeur: r.est_absent ? null : r.note,
        est_absent: !!r.est_absent,
        gemini_nom: r.gemini_nom || null,
        gemini_nom_ar: r.gemini_nom_ar || null,
      }));

      // The chosen cohort is re-sent so the backend can re-check every row
      // against it, including any row linked by hand from the search box.
      const res = await scanApi.confirm({
        notes,
        groupe_id: sel.groupe_id ? parseInt(sel.groupe_id) : null,
      });
      const data = res.data;
      const savedCount = Array.isArray(data.data) ? data.data.length : notes.length;
      const savedAbsent = (data.data || []).filter(n => n.est_absent).length;

      let msg = data.message || `${savedCount} note(s) enregistrée(s)`;
      if (savedAbsent > 0) msg += ` — ${savedAbsent} absent(s) comptés à 0.`;
      if (data.already_confirmed?.length > 0) {
        const names = data.already_confirmed.map(r => r.nom_prenom).join(', ');
        msg += ` — ${data.already_confirmed.length} ignorée(s) (déjà confirmée(s)) : ${names}. Contactez l'admin pour les modifier.`;
        toast.warning(msg);
      } else {
        showSuccess(toast, msg);
      }

      // The backend is the source of truth for what was refused and why; the
      // local count would lump together rows it skipped for different reasons.
      const skippedRows = data.skipped || [];

      // Show what landed immediately instead of throwing the screen away.
      setSavedSummary({
        controle: (filteredControles.find(c => String(c.id) === String(sel.controle_id)) || {}).label
          || controlLabel({ numero: '?', type: '' }),
        count: savedCount,
        absent: savedAbsent,
        skipped: skippedRows.length,
        skippedReasons: [...new Set(skippedRows.map(s => s.raison).filter(Boolean))],
      });
      // Work is done: drop the stored copy so a later visit starts clean.
      try { window.sessionStorage.removeItem(SCAN_KEY); } catch { /* ignore */ }
      setResults([]);
      setPdfs([]);
      // Release the previews now the work is stored, like clearScanWork does;
      // dropping the list without revoking leaked one blob per picked PDF.
      setPdfUrls(prev => {
        prev.forEach(url => URL.revokeObjectURL(url));
        return [];
      });
      setStep(5);
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
            <button className="btn btn-sm btn-outline" onClick={() => setReloadKey(k => k + 1)} disabled={processing}>Réessayer</button>
          </div>
        </div>
      )}

      {/* Steps */}
      <div className="step-mobile">Étape {Math.min(step, 5)} sur 5 : {['Sélection', 'PDF', 'Résultats', 'Vérification', 'Confirmer'][Math.min(step, 5) - 1]}</div>
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
        <div className="card card-body scanner-card" style={{ maxWidth: 860 }}>
          <ScanStepHead
            n={1}
            title="Sélectionner le contrôle"
            subtitle="La feuille de notes scannée sera rapprochée uniquement des étudiants du groupe choisi."
          />
          {filieres.length === 0 && (
            <div className="alert alert-warning" style={{ marginBottom: 16 }}>
              <span><strong>Aucune filière trouvée.</strong> Veuillez contacter l'administrateur.</span>
            </div>
          )}
          <div className="smart-filter" style={{ marginBottom: 18 }}>
            <div className="sf-row">
              <FilterSelect
                label="Filière"
                value={sel.filiere_id}
                options={filieres.map(f => ({ id: f.id, label: f.nom }))}
                onChange={id => setSel(p => ({ ...p, filiere_id: id, annee: '', sequence_id: '', controle_id: '', groupe_id: '' }))}
                placeholder="Choisir une filière"
                allLabel="Choisir une filière"
                disabled={filieres.length === 0}
              />
              <FilterSelect
                label="Année"
                value={sel.annee}
                options={anneesFiliere.map(a => ({ id: a, label: formatNiveau(a) }))}
                onChange={id => setSel(p => ({ ...p, annee: id, sequence_id: '', controle_id: '', groupe_id: '' }))}
                placeholder="Choisir une année"
                allLabel="Choisir une année"
                disabled={!sel.filiere_id || anneesFiliere.length === 0}
              />
              <FilterSelect
                label="Séquence"
                value={sel.sequence_id}
                options={filteredSequences.map(s => ({ id: s.id, label: `${formatNiveau(s.unite?.numero_annee)} — ${s.unite?.nom} — ${s.nom}` }))}
                onChange={id => setSel(p => ({ ...p, sequence_id: id, controle_id: '', groupe_id: '' }))}
                placeholder="Choisir une séquence"
                allLabel="Choisir une séquence"
                disabled={!sel.filiere_id || (anneesFiliere.length > 0 && !sel.annee)}
              />
              <FilterSelect
                label="Contrôle"
                value={sel.controle_id}
                options={filteredControles.map(c => ({ id: c.id, label: controlLabel(c) }))}
                onChange={id => setSel(p => ({ ...p, controle_id: id, groupe_id: '' }))}
                placeholder="Choisir un contrôle"
                allLabel="Choisir un contrôle"
                disabled={!sel.sequence_id}
              />
              <FilterSelect
                label="Groupe"
                value={sel.groupe_id}
                options={filteredGroupes.map(g => ({ id: g.id, label: g.nom }))}
                onChange={id => setSel(p => ({ ...p, groupe_id: id }))}
                placeholder={requiresGroupChoice ? 'Groupe — choisir' : 'Choisir le groupe'}
                allLabel={requiresGroupChoice ? 'Groupe — choisir' : 'Choisir le groupe'}
                disabled={!sel.controle_id || filteredGroupes.length === 0}
              />
            </div>
          </div>

          {sel.controle_id && filteredGroupes.length === 1 && (
            <div className="alert alert-info" style={{ marginTop: 0, marginBottom: 16 }}>
              <span>
                Les notes ne seront rapprochées que des étudiants du groupe{' '}
                <strong>{filteredGroupes[0].nom}</strong>. Tout nom introuvable dans ce groupe
                reste marqué « Introuvable ».
              </span>
            </div>
          )}
          {sel.controle_id && requiresGroupChoice && !sel.groupe_id && (
            <div className="alert alert-warning" style={{ marginTop: 0, marginBottom: 16 }}>
              <span>
                <strong>Choisissez le groupe</strong> de cette feuille de notes : le rapprochement
                des noms se fait uniquement à l'intérieur du groupe choisi.
              </span>
            </div>
          )}

          {sel.controle_id && selectionVerrouillee && (
            <div className="alert alert-danger" style={{ marginTop: 0, marginBottom: 16 }}>
              <span>
                <strong>Période terminée.</strong> Les notes et/ou bulletins ont déjà été publiés pour ce groupe.
                Vous ne pouvez plus saisir ou modifier des notes. Contactez l'administrateur si nécessaire.
              </span>
            </div>
          )}

          <div className="scanner-btn-row" style={{ marginTop: 8 }}>
            <button
              className="btn btn-primary"
              disabled={processing || !sel.controle_id
                || (requiresGroupChoice && !sel.groupe_id)
                || selectionVerrouillee}
              onClick={() => setStep(2)}
            >
              Suivant →
            </button>
          </div>
        </div>
      )}

      {/* Step 2: Upload */}
      {step === 2 && (
        <div className="card card-body scanner-card" style={{ maxWidth: 1000 }}>
          <ScanStepHead
            n={2}
            title="Déposer les feuilles de notes"
            subtitle="Cliquez ou glissez les PDF à lire, puis lancez la reconnaissance."
          />
          <div className="upload-zone">
            <input type="file" accept=".pdf" multiple onChange={handleFiles} disabled={processing} />
            <div className="upload-zone-icon"><FiUploadCloud size={40} aria-hidden="true" /></div>
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
                    <div style={{ position: 'absolute', top: 6, right: 6, background: 'rgba(239,68,68,0.92)', color: '#fff', border: 'none', borderRadius: 6, width: 30, height: 30, display: 'flex', alignItems: 'center', justifyContent: 'center', cursor: processing ? 'not-allowed' : 'pointer', boxShadow: '0 2px 6px rgba(0,0,0,0.3)', opacity: processing ? 0.5 : 1 }} onClick={() => !processing && removePdf(i)}>
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
          <div className="scanner-btn-row">
            <button className="btn btn-outline" onClick={() => setStep(1)} disabled={processing}>Retour</button>
            <button className="btn btn-primary" disabled={processing || pdfs.length === 0} onClick={handleScan}>
              {scanning ? 'Lecture en cours...' : 'Scanner'}
            </button>
            {results.length > 0 && (
              <button className="btn btn-outline" onClick={clearScanWork} disabled={processing}>
                Repartir de zéro
              </button>
            )}
          </div>
        </div>
      )}

      {/* Step 3: Results */}
      {step === 3 && (
        <div style={{ maxWidth: 1400, width: '100%', margin: '0 auto' }}>
          <ScanStepHead
            n={3}
            title="Vérifier les résultats"
            subtitle="Contrôlez le rapprochement des noms et la note avant de confirmer. Seule la note est modifiable."
          />
          {results.length === 0 ? (
            <div className="text-center" style={{ padding: 40 }}>
              {allConfirmed ? (
                <>
                  <h4 style={{ color: 'var(--warning)', marginBottom: 12 }}>Toutes les notes existent deja</h4>
                  <p style={{ color: '#999', marginBottom: 24 }}>
                    Tous les etudiants scannes ont deja des notes confirmees pour ce controle.
                    Modifiez-les depuis la page <strong>Mes Notes</strong> ou selectionnez un autre controle.
                  </p>
                  <button className="btn btn-primary" onClick={() => { setStep(1); setAllConfirmed(false); }} disabled={processing}>
                    Choisir un autre controle
                  </button>
                </>
              ) : (
                <>
                  <h4 style={{ color: '#666', marginBottom: 12 }}>Aucune note detectee</h4>
                  <p style={{ color: '#999', marginBottom: 24 }}>
                    Verifiez le fichier PDF ou ressayer le scan.
                  </p>
                  <div className="scanner-btn-row" style={{ justifyContent: 'center' }}>
                    <button className="btn btn-primary" onClick={() => setStep(2)} disabled={processing}>
                      Retour aux fichiers
                    </button>
                    <button className="btn btn-outline" onClick={handleScan} disabled={processing}>
                      {scanning ? 'Scan en cours...' : 'Ressayer le scan'}
                    </button>
                  </div>
                </>
              )}
            </div>
          ) : (
            <div className="scanner-results-grid">
              <div>
                <h5 style={{ marginBottom: 8, fontWeight: 600 }}>Fichiers PDF uploadés</h5>
                <div className="scanner-pdf-list">
                  {pdfUrls.map((src, i) => (
                    <div key={i} style={{ position: 'relative', borderRadius: 8, overflow: 'hidden', border: '1px solid #e5e7eb', background: '#fff' }}>
                      <embed src={src} type="application/pdf" style={{ width: '100%', height: 220, display: 'block' }} />
                      <div style={{ position: 'absolute', top: 4, left: 4, background: 'var(--primary)', color: '#fff', borderRadius: '50%', width: 24, height: 24, display: 'flex', alignItems: 'center', justifyContent: 'center', fontSize: 11, fontWeight: 700 }}>
                        {i + 1}
                      </div>
                      <button
                        onClick={() => !processing && removePdf(i)}
                        disabled={processing}
                        style={{ position: 'absolute', top: 4, right: 4, background: 'rgba(239,68,68,0.9)', color: '#fff', border: 'none', borderRadius: 4, width: 28, height: 28, display: 'flex', alignItems: 'center', justifyContent: 'center', cursor: processing ? 'not-allowed' : 'pointer', fontSize: 16, opacity: processing ? 0.5 : 1 }}
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
                  <div style={{ padding: '10px 16px', background: '#f9fafb', fontSize: 13, color: '#374151', display: 'flex', gap: 16, flexWrap: 'wrap' }}>
                    <span><strong>{results.filter(r => !r.readOnly && !r.already_confirmed).length}</strong> à confirmer</span>
                    <span><strong>{results.filter(r => r.readOnly || r.already_confirmed).length}</strong> déjà confirmé(s) – vue seule</span>
                  </div>
                  <table style={{ fontSize: '15px', minWidth: '700px', width: '100%' }}>
                    <thead>
                      <tr style={{ backgroundColor: '#f9fafb' }}>
                        <th style={{ padding: '12px 16px', textAlign: 'left', fontWeight: 600, minWidth: '200px' }}>Nom</th>
                        <th style={{ padding: '12px 16px', textAlign: 'left', fontWeight: 600, minWidth: '120px' }}>N Inscription</th>
                        <th style={{ padding: '12px 16px', textAlign: 'left', fontWeight: 600, minWidth: '100px' }}>Groupe</th>
                        <th style={{ padding: '12px 16px', textAlign: 'left', fontWeight: 600, minWidth: '100px' }}>Note /{noteMax}</th>
                        <th style={{ padding: '12px 16px', textAlign: 'left', fontWeight: 600, minWidth: '90px' }}>Absence</th>
                        <th style={{ padding: '12px 16px', textAlign: 'left', fontWeight: 600, minWidth: '150px' }}>Statut</th>
                        <th style={{ padding: '12px 16px', textAlign: 'left', fontWeight: 600, minWidth: '170px' }}>Actions</th>
                      </tr>
                    </thead>
                    <tbody>
                      {results.map((r) => {
                        const isReadOnly = !!(r.readOnly || r.already_confirmed);
                        const isUnmatched = !r.etudiant_id && !isReadOnly;
                        return (
                        <tr key={r.rowId} style={{
                          borderBottom: '1px solid #e5e7eb',
                          background: isReadOnly ? '#f8f9fa' : isUnmatched ? '#fff7ed' : r.note_invalide && !r.est_absent ? '#fef2f2' : 'transparent',
                          opacity: isReadOnly ? 0.9 : 1
                        }}>
                          <td style={{ padding: '12px 16px' }}>
                            {isReadOnly ? (
                              <span style={{ padding: '8px 12px', display: 'block', color: '#6b7280' }}>{r.nom}</span>
                            ) : isUnmatched ? (
                              <input
                                className="form-input"
                                style={{ width: '100%', padding: '6px 10px', fontSize: '14px' }}
                                value={searchQueries[r.rowId] ?? r.nom}
                                onChange={e => handleSearchChange(r.rowId, e.target.value)}
                                placeholder="Rechercher un étudiant..."
                                disabled={processing}
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
                            {isReadOnly ? (
                              <span style={{ padding: '8px 12px', display: 'block', color: '#6b7280' }}>
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
                                max={noteMax}
                                step="0.25"
                                disabled={processing}
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
                            {r.etudiant_id && !isReadOnly && (
                              <label style={{ display: 'flex', alignItems: 'center', gap: 6, fontSize: 13, cursor: 'pointer' }}>
                                <input
                                  type="checkbox"
                                  checked={!!r.est_absent}
                                  disabled={processing}
                                  onChange={e => {
                                    const on = e.target.checked;
                                    setResults(prev => prev.map((row) => row.rowId === r.rowId
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
                            {isReadOnly ? (
                              <span style={{ color: 'var(--gray-500)', fontWeight: 600, fontSize: 13 }}>🔒 Déjà confirmé – Vue seule</span>
                            ) : r.etudiant_id ? (
                              <span style={{ color: 'var(--success)', fontWeight: 600, fontSize: 13 }}>✓ Reconnu</span>
                            ) : (
                              <span style={{ color: 'var(--warning)', fontWeight: 600, fontSize: 13 }}>Introuvable</span>
                            )}
                          </td>
                          <td style={{ padding: '12px 16px' }}>
                            {isReadOnly ? (
                              <span style={{ fontSize: 11, color: 'var(--gray-400)' }}>—</span>
                            ) : isUnmatched ? (
                              <div style={{ display: 'flex', flexDirection: 'column', gap: 6 }}>
                                <span style={{ fontSize: 11, color: 'var(--gray-500)', lineHeight: 1.4 }}>
                                  Reliez-le par recherche, sinon l'administrateur créera l'étudiant.
                                </span>
                                <button
                                  className="btn btn-sm btn-danger"
                                  disabled={processing}
                                  onClick={() => skipStudent(r)}
                                >
                                  Ignorer
                                </button>
                              </div>
                            ) : (
                              <button className="btn btn-sm btn-danger" onClick={() => removeResult(r.rowId)} disabled={processing}>Retirer</button>
                            )}
                          </td>
                        </tr>
                        );
                      })}
                    </tbody>
                  </table>
                </div>
                <div className="scanner-btn-row">
                  <button className="btn btn-outline" onClick={() => setStep(2)} disabled={processing}>← Retour</button>
                  <button className="btn btn-primary" onClick={handleNextFromResults} disabled={processing}>
                    {results.filter(r => !r.readOnly && !r.already_confirmed && r.etudiant_id).length > 0 ? 'Suivant →' : 'Terminé'}
                  </button>
                </div>
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
                    {s.numero_inscription || ''}{s.groupe?.nom ? ` — ${s.groupe.nom}` : ''}
                  </div>
                </div>
              ))}
            </div>
          )}
        </div>
      )}

      {/* Step 5: done */}
      {step === 5 && savedSummary && (
        <div className="card card-body scanner-card" style={{ maxWidth: 640, margin: '0 auto', textAlign: 'center' }}>
          <div style={{
            width: 56, height: 56, borderRadius: '50%', background: 'var(--success)', color: '#fff',
            display: 'flex', alignItems: 'center', justifyContent: 'center', fontSize: 28, margin: '0 auto 12px'
          }}>✓</div>
          <h5 style={{ marginBottom: 8 }}>Notes enregistrées</h5>
          <p style={{ color: 'var(--gray-500)', fontSize: 14 }}>
            <strong>{savedSummary.count}</strong> note(s) confirmée(s) pour <strong>{savedSummary.controle}</strong>.
          </p>
          {savedSummary.absent > 0 && (
            <p style={{ color: 'var(--gray-500)', fontSize: 14 }}>
              dont <strong>{savedSummary.absent}</strong> absent(s), comptés à 0.
            </p>
          )}
          {savedSummary.skipped > 0 && (
            <p style={{ color: 'var(--gray-500)', fontSize: 14 }}>
              {savedSummary.skipped} ligne(s) non enregistrée(s)
              {savedSummary.skippedReasons?.length > 0 && (
                <> — {savedSummary.skippedReasons.join(' · ')}</>
              )}
            </p>
          )}
          <div style={{ display: 'flex', gap: 8, justifyContent: 'center', marginTop: 16 }}>
            <button className="btn btn-primary" onClick={() => { clearScanWork(); setSavedSummary(null); }}>
              Scanner un autre contrôle
            </button>
          </div>
        </div>
      )}

      {/* Step 4: Review */}
      {step === 4 && (
        <div style={{ maxWidth: 860, margin: '0 auto' }}>
          <ScanStepHead
            n={4}
            title="Confirmation finale"
            subtitle="Cette action est définitive : aucune modification ne sera possible après l'enregistrement."
          />
          {results.filter(r => !r.readOnly && !r.already_confirmed && !r.etudiant_id).length > 0 && (
            <div className="alert alert-warning" style={{ marginBottom: 16 }}>
              <span><strong>Étudiants introuvables :</strong> certains étudiants à confirmer ne correspondent à aucun étudiant de cette
              filière/année. Reliez-les par recherche ou ignorez-les avant d'enregistrer.</span>
            </div>
          )}
          <div className="alert alert-warning" style={{ marginBottom: 16 }}>
            <span><strong>Confirmez pour enregistrer les notes</strong> — toute modification sera impossible après. Seuls les étudiants non confirmés seront enregistrés. Les autres restent en lecture seule.</span>
          </div>
          <div className="scanner-btn-row">
            <button className="btn btn-outline" onClick={() => setStep(3)} disabled={processing}>← Retour</button>
            <button className="btn btn-primary" style={{ minWidth: 220, justifyContent: 'center' }} disabled={processing || results.filter(r => !r.readOnly && !r.already_confirmed && r.etudiant_id).length === 0 || results.filter(r => !r.readOnly && !r.already_confirmed && !r.etudiant_id).length > 0} onClick={handleConfirm}>
              {savingResults ? (
                <>
                  <Spinner /> Enregistrement...
                </>
              ) : `Confirmer ${results.filter(r => !r.readOnly && !r.already_confirmed && r.etudiant_id).length} note(s)`}
            </button>
          </div>
        </div>
      )}

      <Modal open={confirmRescan} onClose={() => setConfirmRescan(false)} title="Remplacer les résultats">
        <p>
          Un nouveau scan remplace les {results.length} ligne(s) déjà affichées, y compris les notes saisies
          à la main. Continuer ?
        </p>
        <div className="modal-footer">
          <button className="btn btn-outline" onClick={() => setConfirmRescan(false)}>Annuler</button>
          <button className="btn btn-danger" onClick={runScan}>Remplacer</button>
        </div>
      </Modal>
    </div>
  );
}
