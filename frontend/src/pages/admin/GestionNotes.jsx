import { useState, useCallback, useEffect, useMemo, useRef } from 'react';
import { FiSearch, FiX } from 'react-icons/fi';
import { notesApi } from '../../api/notes';
import { stagesApi } from '../../api/stages';
import { etudiantsApi } from '../../api/etudiants';
import { unitesApi } from '../../api/unites';
import { sequencesApi } from '../../api/sequences';
import { publicationsApi } from '../../api/publications';
import { useToast } from '../../context/ToastContext';
import { useAnneeAcademique } from '../../context/AnneeAcademiqueContext';
import FiliereCascade from '../../components/common/FiliereCascade';
import FilterBar from '../../components/common/FilterBar';
import FilterSelect from '../../components/common/FilterSelect';
import Spinner from '../../components/common/Spinner';
import EmptyState from '../../components/common/EmptyState';
import { handleApiError, showSuccess } from '../../utils/errorHandler';
import usePersistedState from '../../hooks/usePersistedState';
import {
  controlLabel,
  noteDisplayStyle,
  estSemestrePublique,
  MESSAGE_SEMESTRE_PUBLIE,
} from '../../utils/helpers';
import '../../css/components.css';
import '../../css/layout.css';

/**
 * A Stage unit shows one column per service in both modes: the Évaluation note
 * on the controls screen and the Examen note on the exam screen. Both are
 * normally produced by the Stage page's grids but stay editable here; a grid
 * save replaces them. The Stage only reaches the averages once every service
 * carries both marks.
 */
export default function GestionNotes() {
  const [saved, setSaved] = usePersistedState('gestionNotes', {
    selected: {
      filiere_id: '',
      niveau_id: '',
      groupe_id: '',
      annee_academique_id: '',
    },
    selectedUniteId: '',
    selectedSequenceId: '',
    semestre: '',
    mode: 'controles',
  });
  const [selected, setSelected] = useState(() => saved.selected);
  const [unites, setUnites] = useState([]);
  const [selectedUniteId, setSelectedUniteId] = useState(() => saved.selectedUniteId);
  const [sequences, setSequences] = useState([]);
  const [selectedSequenceId, setSelectedSequenceId] = useState(() => saved.selectedSequenceId);
  const [semestre, setSemestre] = useState(() => saved.semestre || '');
  const [mode, setMode] = useState(() => saved.mode || 'controles');
  const [notesByControl, setNotesByControl] = useState({});
  const [roster, setRoster] = useState([]);
  const [examens, setExamens] = useState({}); // { theorique: { studentId: valeur }, pratique: { studentId: valeur } }
  const [stageExamens, setStageExamens] = useState({}); // Stage: { controleId: { studentId: row } } — one exam mark per service
  const [publications, setPublications] = useState([]);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState(null);
  const [editingNote, setEditingNote] = useState(null);
  const [editValue, setEditValue] = useState('');
  const [newNote, setNewNote] = useState(null);
  const [editingExam, setEditingExam] = useState(null);
  const [editingStageExam, setEditingStageExam] = useState(null);
  const [editExamValue, setEditExamValue] = useState('');
  const [editExamAbsent, setEditExamAbsent] = useState(false);
  const [saving, setSaving] = useState(false);
  const [filtersApplied, setFiltersApplied] = useState(false);
  const [searchTerm, setSearchTerm] = useState('');
  const toast = useToast();
  const { currentAnnee } = useAnneeAcademique();

  useEffect(() => {
    setSaved({ selected, selectedUniteId, selectedSequenceId, semestre, mode });
  }, [selected, selectedUniteId, selectedSequenceId, semestre, mode, setSaved]);

  useEffect(() => {
    if (!selected.filiere_id || !selected.niveau_id) {
      setUnites([]);
      setSelectedUniteId('');
      setSelectedSequenceId('');
      setSequences([]);
      setNotesByControl({});
      setExamens({});
      setStageExamens({});
      setRoster([]);
      return;
    }
    setLoading(true);
    setError(null);
    unitesApi.getUnites({
      filiere_id: selected.filiere_id,
      niveau_id: selected.niveau_id,
      ...(semestre ? { semestre } : {}),
    })
      .then(res => {
        const data = res.data.data || [];
        setUnites(data);
        setSelectedUniteId(cur => (data.some(u => String(u.id) === String(cur)) ? cur : ''));
      })
      .catch(err => {
        const info = handleApiError(err, toast, { showToast: false });
        setError(info.message);
      })
      .finally(() => setLoading(false));
  }, [selected.filiere_id, selected.niveau_id, semestre]);

  useEffect(() => {
    if (selectedUniteId) {
      setLoading(true);
      setError(null);
      sequencesApi.getSequences(selectedUniteId)
        .then(res => {
          const data = res.data.data || [];
          setSequences(data);
          setSelectedSequenceId(cur => (data.some(s => String(s.id) === String(cur)) ? cur : (data.length > 0 ? data[0].id : '')));
        })
        .catch(err => {
          const info = handleApiError(err, toast, { showToast: false });
          setError(info.message);
        })
        .finally(() => setLoading(false));
    } else {
      setSequences([]);
      setSelectedSequenceId('');
      setNotesByControl({});
    }
  }, [selectedUniteId]);

  const selectedUnite = useMemo(
    () => unites.find(u => String(u.id) === String(selectedUniteId)) || null,
    [unites, selectedUniteId]
  );

  // A stage unit has no sequence: its controls hang directly off the unit. The
  // grid falls back to those so the admin can still see the unit at all.
  const directControles = useMemo(
    () => [...(selectedUnite?.controles || [])]
      .sort((a, b) => (a.numero ?? 0) - (b.numero ?? 0)),
    [selectedUnite]
  );

  const isDirectUnit = sequences.length === 0 && directControles.length > 0;
  const isStageDirect = isDirectUnit && selectedUnite?.nom === 'Stage';
  const isSoutenanceDirect = isDirectUnit && selectedUnite?.nom === 'Soutenance';

  // The controls shown in the grid: the unit's own (a Stage's services / a
  // Soutenance's criteria) when it has no sequence, otherwise the selected
  // sequence's controls.
  const seqControles = useMemo(() => {
    if (isDirectUnit) return directControles;
    return [...(sequences.find(s => s.id === selectedSequenceId)?.controles || [])]
      .sort((a, b) => (a.numero ?? 0) - (b.numero ?? 0));
  }, [isDirectUnit, directControles, sequences, selectedSequenceId]);

  // A Stage unit owns many controls at once (up to 14). With `table { width:100% }`
  // the browser squeezes those columns instead of overflowing, so no horizontal
  // scrollbar ever appears. For these wide direct-unit grids (both the controls
  // and the exam view), force a minimum width so the wrapper's `overflow-x: auto`
  // can actually scroll.
  const wideGridMinWidth = useMemo(() => {
    if (!isDirectUnit) return undefined;
    const columns = seqControles.length + 1; // + the student-name column
    if (columns <= 6) return undefined;
    return `${seqControles.length * 116 + 220}px`;
  }, [isDirectUnit, seqControles.length]);

  const loadNotesForGrid = useCallback(async () => {
    const controles = isDirectUnit
      ? directControles
      : (sequences.find(s => s.id === selectedSequenceId)?.controles || []);
    if (controles.length === 0) return;

    setError(null);
    try {
      const params = isDirectUnit
        ? { unite_id: selectedUniteId, groupe_id: selected.groupe_id }
        : { sequence_id: selectedSequenceId, groupe_id: selected.groupe_id };
      const res = await notesApi.getNotes(params);
      const list = res.data?.data || [];
      const map = {};
      controles.forEach(c => { map[c.id] = list.filter(n => n.controle_id === c.id); });
      setNotesByControl(map);
    } catch (err) {
      const info = handleApiError(err, toast, { showToast: false });
      setError(info.message);
    }
  }, [isDirectUnit, directControles, sequences, selectedSequenceId, selectedUniteId, selected.groupe_id]);

  const loadRoster = useCallback(async () => {
    setError(null);
    try {
      const res = await etudiantsApi.getEtudiants({
        groupe_id: selected.groupe_id,
        annee_academique_id: selected.annee_academique_id || currentAnnee?.id,
      });
      setRoster(res.data?.data || []);
    } catch (err) {
      const info = handleApiError(err, toast, { showToast: false });
      setError(info.message);
    }
  }, [selected.groupe_id, selected.annee_academique_id]);

  const loadExamens = useCallback(async () => {
    setError(null);
    try {
      const unite = unites.find(u => u.id == selectedUniteId);
      const res = await notesApi.getExamens({
        groupe_id: selected.groupe_id,
        unite_id: selectedUniteId,
        bloc: unite?.semestre || 1,
        annee_academique_id: currentAnnee?.id,
      });
      const data = res.data?.data || [];
      const theoMap = {};
      const praMap = {};
      data.forEach(ex => {
        if (ex.type === 'theorique') theoMap[ex.etudiant_id] = ex;
        if (ex.type === 'pratique') praMap[ex.etudiant_id] = ex;
      });
      setExamens({ theorique: theoMap, pratique: praMap });
    } catch (err) {
      const info = handleApiError(err, toast, { showToast: false });
      setError(info.message);
    }
  }, [selectedUniteId, selected.groupe_id, unites]);

  // The Stage exam marks, keyed by service (control) then student, mirroring
  // notesByControl so the exam view can render one column per service.
  const loadStageExamens = useCallback(async () => {
    if (directControles.length === 0) {
      setStageExamens({});
      return;
    }
    setError(null);
    try {
      const res = await stagesApi.notes({
        unite_id: selectedUniteId,
        groupe_id: selected.groupe_id,
      });
      const data = res.data?.data || [];
      const map = {};
      directControles.forEach(c => { map[c.id] = {}; });
      data.forEach(ex => {
        if (map[ex.controle_id]) map[ex.controle_id][ex.etudiant_id] = ex;
      });
      setStageExamens(map);
    } catch (err) {
      const info = handleApiError(err, toast, { showToast: false });
      setError(info.message);
    }
  }, [directControles, selectedUniteId, selected.groupe_id]);

  const anneeCourante = currentAnnee?.id;

  const loadPublications = useCallback(async () => {
    try {
      const res = await publicationsApi.getPublications({
        groupe_id: selected.groupe_id,
        annee_academique_id: selected.annee_academique_id || anneeCourante,
      });
      setPublications(res.data?.data || []);
    } catch {
      // A failed lookup must not silently look like "published": clearing the
      // list leaves the UI open, and the server's 403 stays the backstop.
      setPublications([]);
    }
  }, [selected.groupe_id, selected.annee_academique_id, anneeCourante]);

  const handleLoad = useCallback(async () => {
    if (!selected.groupe_id) {
      toast.warning('Veuillez sélectionner un groupe');
      return;
    }
    setLoading(true);
    setError(null);
    setFiltersApplied(true);
    const promises = [];
    if (selectedSequenceId || isDirectUnit) promises.push(loadNotesForGrid());
    if (selectedUniteId && !isSoutenanceDirect) promises.push(isStageDirect ? loadStageExamens() : loadExamens());
    promises.push(loadRoster());
    promises.push(loadPublications());
    try {
      await Promise.all(promises);
    } finally {
      setLoading(false);
    }
  }, [selectedSequenceId, isDirectUnit, isStageDirect, selectedUniteId, selected.groupe_id, loadNotesForGrid, loadExamens, loadStageExamens, loadRoster, loadPublications]);

  const prevSelections = useRef({ groupe_id: '', unite_id: '', sequence_id: '' });

  useEffect(() => {
    const prev = prevSelections.current;
    prevSelections.current = { groupe_id: selected.groupe_id, unite_id: selectedUniteId, sequence_id: selectedSequenceId };

    if (!prev.groupe_id && !prev.unite_id && !prev.sequence_id) return;

    if (selected.groupe_id && selectedUniteId && (selectedSequenceId || isDirectUnit)) {
      handleLoad();
    }
  }, [selected.groupe_id, selectedUniteId, selectedSequenceId, isDirectUnit, handleLoad]);

  useEffect(() => {
    const onFocus = () => {
      if (selected.groupe_id && selectedUniteId && (selectedSequenceId || isDirectUnit) && filtersApplied) {
        handleLoad();
      }
    };
    window.addEventListener('focus', onFocus);
    return () => window.removeEventListener('focus', onFocus);
  }, [selected.groupe_id, selectedUniteId, selectedSequenceId, isDirectUnit, filtersApplied, handleLoad]);

  const patchNoteById = (noteId, valeur) => {
    setNotesByControl(prev => {
      let found = false;
      const next = {};
      Object.keys(prev).forEach(k => {
        next[k] = prev[k].map(n => {
          if (n.id === noteId) {
            found = true;
            return { ...n, valeur, est_absent: false };
          }
          return n;
        });
      });
      return found ? next : prev;
    });
  };

  const patchCreatedNote = (created) => {
    if (!created || !created.controle_id) return;
    setNotesByControl(prev => {
      const list = prev[created.controle_id] || [];
      if (list.some(n => n.id === created.id)) return prev;
      return { ...prev, [created.controle_id]: [...list, created] };
    });
  };

  const patchExamens = (type, etudiantId, row) => {
    setExamens(prev => ({ ...prev, [type]: { ...(prev[type] || {}), [etudiantId]: row } }));
  };

  const patchStageExamens = (controleId, etudiantId, row) => {
    setStageExamens(prev => ({
      ...prev,
      [controleId]: { ...(prev[controleId] || {}), [etudiantId]: row },
    }));
  };

  const handleSaveNote = async (noteId) => {
    if (editValue === '' || editValue === null) return;
    if (estVerrouille) {
      toast.warning(MESSAGE_SEMESTRE_PUBLIE);
      setEditingNote(null);
      setEditValue('');
      return;
    }
    setSaving(true);
    try {
      const valeur = parseFloat(editValue);
      await notesApi.updateNote(noteId, valeur);
      showSuccess(toast, 'Note enregistrée');
      patchNoteById(noteId, valeur);
      setEditingNote(null);
      setEditValue('');
    } catch (err) {
      handleApiError(err, toast);
    } finally {
      setSaving(false);
    }
  };

  const handleSaveNewNote = async () => {
    if (!newNote) return;
    // An empty value on blur means "never mind" rather than a zero.
    if (editValue === '' || editValue === null) {
      setNewNote(null);
      setEditValue('');
      return;
    }
    if (estVerrouille) {
      toast.warning(MESSAGE_SEMESTRE_PUBLIE);
      setNewNote(null);
      setEditValue('');
      return;
    }
    setSaving(true);
    try {
      const res = await notesApi.createNote({
        etudiant_id: newNote.etudiant_id,
        controle_id: newNote.controle_id,
        valeur: parseFloat(editValue),
      });
      showSuccess(toast, 'Note enregistrée');
      patchCreatedNote(res.data?.data);
      setNewNote(null);
      setEditValue('');
    } catch (err) {
      handleApiError(err, toast);
    } finally {
      setSaving(false);
    }
  };

  const handleSaveExam = async (type) => {
    if (!editingExam) return;
    if (!editExamAbsent && (editExamValue === '' || editExamValue === null)) return;
    const unite = unites.find(u => u.id == selectedUniteId);
    if (!unite) return;
    if (estVerrouille) {
      toast.warning(MESSAGE_SEMESTRE_PUBLIE);
      setEditingExam(null);
      setEditExamValue('');
      setEditExamAbsent(false);
      return;
    }
    setSaving(true);
    try {
      await notesApi.saveBulkExamens({
        examens: [{
          etudiant_id: editingExam.etudiant_id,
          valeur: editExamAbsent ? 0 : parseFloat(editExamValue),
          est_absent: editExamAbsent,
        }],
        unite_id: selectedUniteId,
        bloc: unite.semestre || 1,
        type: type,
        semestre: unite.semestre || 1,
        annee_academique_id: selected.annee_academique_id || currentAnnee?.id || 1,
      });
      showSuccess(toast, editExamAbsent
        ? `Absence d'examen ${type} enregistrée`
        : `Note d'examen ${type} enregistrée`);
      patchExamens(type, editingExam.etudiant_id, {
        etudiant_id: editingExam.etudiant_id,
        valeur: editExamAbsent ? 0 : parseFloat(editExamValue),
        est_absent: editExamAbsent,
      });
      setEditingExam(null);
      setEditExamValue('');
      setEditExamAbsent(false);
    } catch (err) {
      handleApiError(err, toast);
    } finally {
      setSaving(false);
    }
  };

  // Save one service's Examen note (its own column for a Stage unit).
  const handleSaveStageExam = async (controleId) => {
    if (!editingStageExam) return;
    if (!editExamAbsent && (editExamValue === '' || editExamValue === null)) return;
    if (estVerrouille) {
      toast.warning(MESSAGE_SEMESTRE_PUBLIE);
      setEditingStageExam(null);
      setEditExamValue('');
      setEditExamAbsent(false);
      return;
    }
    const valeur = editExamAbsent ? 0 : parseFloat(editExamValue);
    setSaving(true);
    try {
      await stagesApi.saveNote({
        etudiant_id: editingStageExam.etudiant_id,
        controle_id: controleId,
        valeur,
        est_absent: editExamAbsent,
        annee_academique_id: selected.annee_academique_id || currentAnnee?.id,
      });
      showSuccess(toast, editExamAbsent
        ? "Absence d'examen enregistrée"
        : "Note d'examen enregistrée");
      patchStageExamens(controleId, editingStageExam.etudiant_id, {
        etudiant_id: editingStageExam.etudiant_id,
        controle_id: controleId,
        valeur,
        est_absent: editExamAbsent,
      });
      setEditingStageExam(null);
      setEditExamValue('');
      setEditExamAbsent(false);
    } catch (err) {
      handleApiError(err, toast);
    } finally {
      setSaving(false);
    }
  };

  const etudiants = useMemo(() => {
    // The roster drives the rows. Notes alone cannot: a sequence that has never
    // been graded has no Note rows, which used to collapse the whole table to
    // an empty state and make the first grade impossible to enter.
    if (roster.length > 0) return roster;

    return [...new Map(
      Object.values(notesByControl).flat().map(n => [n.etudiant_id, n.etudiant])
    ).values()];
  }, [roster, notesByControl]);

  const filteredEtudiants = useMemo(() => {
    if (!searchTerm.trim()) return etudiants;
    const q = searchTerm.trim().toLowerCase();
    return etudiants.filter(e =>
      (e.nom_prenom?.toLowerCase().includes(q)) ||
      (e.cin?.toLowerCase().includes(q)) ||
      (e.numero_inscription?.toLowerCase().includes(q))
    );
  }, [etudiants, searchTerm]);

  // The unit decides the semester, so one flag covers the note cells and the
  // exam columns of this view. anneeId repeats the fallback chain the exam
  // save itself uses, otherwise the grid could show an open cell for a year the
  // server then treats as published.
  const anneeId = selected.annee_academique_id || anneeCourante;
  const semestreUnite = unites.find(u => u.id == selectedUniteId)?.semestre ?? 1;
  const estVerrouille = estSemestrePublique(publications, {
    groupeId: selected.groupe_id,
    anneeId,
    semestre: semestreUnite,
  });

  return (
    <div className="page">
      <FilterBar
        showSearch={false}
        value={{
          filiere_id: selected.filiere_id,
          niveau_id: selected.niveau_id,
          groupe_id: selected.groupe_id,
          unite_id: selectedUniteId,
          sequence_id: selectedSequenceId,
        }}
        onChange={() => {}}
        onClear={() => {
          setSelected({ filiere_id: '', niveau_id: '', groupe_id: '', annee_academique_id: selected.annee_academique_id });
          setSelectedUniteId('');
          setSelectedSequenceId('');
          setSequences([]);
          setNotesByControl({});
          setExamens({});
          setStageExamens({});
          setRoster([]);
          setFiltersApplied(false);
        }}
        actions={(
          <>
            {selectedUnite && selectedUnite.nom !== 'Soutenance' && (
              <button
                className="btn btn-outline"
                onClick={() => setMode(m => m === 'controles' ? 'examens' : 'controles')}
                disabled={!selected.groupe_id}
              >
                {mode === 'controles' ? "Saisie examens de l'unité" : 'Saisie des contrôles'}
              </button>
            )}
            <button className="btn btn-primary" onClick={handleLoad} disabled={!selected.groupe_id}>
              Afficher
            </button>
            {!selected.groupe_id && selected.filiere_id && (
              <span className="alert alert-warning" style={{ margin: 0 }}>
                Sélectionnez un groupe
              </span>
            )}
          </>
        )}
      >
        <FiliereCascade selected={selected} onChange={setSelected} />
        <FilterSelect
          label="Semestre"
          value={semestre}
          options={[
            { id: '1', label: 'S1' },
            { id: '2', label: 'S2' },
          ]}
          onChange={(v) => {
            setSemestre(v);
            setSelectedUniteId('');
            setSelectedSequenceId('');
            setSequences([]);
            setNotesByControl({});
            setExamens({});
            setStageExamens({});
            setRoster([]);
            setFiltersApplied(false);
          }}
          disabled={!selected.niveau_id}
          placeholder="S1 & S2"
          allLabel="S1 & S2"
        />
        <FilterSelect
          label="Unité"
          value={selectedUniteId}
          options={unites.map(u => ({ id: u.id, label: u.nom }))}
          onChange={(v) => {
            setSelectedUniteId(v);
            setSelectedSequenceId('');
            setSequences([]);
            setNotesByControl({});
            setExamens({});
            setStageExamens({});
            setRoster([]);
            setFiltersApplied(false);
          }}
          disabled={!selected.niveau_id || unites.length === 0}
          placeholder="Toutes les unités"
          allLabel="Toutes les unités"
        />
        {mode === 'controles' && !isDirectUnit && (
          <FilterSelect
            label="Séquence"
            value={selectedSequenceId}
            options={sequences.map(s => ({ id: s.id, label: `${s.nom} (Coef: ${s.coefficient})` }))}
            onChange={(v) => {
              setSelectedSequenceId(v);
              setNotesByControl({});
              setFiltersApplied(false);
            }}
            disabled={!selectedUniteId || sequences.length === 0}
            placeholder="Toutes les séquences"
            allLabel="Toutes les séquences"
          />
        )}
      </FilterBar>

      {error && <div className="alert alert-error">{error}</div>}

      {loading && <Spinner />}

      {selected.filiere_id && !selected.niveau_id && !loading && (
        <EmptyState message="Sélectionnez un niveau (année) pour afficher les unités." />
      )}

      {selected.filiere_id && selected.niveau_id && unites.length === 0 && !loading && (
        <EmptyState message="Aucune unité trouvée pour cette année." />
      )}

      {!selected.filiere_id && !loading && (
        <EmptyState message="Sélectionnez une filière, un niveau et un groupe pour commencer." />
      )}

      {filtersApplied && etudiants.length > 0 && !loading && (
        <div className="card">
          <div className="card-header">
            <h5 className="mb-0">
              Notes — {mode === 'examens'
                ? (selectedUnite?.nom || "Examens de l'unité")
                : (isDirectUnit
                  ? (selectedUnite?.nom || '')
                  : (sequences.find(s => s.id === selectedSequenceId)?.nom || ''))}
            </h5>
          </div>
          <div className="card-body">
            {estVerrouille && (
              <div className="alert alert-warning" style={{ marginBottom: 12 }}>
                {MESSAGE_SEMESTRE_PUBLIE}
              </div>
            )}
            <div className="search-input-wrapper" style={{ position: 'relative', marginBottom: 12, maxWidth: 400 }}>
              <FiSearch style={{ position: 'absolute', left: 10, top: '50%', transform: 'translateY(-50%)', color: '#999' }} />
              <input
                type="text"
                className="form-input"
                placeholder="Rechercher par nom, CIN ou N° inscription..."
                value={searchTerm}
                onChange={e => setSearchTerm(e.target.value)}
                style={{ paddingLeft: 32, paddingRight: 30, width: '100%' }}
              />
              {searchTerm && (
                <FiX
                  style={{ position: 'absolute', right: 10, top: '50%', transform: 'translateY(-50%)', cursor: 'pointer', color: '#999' }}
                  onClick={() => setSearchTerm('')}
                />
              )}
            </div>
            {searchTerm && (
              <p style={{ marginBottom: 8, fontSize: 13, color: '#666' }}>
                {filteredEtudiants.length} étudiant(s) trouvé(s)
              </p>
            )}
            <div className="table-wrap" style={{ overflowX: 'auto' }}>
              <table className="table mb-0" style={wideGridMinWidth ? { minWidth: wideGridMinWidth } : undefined}>
                <thead>
                  <tr>
                    <th>Étudiant</th>
                    {mode === 'examens' ? (
                      isStageDirect ? (
                        seqControles.map(ctrl => (
                          <th key={ctrl.id}>{controlLabel(ctrl)}</th>
                        ))
                      ) : (isSoutenanceDirect ? (
                        seqControles.map(ctrl => (
                          <th key={ctrl.id}>{controlLabel(ctrl)}</th>
                        ))
                      ) : (
                        <>
                          <th>Examen théorique</th>
                          <th>Examen pratique</th>
                        </>
                      ))
                    ) : (
                      seqControles.map(ctrl => (
                        <th key={ctrl.id}>{controlLabel(ctrl)}</th>
                      ))
                    )}
                  </tr>
                </thead>
                <tbody>
                  {filteredEtudiants.map((etudiant) => {
                    const seq = sequences.find(s => s.id === selectedSequenceId);
                    const ctrlCells = seqControles.map(ctrl => {
                      const note = (notesByControl[ctrl.id] || []).find(n => n.etudiant_id === etudiant.id);
                      return { ctrl, note };
                    });
                    const theoEx = examens.theorique?.[etudiant.id];
                    const praEx = examens.pratique?.[etudiant.id];

                    return (
                      <tr key={etudiant.id}>
                        <td>
                          <strong>{etudiant.nom_prenom}</strong>
                        </td>
                        {mode !== 'examens' && ctrlCells.map(({ ctrl, note }) => (
                          <td key={ctrl?.id}>
                            {estVerrouille ? (
                              <span
                                style={noteDisplayStyle(note?.est_absent)}
                                title={MESSAGE_SEMESTRE_PUBLIE}
                              >
                                {note ? (note.est_absent ? 'Absent' : (note.valeur ?? '—')) : '—'}
                              </span>
                            ) : newNote?.controle_id === ctrl?.id && newNote?.etudiant_id === etudiant.id ? (
                              <input
                                type="number"
                                className="form-input form-input-sm"
                                value={editValue}
                                min="0"
                                max={ctrl?.note_max || 20}
                                step="0.25"
                                style={{ width: '72px' }}
                                onChange={e => setEditValue(e.target.value)}
                                onBlur={handleSaveNewNote}
                                onKeyDown={e => { if (e.key === 'Enter') handleSaveNewNote(); }}
                                disabled={saving}
                                autoFocus
                              />
                            ) : note ? (
                              editingNote === note.id ? (
                                <input
                                  type="number"
                                  className="form-input form-input-sm"
                                  value={editValue}
                                  min="0"
                                  max="20"
                                  step="0.25"
                                  style={{ width: '72px' }}
                                  onChange={e => setEditValue(e.target.value)}
                                  onBlur={() => handleSaveNote(note.id)}
                                  onKeyDown={e => { if (e.key === 'Enter') handleSaveNote(note.id); }}
                                  disabled={saving}
                                  autoFocus
                                />
                              ) : note.est_absent ? (
                                <span
                                  style={{ ...noteDisplayStyle(), cursor: 'pointer' }}
                                  onClick={() => {
                                    setEditingNote(note.id);
                                    setEditValue('');
                                  }}
                                  title="Absent — cliquer pour corriger"
                                >
                                  Absent
                                </span>
                              ) : (
                                <span
                                  style={{ cursor: 'pointer' }}
                                  onClick={() => {
                                    setEditingNote(note.id);
                                    setEditValue(note.valeur ?? '');
                                  }}
                                  title="Cliquer pour modifier"
                                >
                                  {note.valeur ?? '—'}
                                </span>
                              )
                            ) : (
                              <span
                                style={{ color: '#b45309', background: '#fffbeb', outline: '1px dashed #f59e0b', cursor: 'pointer' }}
                                onClick={() => {
                                  setNewNote({ etudiant_id: etudiant.id, controle_id: ctrl?.id });
                                  setEditValue('');
                                }}
                                title="Note manquante — cliquer pour saisir"
                              >
                                —
                              </span>
                            )}
                          </td>
                        ))}
                        {mode === 'examens' && isStageDirect && seqControles.map(ctrl => {
                          const ex = stageExamens[ctrl?.id]?.[etudiant.id];
                          const editing = editingStageExam?.etudiant_id === etudiant.id
                            && editingStageExam?.controle_id === ctrl?.id;
                          return (
                            <td key={ctrl?.id}>
                              {estVerrouille ? (
                                <span style={noteDisplayStyle(ex?.est_absent)} title={MESSAGE_SEMESTRE_PUBLIE}>
                                  {ex ? (ex.est_absent ? 'Absent' : (ex.valeur ?? '—')) : '—'}
                                </span>
                              ) : editing ? (
                                <>
                                  <input
                                    type="number"
                                    className="form-input form-input-sm"
                                    value={editExamAbsent ? '' : editExamValue}
                                    min="0"
                                    max="20"
                                    step="0.01"
                                    style={{ width: '72px' }}
                                    onChange={e => setEditExamValue(e.target.value)}
                                    onBlur={() => handleSaveStageExam(ctrl.id)}
                                    onKeyDown={e => { if (e.key === 'Enter') handleSaveStageExam(ctrl.id); }}
                                    disabled={saving || editExamAbsent}
                                    autoFocus
                                  />
                                  <label
                                    title="Marquer comme absent"
                                    onMouseDown={e => e.preventDefault()}
                                    style={{ display: 'inline-flex', alignItems: 'center', gap: 3, marginLeft: 4, fontSize: 11, color: '#666', cursor: 'pointer' }}
                                  >
                                    <input
                                      type="checkbox"
                                      checked={editExamAbsent}
                                      disabled={saving}
                                      onChange={e => {
                                        const next = e.target.checked;
                                        setEditExamAbsent(next);
                                        setEditExamValue(next ? '' : (ex?.valeur ?? ''));
                                      }}
                                    />
                                    Abs.
                                  </label>
                                </>
                              ) : ex ? (
                                ex.est_absent ? (
                                  <span
                                    style={{ ...noteDisplayStyle(), cursor: 'pointer' }}
                                    onClick={() => {
                                      setEditingStageExam({ etudiant_id: etudiant.id, controle_id: ctrl.id });
                                      setEditExamValue('');
                                      setEditExamAbsent(true);
                                    }}
                                    title="Absent — cliquer pour corriger"
                                  >
                                    Absent
                                  </span>
                                ) : (
                                  <span
                                    style={{ cursor: 'pointer' }}
                                    onClick={() => {
                                      setEditingStageExam({ etudiant_id: etudiant.id, controle_id: ctrl.id });
                                      setEditExamValue(ex.valeur ?? '');
                                      setEditExamAbsent(false);
                                    }}
                                    title="Cliquer pour modifier"
                                  >
                                    {ex.valeur ?? '—'}
                                  </span>
                                )
                              ) : (
                                <span
                                  style={{ color: '#ccc', cursor: 'pointer' }}
                                  onClick={() => {
                                    setEditingStageExam({ etudiant_id: etudiant.id, controle_id: ctrl.id });
                                    setEditExamValue('');
                                    setEditExamAbsent(false);
                                  }}
                                  title="Aucun examen — cliquer pour saisir"
                                >
                                  —
                                </span>
                              )}
                            </td>
                          );
                        })}
                        {mode === 'examens' && !isStageDirect && !isSoutenanceDirect && (
                          <td>
                            {estVerrouille ? (
                              <span
                                style={noteDisplayStyle(theoEx?.est_absent)}
                                title={MESSAGE_SEMESTRE_PUBLIE}
                              >
                                {theoEx ? (theoEx.est_absent ? 'Absent' : (theoEx.valeur ?? '—')) : '—'}
                              </span>
                            ) : editingExam?.etudiant_id === etudiant.id && editingExam?.type === 'theorique' ? (
                              <>
                                <input
                                  type="number"
                                  className="form-input form-input-sm"
                                  value={editExamAbsent ? '' : editExamValue}
                                  min="0"
                                  max="20"
                                  step="0.25"
                                  style={{ width: '72px' }}
                                  onChange={e => setEditExamValue(e.target.value)}
                                  onBlur={() => handleSaveExam('theorique')}
                                  onKeyDown={e => { if (e.key === 'Enter') handleSaveExam('theorique'); }}
                                  disabled={saving || editExamAbsent}
                                  autoFocus
                                />
                                <label
                                  title="Marquer comme absent"
                                  onMouseDown={e => e.preventDefault()}
                                  style={{ display: 'inline-flex', alignItems: 'center', gap: 3, marginLeft: 4, fontSize: 11, color: '#666', cursor: 'pointer' }}
                                >
                                  <input
                                    type="checkbox"
                                    checked={editExamAbsent}
                                    disabled={saving}
                                    onChange={e => {
                                      const next = e.target.checked;
                                      setEditExamAbsent(next);
                                      setEditExamValue(next ? '' : (theoEx?.valeur ?? ''));
                                    }}
                                  />
                                  Abs.
                                </label>
                              </>
                            ) : theoEx ? (
                              theoEx.est_absent ? (
                                <span
                                  style={{ ...noteDisplayStyle(), cursor: 'pointer' }}
                                  onClick={() => {
                                    setEditingExam({ etudiant_id: etudiant.id, type: 'theorique' });
                                    setEditExamValue('');
                                    setEditExamAbsent(true);
                                  }}
                                  title="Absent — cliquer pour corriger"
                                >
                                  Absent
                                </span>
                              ) : (
                                <span
                                  style={{ cursor: 'pointer' }}
                                  onClick={() => {
                                    setEditingExam({ etudiant_id: etudiant.id, type: 'theorique' });
                                    setEditExamValue(theoEx.valeur ?? '');
                                    setEditExamAbsent(false);
                                  }}
                                >
                                  {theoEx.valeur ?? '—'}
                                </span>
                              )
                            ) : (
                              <span
                                style={{ color: '#ccc', cursor: 'pointer' }}
                                onClick={() => {
                                  setEditingExam({ etudiant_id: etudiant.id, type: 'theorique' });
                                  setEditExamValue('');
                                  setEditExamAbsent(false);
                                }}
                                title="Aucun examen — cliquer pour saisir"
                              >
                                —
                              </span>
                            )}
                          </td>
                        )}
                        {mode === 'examens' && !isStageDirect && !isSoutenanceDirect && (
                          <td>
                            {estVerrouille ? (
                              <span
                                style={noteDisplayStyle(praEx?.est_absent)}
                                title={MESSAGE_SEMESTRE_PUBLIE}
                              >
                                {praEx ? (praEx.est_absent ? 'Absent' : (praEx.valeur ?? '—')) : '—'}
                              </span>
                            ) : editingExam?.etudiant_id === etudiant.id && editingExam?.type === 'pratique' ? (
                              <>
                                <input
                                  type="number"
                                  className="form-input form-input-sm"
                                  value={editExamAbsent ? '' : editExamValue}
                                  min="0"
                                  max="20"
                                  step="0.25"
                                  style={{ width: '72px' }}
                                  onChange={e => setEditExamValue(e.target.value)}
                                  onBlur={() => handleSaveExam('pratique')}
                                  onKeyDown={e => { if (e.key === 'Enter') handleSaveExam('pratique'); }}
                                  disabled={saving || editExamAbsent}
                                  autoFocus
                                />
                                <label
                                  title="Marquer comme absent"
                                  onMouseDown={e => e.preventDefault()}
                                  style={{ display: 'inline-flex', alignItems: 'center', gap: 3, marginLeft: 4, fontSize: 11, color: '#666', cursor: 'pointer' }}
                                >
                                  <input
                                    type="checkbox"
                                    checked={editExamAbsent}
                                    disabled={saving}
                                    onChange={e => {
                                      const next = e.target.checked;
                                      setEditExamAbsent(next);
                                      setEditExamValue(next ? '' : (praEx?.valeur ?? ''));
                                    }}
                                  />
                                  Abs.
                                </label>
                              </>
                            ) : praEx ? (
                              praEx.est_absent ? (
                                <span
                                  style={{ ...noteDisplayStyle(), cursor: 'pointer' }}
                                  onClick={() => {
                                    setEditingExam({ etudiant_id: etudiant.id, type: 'pratique' });
                                    setEditExamValue('');
                                    setEditExamAbsent(true);
                                  }}
                                  title="Absent — cliquer pour corriger"
                                >
                                  Absent
                                </span>
                              ) : (
                                <span
                                  style={{ cursor: 'pointer' }}
                                  onClick={() => {
                                    setEditingExam({ etudiant_id: etudiant.id, type: 'pratique' });
                                    setEditExamValue(praEx.valeur ?? '');
                                    setEditExamAbsent(false);
                                  }}
                                >
                                  {praEx.valeur ?? '—'}
                                </span>
                              )
                            ) : (
                              <span
                                style={{ color: '#ccc', cursor: 'pointer' }}
                                onClick={() => {
                                  setEditingExam({ etudiant_id: etudiant.id, type: 'pratique' });
                                  setEditExamValue('');
                                  setEditExamAbsent(false);
                                }}
                                title="Aucun examen — cliquer pour saisir"
                              >
                                —
                              </span>
                            )}
                          </td>
                        )}
                      </tr>
                    );
                  })}
                </tbody>
              </table>
            </div>
          </div>
        </div>
      )}

      {filtersApplied && etudiants.length === 0 && !loading && selected.groupe_id && (
        <EmptyState message="Aucun étudiant trouvé pour ce groupe." />
      )}
    </div>
  );
}
