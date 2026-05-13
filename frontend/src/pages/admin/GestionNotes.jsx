import { useState, useCallback, useEffect } from 'react';
import { notesApi } from '../../api/notes';
import { unitesApi } from '../../api/unites';
import { sequencesApi } from '../../api/sequences';
import { useToast } from '../../context/ToastContext';
import { useAnneeAcademique } from '../../context/AnneeAcademiqueContext';
import FiliereCascade from '../../components/common/FiliereCascade';
import Spinner from '../../components/common/Spinner';
import EmptyState from '../../components/common/EmptyState';
import { handleApiError, showSuccess } from '../../utils/errorHandler';
import '../../css/components.css';
import '../../css/layout.css';

export default function GestionNotes() {
  const [selected, setSelected] = useState({
    filiere_id: '',
    niveau_id: '',
    groupe_id: '',
    annee_academique_id: '',
  });
  const [unites, setUnites] = useState([]);
  const [selectedUniteId, setSelectedUniteId] = useState('');
  const [sequences, setSequences] = useState([]);
  const [selectedSequenceId, setSelectedSequenceId] = useState('');
  const [allControlNums, setAllControlNums] = useState([]);
  const [notesByControl, setNotesByControl] = useState({});
  const [examens, setExamens] = useState({}); // { theorique: { studentId: valeur }, pratique: { studentId: valeur } }
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState(null);
  const [editingNote, setEditingNote] = useState(null);
  const [editValue, setEditValue] = useState('');
  const [editingExam, setEditingExam] = useState(null); // { etudiant_id, type }
  const [examenBloc, setExamenBloc] = useState(1);
  const [editExamValue, setEditExamValue] = useState('');
  const [saving, setSaving] = useState(false);
  const toast = useToast();
  const { currentAnnee } = useAnneeAcademique();

  useEffect(() => {
    if (selected.filiere_id) {
      setLoading(true);
      setError(null);
      unitesApi.getUnites({ filiere_id: selected.filiere_id })
        .then(res => setUnites(res.data.data || []))
        .catch(err => {
          const info = handleApiError(err, toast, { showToast: false });
          setError(info.message);
        })
        .finally(() => setLoading(false));
      setSelectedUniteId('');
      setSelectedSequenceId('');
      setSequences([]);
      setNotesByControl({});
      setExamens({});
    } else {
      setUnites([]);
      setSelectedUniteId('');
      setSelectedSequenceId('');
      setSequences([]);
      setNotesByControl({});
      setExamens({});
    }
  }, [selected.filiere_id]);

  useEffect(() => {
    if (selectedUniteId) {
      setLoading(true);
      setError(null);
      sequencesApi.getSequences(selectedUniteId)
        .then(res => {
          const data = res.data.data || [];
          setSequences(data);
          const nums = [...new Set(data.flatMap(s => (s.controles || []).map(c => c.numero)))].sort((a, b) => a - b);
          setAllControlNums(nums);
          if (data.length > 0) setSelectedSequenceId(data[0].id);
          else setSelectedSequenceId('');
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

  useEffect(() => {
    if (selectedSequenceId && selected.groupe_id) {
      loadNotesForSequence();
    } else {
      setNotesByControl({});
    }
  }, [selectedSequenceId, selected.groupe_id]);

  useEffect(() => {
    if (selectedUniteId && selected.groupe_id) {
      loadExamens();
    } else {
      setExamens({});
    }
  }, [selectedUniteId, selected.groupe_id]);

  const loadNotesForSequence = useCallback(async () => {
    const seq = sequences.find(s => s.id === selectedSequenceId);
    if (!seq) return;
    const ctrlIds = seq.controles?.map(c => c.id) || [];
    if (ctrlIds.length === 0) return;

    setLoading(true);
    setError(null);
    try {
      const responses = await Promise.all(
        ctrlIds.map(id => notesApi.getNotes({ controle_id: id, groupe_id: selected.groupe_id }))
      );
      const map = {};
      responses.forEach((res, idx) => {
        map[ctrlIds[idx]] = res.data?.data || [];
      });
      setNotesByControl(map);
    } catch (err) {
      const info = handleApiError(err, toast, { showToast: false });
      setError(info.message);
    } finally {
      setLoading(false);
    }
  }, [selectedSequenceId, selected.groupe_id, sequences]);

  const loadExamens = useCallback(async () => {
    setError(null);
    try {
      const res = await notesApi.getExamens({ groupe_id: selected.groupe_id, unite_id: selectedUniteId });
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
  }, [selectedUniteId, selected.groupe_id]);

  const handleSaveNote = async (noteId) => {
    if (editValue === '' || editValue === null) return;
    setSaving(true);
    try {
      await notesApi.updateNote(noteId, parseFloat(editValue));
      showSuccess(toast, 'Note enregistrée');
      setEditingNote(null);
      setEditValue('');
      if (selectedSequenceId && selected.groupe_id) {
        await loadNotesForSequence();
      }
    } catch (err) {
      handleApiError(err, toast);
    } finally {
      setSaving(false);
    }
  };

  const handleSaveExam = async (type) => {
    if (!editingExam || editExamValue === '' || editExamValue === null) return;
    const unite = unites.find(u => u.id == selectedUniteId);
    if (!unite) return;
    setSaving(true);
    try {
      await notesApi.saveBulkExamens({
        examens: [{ etudiant_id: editingExam.etudiant_id, valeur: parseFloat(editExamValue) }],
        unite_id: selectedUniteId,
        bloc: examenBloc,
        type: type,
        semestre: unite.semestre || 1,
        annee_academique_id: selected.annee_academique_id || currentAnnee?.id || 1,
      });
      showSuccess(toast, `Note d'examen ${type} enregistrée`);
      setEditingExam(null);
      setEditExamValue('');
      await loadExamens();
    } catch (err) {
      handleApiError(err, toast);
    } finally {
      setSaving(false);
    }
  };

  const etudiants = [...new Map(
    Object.values(notesByControl).flat().map(n => [n.etudiant_id, n.etudiant])
  ).values()];

  const showExamCols = selectedUniteId && selected.groupe_id;

  return (
    <div className="page">
      <div className="page-header">
        <h2 className="page-title">Saisie des Notes</h2>
      </div>

      <FiliereCascade selected={selected} onChange={setSelected} />

      {error && <div className="alert alert-error">{error}</div>}

      {loading && <Spinner />}

      {selected.filiere_id && unites.length > 0 && !loading && (
        <div className="filter-bar">
          <select
            className="form-select"
            value={selectedUniteId}
            onChange={e => setSelectedUniteId(e.target.value)}
          >
            <option value="">Toutes les unités</option>
            {unites.map(u => (
              <option key={u.id} value={u.id}>{u.nom}</option>
            ))}
          </select>
          <select
            className="form-select"
            value={selectedSequenceId}
            onChange={e => setSelectedSequenceId(e.target.value)}
            disabled={!selectedUniteId || sequences.length === 0}
          >
            {sequences.length === 0 ? (
              <option value="">Sélectionnez une unité</option>
            ) : (
              sequences.map(s => (
                <option key={s.id} value={s.id}>
                  {s.nom} (Coef: {s.coefficient})
                </option>
              ))
            )}
          </select>
          {!selected.groupe_id && (
            <span className="form-select" style={{ display: 'inline-block', padding: '8px 12px', background: '#fef9c3', color: '#a16207', fontWeight: 'bold', width: 'auto' }}>
              Sélectionnez un groupe
            </span>
          )}
        </div>
      )}

      {selected.filiere_id && unites.length === 0 && !loading && (
        <EmptyState message="Aucune unité trouvée pour cette filière." />
      )}

      {!selected.filiere_id && !loading && (
        <EmptyState message="Sélectionnez une filière, un niveau et un groupe pour commencer." />
      )}

      {etudiants.length > 0 && !loading && (
        <div className="card">
          <div className="card-header">
            <h5 className="mb-0">
              Notes — {sequences.find(s => s.id === selectedSequenceId)?.nom || ''}
              {showExamCols && (
                <span className="ml-2" style={{ fontSize: 12, color: '#666', marginLeft: 8 }}>
                  (Examens inclus — Bloc:
                  <select value={examenBloc} onChange={e => setExamenBloc(Number(e.target.value))} style={{ marginLeft: 4, fontSize: 11, padding: '1px 4px' }}>
                    <option value={1}>Session 1</option>
                    <option value={2}>Session 2</option>
                  </select>)
                </span>
              )}
            </h5>
          </div>
          <div className="card-body p-0">
            <div className="table-wrap">
              <table className="table mb-0">
                <thead>
                  <tr>
                    <th>Étudiant</th>
                    {allControlNums.map(num => (
                      <th key={num}>C{num}</th>
                    ))}
                    {showExamCols && <th>Théorique</th>}
                    {showExamCols && <th>Pratique</th>}
                  </tr>
                </thead>
                <tbody>
                  {etudiants.map((etudiant) => {
                    const seq = sequences.find(s => s.id === selectedSequenceId);
                    const ctrlCells = allControlNums.map(num => {
                      const ctrl = seq?.controles?.find(c => c.numero === num);
                      const note = ctrl ? (notesByControl[ctrl.id] || []).find(n => n.etudiant_id === etudiant.id) : null;
                      return { ctrl, note };
                    });
                    const theoEx = examens.theorique?.[etudiant.id];
                    const praEx = examens.pratique?.[etudiant.id];

                    return (
                      <tr key={etudiant.id}>
                        <td><strong>{etudiant.nom_prenom}</strong></td>
                        {ctrlCells.map(({ ctrl, note }) => (
                          <td key={ctrl?.id}>
                            {note ? (
                              editingNote === note.id ? (
                                <input
                                  type="number"
                                  className="form-input form-input-sm"
                                  value={editValue}
                                  min="0"
                                  max="20"
                                  step="0.25"
                                  style={{ width: '60px' }}
                                  onChange={e => setEditValue(e.target.value)}
                                  onBlur={() => handleSaveNote(note.id)}
                                  onKeyDown={e => { if (e.key === 'Enter') handleSaveNote(note.id); }}
                                  disabled={saving}
                                  autoFocus
                                />
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
                              <span style={{ color: '#ccc' }}>—</span>
                            )}
                          </td>
                        ))}
                        {showExamCols && (
                          <td>
                            {theoEx ? (
                              editingExam?.etudiant_id === etudiant.id && editingExam?.type === 'theorique' ? (
                                <input
                                  type="number"
                                  className="form-input form-input-sm"
                                  value={editExamValue}
                                  min="0"
                                  max="20"
                                  step="0.25"
                                  style={{ width: '60px' }}
                                  onChange={e => setEditExamValue(e.target.value)}
                                  onBlur={() => handleSaveExam('theorique')}
                                  onKeyDown={e => { if (e.key === 'Enter') handleSaveExam('theorique'); }}
                                  disabled={saving}
                                  autoFocus
                                />
                              ) : (
                                <span
                                  style={{ cursor: 'pointer' }}
                                  onClick={() => {
                                    setEditingExam({ etudiant_id: etudiant.id, type: 'theorique' });
                                    setEditExamValue(theoEx.valeur ?? '');
                                  }}
                                >
                                  {theoEx.valeur ?? '—'}
                                </span>
                              )
                            ) : (
                              <span style={{ color: '#ccc' }}>—</span>
                            )}
                          </td>
                        )}
                        {showExamCols && (
                          <td>
                            {praEx ? (
                              editingExam?.etudiant_id === etudiant.id && editingExam?.type === 'pratique' ? (
                                <input
                                  type="number"
                                  className="form-input form-input-sm"
                                  value={editExamValue}
                                  min="0"
                                  max="20"
                                  step="0.25"
                                  style={{ width: '60px' }}
                                  onChange={e => setEditExamValue(e.target.value)}
                                  onBlur={() => handleSaveExam('pratique')}
                                  onKeyDown={e => { if (e.key === 'Enter') handleSaveExam('pratique'); }}
                                  disabled={saving}
                                  autoFocus
                                />
                              ) : (
                                <span
                                  style={{ cursor: 'pointer' }}
                                  onClick={() => {
                                    setEditingExam({ etudiant_id: etudiant.id, type: 'pratique' });
                                    setEditExamValue(praEx.valeur ?? '');
                                  }}
                                >
                                  {praEx.valeur ?? '—'}
                                </span>
                              )
                            ) : (
                              <span style={{ color: '#ccc' }}>—</span>
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

      {selectedSequenceId && etudiants.length === 0 && !loading && selected.groupe_id && (
        <EmptyState message="Aucune note trouvée pour cette séquence et ce groupe." />
      )}
    </div>
  );
}
