import { useState, useEffect, useCallback, useMemo, useRef } from 'react';
import { FiSearch, FiX, FiRefreshCw } from 'react-icons/fi';
import { formateursApi } from '../../api/formateurs';
import { useToast } from '../../context/ToastContext';
import { handleApiError, showSuccess } from '../../utils/errorHandler';
import { controlLabel, noteDisplayStyle } from '../../utils/helpers';
import Badge from '../../components/common/Badge';
import Spinner from '../../components/common/Spinner';
import FilterSelect from '../../components/common/FilterSelect';
import '../../css/components.css';
import '../../css/layout.css';

// A French locale renders the decimal separator as a comma and Number('12,5')
// is NaN, so manual entry goes through the same comma-aware parser as the
// scanner instead of parseFloat, which silently truncates at the comma.
const toGrade = (v) => {
  if (v === null || v === undefined || String(v).trim() === '') return null;
  const n = Number(String(v).replace(',', '.').trim());
  return Number.isFinite(n) ? n : null;
};

export default function MesNotesList() {
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);
  const [sequences, setSequences] = useState([]);
  const [groupes, setGroupes] = useState([]);
  const [selectedSequence, setSelectedSequence] = useState(null);
  const [allControlNums, setAllControlNums] = useState([]);
  const [searchTerm, setSearchTerm] = useState('');
  const [selectedGroupeId, setSelectedGroupeId] = useState('');
  const [selectedSeqId, setSelectedSeqId] = useState('');

  const toast = useToast();

  const loadSequences = useCallback(async () => {
    setLoading(true);
    setError(null);
    try {
      const [seqRes, grpRes] = await Promise.all([
        formateursApi.getMySequences(),
        formateursApi.getGroupes().catch(() => ({ data: { data: [] } })),
      ]);
      const data = seqRes.data.data || [];
      setSequences(data);
      setGroupes(grpRes.data?.data || []);
      const nums = [...new Set(data.flatMap(s => (s.controles || []).map(c => c.numero)))].sort((a, b) => a - b);
      setAllControlNums(nums);
      if (data.length > 0) setSelectedSequence(data[0].id);
    } catch (err) {
      const errorInfo = handleApiError(err, toast, { showToast: false });
      setError(errorInfo.message);
    } finally {
      setLoading(false);
    }
  }, [toast]);

  useEffect(() => { loadSequences(); }, [loadSequences]);

  const filteredSequences = useMemo(() => {
    if (!selectedSeqId) return sequences;
    return sequences.filter(s => s.id === Number(selectedSeqId));
  }, [sequences, selectedSeqId]);

  // The endpoint already narrows to the instructor's filières, but not to the
  // year of the sequences they actually teach, so the list also offered groups
  // from other years that could never match a row here.
  const scopedGroupes = useMemo(() => {
    if (sequences.length === 0) return [];
    const annees = new Set(
      sequences.map(s => `${s.unite?.filiere_id}|${s.unite?.numero_annee}`)
    );
    return groupes.filter(g => annees.has(`${g.niveau?.filiere_id}|${g.niveau?.numero}`));
  }, [groupes, sequences]);

  const resetFilters = () => {
    setSearchTerm('');
    setSelectedGroupeId('');
    setSelectedSeqId('');
  };

  if (loading) return <Spinner />;

  if (error) {
    return (
      <div className="page">
        <div className="alert alert-danger">
          <p>{error}</p>
          <button className="btn btn-outline mt-2" onClick={loadSequences}>Reessayer</button>
        </div>
      </div>
    );
  }

  return (
    <div className="page">
      <div className="page-header">
        <h2 className="page-title">Liste des Notes</h2>
      </div>

      {sequences.length === 0 ? (
        <div className="card">
          <div className="card-body">
            <div className="empty-state"><p>Aucune sequence assignee</p></div>
          </div>
        </div>
      ) : (
        <>
          <div className="card" style={{ marginBottom: 20 }}>
            <div className="card-body">
              <div className="smart-filter" style={{ marginBottom: 0 }}>
                <div className="sf-row">
                  <div className="sf-searchbox">
                    <FiSearch size={14} />
                    <input
                      type="text"
                      value={searchTerm}
                      onChange={e => setSearchTerm(e.target.value)}
                      placeholder="Rechercher un étudiant..."
                    />
                    {searchTerm && (
                      <button type="button" onClick={() => setSearchTerm('')} aria-label="Effacer">
                        <FiX size={13} />
                      </button>
                    )}
                  </div>

                  <FilterSelect
                    label="Groupe"
                    value={selectedGroupeId}
                    options={scopedGroupes.map(g => ({ id: g.id, label: `${g.nom} — ${g.niveau?.filiere?.nom || ''}` }))}
                    onChange={setSelectedGroupeId}
                    placeholder="Tous les groupes"
                    allLabel="Tous les groupes"
                  />

                  <FilterSelect
                    label="Séquence"
                    value={selectedSeqId}
                    options={sequences.map(s => ({ id: s.id, label: `${s.unite?.nom} — ${s.nom}` }))}
                    onChange={setSelectedSeqId}
                    placeholder="Toutes les séquences"
                    allLabel="Toutes les séquences"
                  />

                  <button className="btn btn-outline" onClick={resetFilters} style={{ height: 46 }}>
                    <FiRefreshCw size={14} /> Réinitialiser
                  </button>
                </div>
              </div>
            </div>
          </div>

          <div className="card">
            <div className="card-header">
              <h5 className="mb-0">
                Notes par Séquence
                {filteredSequences.length < sequences.length && (
                  <span style={{ fontWeight: 400, fontSize: 13, color: 'var(--gray-500)' }}>
                    &nbsp;({filteredSequences.length} sur {sequences.length})
                  </span>
                )}
              </h5>
            </div>
            <div className="card-body">
              {filteredSequences.map(seq => (
                  <SequenceRow
                  key={seq.id}
                  sequence={seq}
                  isOpen={selectedSequence === seq.id}
                  onToggle={() => setSelectedSequence(prev => prev === seq.id ? null : seq.id)}
                  searchTerm={searchTerm}
                  selectedGroupeId={selectedGroupeId}
                />
              ))}
            </div>
          </div>
        </>
      )}
    </div>
  );
}

function SequenceRow({ sequence, isOpen, onToggle, searchTerm, selectedGroupeId }) {
  const [editingNote, setEditingNote] = useState(null);
  const [editValue, setEditValue] = useState('');
  const [editAbsent, setEditAbsent] = useState(false);
  const [saving, setSaving] = useState(false);
  const [notesByControl, setNotesByControl] = useState({});
  const [allEtudiants, setAllEtudiants] = useState([]);
  const [loading, setLoading] = useState(false);
  // Locking is per control *and* per group: a publication on one group must
  // leave the instructor's other groups editable, so a sequence-wide flag
  // cannot be used to gate the cells.
  const [lockedByControl, setLockedByControl] = useState({});
  const toast = useToast();
  const saveInFlight = useRef(false);

  const verrouille = useCallback(
    (ctrl, note, etudiant) => {
      if (!ctrl) return false;
      // The API already resolves this per note; fall back to the control's
      // locked-group list for rows that have no grade yet.
      if (note && note.verrouille !== undefined) return !!note.verrouille;
      const locked = lockedByControl[ctrl.id];
      return !!locked && locked.groupes.includes(etudiant?.groupe_id);
    },
    [lockedByControl]
  );

  const lockedGroupes = useMemo(() => {
    const set = new Set();
    Object.values(lockedByControl).forEach(l => l.groupes.forEach(g => set.add(g)));
    return set;
  }, [lockedByControl]);

  // Nothing at all is editable only when every control has its whole perimeter
  // published. A partially published sequence still has to render its table.
  const toutVerrouille = useMemo(
    () => Object.keys(lockedByControl).length > 0
      && Object.values(lockedByControl).every(l => l.all),
    [lockedByControl]
  );

  const ctrlIds = useMemo(() => sequence.controles?.map(c => c.id) || [], [sequence.controles]);

  useEffect(() => {
    if (!isOpen) return;

    setLoading(true);
    const ids = ctrlIds;
    // Collapsing and re-expanding used to let an older response land last and
    // overwrite the newer one, showing grades that no longer matched.
    let ignore = false;

    Promise.all([
      ...ids.map(id =>
        import('../../api/formateurs').then(m => m.formateursApi.getPublicationStatus(id))
      ),
      ...ids.map(id =>
        import('../../api/formateurs').then(m => m.formateursApi.getNotes({ controle_id: id }))
      ),
      import('../../api/formateurs').then(m => m.formateursApi.getSequenceEtudiants(sequence.id)),
    ]).then(responses => {
      if (ignore) return;

      const pubResults = responses.slice(0, ids.length);
      const noteResults = responses.slice(ids.length, ids.length * 2);
      const etuResult = responses[ids.length * 2];

      const locks = {};
      pubResults.forEach((res, idx) => {
        locks[ids[idx]] = {
          groupes: res.data?.data?.groupes_verrouilles || [],
          all: !!res.data?.data?.published_all,
        };
      });
      setLockedByControl(locks);

      const map = {};
      noteResults.forEach((res, idx) => {
        map[ids[idx]] = res.data?.data || [];
      });
      setNotesByControl(map);

      setAllEtudiants(etuResult.data?.data || []);
    }).catch(err => {
      if (ignore) return;
      // Swallowed errors left an empty table that reads as "no grades recorded".
      handleApiError(err, toast);
      setNotesByControl({});
      setAllEtudiants([]);
    }).finally(() => {
      if (!ignore) setLoading(false);
    });

    return () => { ignore = true; };
  }, [isOpen, ctrlIds, sequence.id, toast]);

  const handleSave = async (noteId) => {
    // Enter triggers a save and the input then unmounts, which fires blur and
    // used to fire a second identical PUT and a duplicate toast.
    if (saveInFlight.current) return;
    // Empty and not marked absent: there is nothing to store, so close the
    // editor. Returning here used to leave the cell stuck on a blank input.
    if (!editAbsent && (editValue === '' || editValue === null)) {
      setEditingNote(null);
      setEditValue('');
      setEditAbsent(false);
      return;
    }

    saveInFlight.current = true;
    setSaving(true);
    try {
      const { formateursApi } = await import('../../api/formateurs');
      await formateursApi.updateNote(noteId, editAbsent ? 0 : toGrade(editValue), editAbsent);
      showSuccess(toast, editAbsent ? 'Absence enregistree' : 'Note enregistree');
      setEditingNote(null);
      setEditValue('');
      setEditAbsent(false);
      if (isOpen) {
        const responses = await Promise.all(
          ctrlIds.map(id =>
            import('../../api/formateurs').then(m => m.formateursApi.getNotes({ controle_id: id }))
          )
        );
        const map = {};
        responses.forEach((res, idx) => {
          map[ctrlIds[idx]] = res.data?.data || [];
        });
        setNotesByControl(map);
      }
    } catch (err) {
      handleApiError(err, toast);
    } finally {
      saveInFlight.current = false;
      setSaving(false);
    }
  };

  const mergedEtudiants = useMemo(() => {
    let list = [...allEtudiants];

    if (searchTerm) {
      const term = searchTerm.toLowerCase();
      list = list.filter(e =>
        (e.nom_prenom || '').toLowerCase().includes(term) ||
        (e.nom_ar || '').toLowerCase().includes(term) ||
        (e.numero_inscription || '').toLowerCase().includes(term)
      );
    }

    if (selectedGroupeId) {
      list = list.filter(e => e.groupe_id === Number(selectedGroupeId));
    }

    return list;
  }, [allEtudiants, searchTerm, selectedGroupeId]);

  return (
    <div className="card mb-2" style={{ border: '1px solid var(--gray-200)', borderRadius: 10, overflow: 'hidden' }}>
      <div
        className="card-header"
        style={{ cursor: 'pointer', padding: '14px 18px', background: isOpen ? 'var(--gray-50)' : 'white' }}
        onClick={onToggle}
      >
        <div className="d-flex justify-content-between align-items-center" style={{ fontSize: 14, fontWeight: 600 }}>
          <span>
            {sequence.unite?.nom} — {sequence.nom}
            <Badge label={`Coef: ${sequence.coefficient}`} color="info" className="ml-2" style={{ fontSize: 11 }} />
            {lockedGroupes.size > 0 && (
              <Badge
                label={toutVerrouille ? 'Publié' : `${lockedGroupes.size} groupe(s) publié(s)`}
                color="danger"
                style={{ fontSize: 11, marginLeft: 6 }}
              />
            )}
          </span>
          <span style={{ fontSize: 12, color: 'var(--gray-400)' }}>{isOpen ? '▲' : '▼'}</span>
        </div>
      </div>
      {isOpen && (
        <div className="card-body p-0">
          {loading ? (
            <div className="p-3"><Spinner /></div>
          ) : toutVerrouille ? (
            <div className="p-3">
              <div className="alert alert-danger mb-0">
                <strong>Période terminée.</strong> Les notes/bulletins ont déjà été publiés pour tous les groupes de cette séquence.
                Modification impossible. Contactez l'administrateur si nécessaire.
              </div>
            </div>
          ) : mergedEtudiants.length === 0 ? (
            <div className="p-3 empty-state">
              {searchTerm || selectedGroupeId ? 'Aucun étudiant ne correspond aux filtres' : 'Aucun étudiant inscrit'}
            </div>
          ) : (
            <>
              {sequence.controles?.length > 0 && (
                <div style={{ padding: '8px 16px', fontSize: 12, color: 'var(--gray-500)', background: 'var(--gray-50)', borderBottom: '1px solid var(--gray-200)', display: 'flex', gap: 16, flexWrap: 'wrap' }}>
                  {sequence.controles.map(c => (
                    <span key={c.id}><strong>{controlLabel(c)}</strong> {c.date && `— ${c.date}`}</span>
                  ))}
                  <span style={{ marginLeft: 'auto' }}>{mergedEtudiants.length} étudiant(s)</span>
                </div>
              )}
              {lockedGroupes.size > 0 && !toutVerrouille && (
                <div className="alert alert-warning mb-0 mx-3 mt-3 py-2" style={{ fontSize: 12 }}>
                  Une partie des groupes est déjà publiée : leurs notes restent visibles mais ne sont plus modifiables.
                </div>
              )}
              <div className="table-wrap">
                <table className="table mb-0" style={{ fontSize: 13 }}>
                  <thead>
                    <tr>
                      <th style={{ minWidth: 200, padding: '10px 12px' }}>Étudiant</th>
                      <th style={{ minWidth: 60, padding: '10px 12px' }}>Groupe</th>
                      {sequence.controles?.map(ctrl => (
                        <th key={ctrl.id} style={{ textAlign: 'center', minWidth: 60, padding: '10px 8px', fontSize: 12 }}>
                          {controlLabel(ctrl)}
                        </th>
                      ))}
                    </tr>
                  </thead>
                  <tbody>
                    {mergedEtudiants.map(etudiant => {
                      const ctrlCells = (sequence.controles || []).map(ctrl => {
                        const note = (notesByControl[ctrl.id] || []).find(n => n.etudiant_id === etudiant.id);
                        return { ctrl, note };
                      });

                      return (
                        <tr
                          key={etudiant.id}
                          style={{ transition: 'background 0.15s' }}
                          onMouseEnter={e => e.currentTarget.style.background = 'var(--gray-50)'}
                          onMouseLeave={e => e.currentTarget.style.background = 'transparent'}
                        >
                          <td style={{ padding: '8px 12px' }}>
                            <strong style={{ fontSize: 13 }}>{etudiant.nom_prenom}</strong>
                            <div style={{ fontSize: 11, color: 'var(--gray-400)', marginTop: 1 }}>
                              {etudiant.numero_inscription}
                            </div>
                          </td>
                          <td style={{ padding: '8px 12px', fontSize: 12, color: 'var(--gray-500)' }}>
                            {etudiant.groupe?.nom || '—'}
                          </td>
                      {ctrlCells.map(({ ctrl, note }) => {
                        const locked = verrouille(ctrl, note, etudiant);
                        return (
                            <td key={ctrl?.id} style={{ textAlign: 'center', padding: '6px 8px', verticalAlign: 'middle' }}>
                              {note ? (
                                editingNote === note.id ? (
                                  <div style={{ display: 'inline-flex', flexDirection: 'column', alignItems: 'center', gap: 3 }}>
                                    <input
                                      type="number"
                                      className="form-input form-input-sm"
                                      value={editAbsent ? '' : editValue}
                                      min="0"
                                      max={ctrl?.note_max ?? 20}
                                      step="0.25"
                                      style={{ width: 64, textAlign: 'center', padding: '4px 6px', fontSize: 13 }}
                                      onChange={e => setEditValue(e.target.value)}
                                      onBlur={() => handleSave(note.id)}
                                      onKeyDown={e => { if (e.key === 'Enter') handleSave(note.id); }}
                                      disabled={saving || editAbsent}
                                      autoFocus
                                    />
                                    <label
                                      title="Marquer comme absent"
                                      onMouseDown={e => e.preventDefault()}
                                      style={{ display: 'inline-flex', alignItems: 'center', gap: 3, fontSize: 11, color: 'var(--gray-500)', cursor: 'pointer' }}
                                    >
                                      <input
                                        type="checkbox"
                                        checked={editAbsent}
                                        disabled={saving}
                                        onChange={e => {
                                          const next = e.target.checked;
                                          setEditAbsent(next);
                                          setEditValue(next ? '' : (note.valeur ?? ''));
                                        }}
                                      />
                                      Abs.
                                    </label>
                                  </div>
                                ) : note.est_absent ? (
                                  <span
                                    style={{ ...noteDisplayStyle(), cursor: (note.is_confirmed || locked) ? 'default' : 'pointer', borderBottom: (note.is_confirmed || locked) ? 'none' : '1px dashed var(--gray-300)' }}
                                    onClick={() => {
                                      if (note.is_confirmed || locked) return;
                                      setEditingNote(note.id);
                                      setEditValue('');
                                      setEditAbsent(true);
                                    }}
                                    title={locked ? 'Publié — non modifiable' : (note.is_confirmed ? 'Absent' : 'Cliquer pour corriger')}
                                  >
                                    Absent
                                  </span>
                                ) : note.is_confirmed || locked ? (
                                  <span style={{ fontWeight: 600, fontSize: 13, opacity: locked && !note.is_confirmed ? 0.7 : 1 }} title={locked ? 'Publié — non modifiable' : undefined}>
                                    {note.valeur ?? '—'}
                                  </span>
                                ) : (
                                  <span
                                    style={{ cursor: 'pointer', fontWeight: 600, fontSize: 13, borderBottom: '1px dashed var(--gray-300)' }}
                                    onClick={() => {
                                      setEditingNote(note.id);
                                      setEditValue(note.valeur ?? '');
                                      setEditAbsent(false);
                                    }}
                                    title="Cliquer pour modifier"
                                  >
                                    {note.valeur ?? '—'}
                                  </span>
                                )
                              ) : (
                                <span
                                  style={{
                                    display: 'inline-block', width: 32, height: 32, lineHeight: '32px',
                                    borderRadius: '50%',
                                    background: locked ? 'var(--gray-100)' : '#FFF0F0',
                                    color: locked ? 'var(--gray-400)' : '#E74C3C',
                                    fontWeight: 600, fontSize: 11,
                                  }}
                                  title={locked ? 'Publié — non modifiable' : 'Aucune note saisie'}
                                >
                                  —
                                </span>
                              )}
                            </td>
                          );
                        })}
                        </tr>
                      );
                    })}
                  </tbody>
                </table>
              </div>
            </>
          )}
        </div>
      )}
    </div>
  );
}
