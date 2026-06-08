import { useState, useEffect, useCallback, useMemo } from 'react';
import { FiSearch, FiX, FiRefreshCw } from 'react-icons/fi';
import { formateursApi } from '../../api/formateurs';
import { useToast } from '../../context/ToastContext';
import { handleApiError, showSuccess } from '../../utils/errorHandler';
import Badge from '../../components/common/Badge';
import Spinner from '../../components/common/Spinner';
import '../../css/components.css';
import '../../css/layout.css';

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
              <div style={{ display: 'flex', gap: 12, flexWrap: 'wrap', alignItems: 'flex-end' }}>
                <div className="form-group" style={{ flex: '1 1 220px', marginBottom: 0 }}>
                  <label className="form-label" style={{ fontSize: 12, marginBottom: 4 }}>Rechercher un étudiant</label>
                  <div style={{ position: 'relative' }}>
                    <FiSearch size={15} style={{ position: 'absolute', left: 10, top: '50%', transform: 'translateY(-50%)', color: 'var(--gray-400)' }} />
                    <input
                      className="form-input"
                      type="text"
                      placeholder="Nom, prénom, n° inscription..."
                      value={searchTerm}
                      onChange={e => setSearchTerm(e.target.value)}
                      style={{ paddingLeft: 32, paddingRight: searchTerm ? 32 : 12 }}
                    />
                    {searchTerm && (
                      <FiX
                        size={15}
                        style={{ position: 'absolute', right: 10, top: '50%', transform: 'translateY(-50%)', cursor: 'pointer', color: 'var(--gray-400)' }}
                        onClick={() => setSearchTerm('')}
                      />
                    )}
                  </div>
                </div>

                <div className="form-group" style={{ flex: '0 1 200px', marginBottom: 0 }}>
                  <label className="form-label" style={{ fontSize: 12, marginBottom: 4 }}>Groupe</label>
                  <select className="form-select" value={selectedGroupeId} onChange={e => setSelectedGroupeId(e.target.value)}>
                    <option value="">Tous les groupes</option>
                    {groupes.map(g => (
                      <option key={g.id} value={g.id}>{g.nom} — {g.niveau?.filiere?.nom || ''}</option>
                    ))}
                  </select>
                </div>

                <div className="form-group" style={{ flex: '0 1 250px', marginBottom: 0 }}>
                  <label className="form-label" style={{ fontSize: 12, marginBottom: 4 }}>Séquence</label>
                  <select className="form-select" value={selectedSeqId} onChange={e => setSelectedSeqId(e.target.value)}>
                    <option value="">Toutes les séquences</option>
                    {sequences.map(s => (
                      <option key={s.id} value={s.id}>{s.unite?.nom} — {s.nom}</option>
                    ))}
                  </select>
                </div>

                <button className="btn btn-outline btn-sm" onClick={resetFilters} style={{ height: 36 }}>
                  <FiRefreshCw size={14} /> Réinitialiser
                </button>
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
  const [saving, setSaving] = useState(false);
  const [notesByControl, setNotesByControl] = useState({});
  const [allEtudiants, setAllEtudiants] = useState([]);
  const [loading, setLoading] = useState(false);
  const [published, setPublished] = useState(false);
  const toast = useToast();

  const ctrlIds = useMemo(() => sequence.controles?.map(c => c.id) || [], [sequence.controles]);

  useEffect(() => {
    if (isOpen) {
      setLoading(true);
      const ids = ctrlIds;

      Promise.all([
        ...ids.map(id =>
          import('../../api/formateurs').then(m => m.formateursApi.getPublicationStatus(id))
        ),
        ...ids.map(id =>
          import('../../api/formateurs').then(m => m.formateursApi.getNotes({ controle_id: id }))
        ),
        import('../../api/formateurs').then(m => m.formateursApi.getSequenceEtudiants(sequence.id)),
      ]).then(responses => {
        const pubResults = responses.slice(0, ids.length);
        const noteResults = responses.slice(ids.length, ids.length * 2);
        const etuResult = responses[ids.length * 2];

        setPublished(pubResults.some(r => r.data?.data?.published));

        const map = {};
        noteResults.forEach((res, idx) => {
          map[ids[idx]] = res.data?.data || [];
        });
        setNotesByControl(map);

        setAllEtudiants(etuResult.data?.data || []);
      }).catch(() => {
        setNotesByControl({});
        setAllEtudiants([]);
      }).finally(() => setLoading(false));
    }
  }, [isOpen, ctrlIds, sequence.id]);

  const handleSave = async (noteId) => {
    if (editValue === '' || editValue === null) return;
    setSaving(true);
    try {
      const { formateursApi } = await import('../../api/formateurs');
      await formateursApi.updateNote(noteId, parseFloat(editValue));
      showSuccess(toast, 'Note enregistree');
      setEditingNote(null);
      setEditValue('');
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

  const controlLabel = (ctrl) => `C${ctrl.numero}`;

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
            <Badge color="info" className="ml-2" style={{ fontSize: 11 }}>Coef: {sequence.coefficient}</Badge>
            {published && <Badge color="danger" style={{ fontSize: 11, marginLeft: 6 }}>Publié</Badge>}
          </span>
          <span style={{ fontSize: 12, color: 'var(--gray-400)' }}>{isOpen ? '▲' : '▼'}</span>
        </div>
      </div>
      {isOpen && (
        <div className="card-body p-0">
          {loading ? (
            <div className="p-3"><Spinner /></div>
          ) : published ? (
            <div className="p-3">
              <div className="alert alert-danger mb-0">
                <strong>Période terminée.</strong> Les notes/bulletins ont déjà été publiés pour ce groupe.
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
                          {ctrlCells.map(({ ctrl, note }) => (
                            <td key={ctrl?.id} style={{ textAlign: 'center', padding: '6px 8px', verticalAlign: 'middle' }}>
                              {note ? (
                                editingNote === note.id ? (
                                  <input
                                    type="number"
                                    className="form-input form-input-sm"
                                    value={editValue}
                                    min="0"
                                    max="20"
                                    step="0.25"
                                    style={{ width: 64, textAlign: 'center', padding: '4px 6px', fontSize: 13 }}
                                    onChange={e => setEditValue(e.target.value)}
                                    onBlur={() => handleSave(note.id)}
                                    onKeyDown={e => { if (e.key === 'Enter') handleSave(note.id); }}
                                    disabled={saving}
                                    autoFocus
                                  />
                                ) : note.is_confirmed || published ? (
                                  <span style={{ fontWeight: 600, fontSize: 13 }}>{note.valeur ?? '—'}</span>
                                ) : (
                                  <span
                                    style={{ cursor: 'pointer', fontWeight: 600, fontSize: 13, borderBottom: '1px dashed var(--gray-300)' }}
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
                                  style={{
                                    display: 'inline-block', width: 32, height: 32, lineHeight: '32px',
                                    borderRadius: '50%', background: '#FFF0F0', color: '#E74C3C',
                                    fontWeight: 600, fontSize: 11,
                                  }}
                                  title="Aucune note saisie"
                                >
                                  —
                                </span>
                              )}
                            </td>
                          ))}
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
