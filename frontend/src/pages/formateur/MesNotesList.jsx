import { useState, useEffect, useCallback } from 'react';
import { formateursApi } from '../../api/formateurs';
import { useToast } from '../../context/ToastContext';
import { useAnneeAcademique } from '../../context/AnneeAcademiqueContext';
import { handleApiError, showSuccess } from '../../utils/errorHandler';
import Spinner from '../../components/common/Spinner';
import Badge from '../../components/common/Badge';
import '../../css/components.css';
import '../../css/layout.css';

export default function MesNotesList() {
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);
  const [sequences, setSequences] = useState([]);
  const [selectedSequence, setSelectedSequence] = useState(null);
  const [allControlNums, setAllControlNums] = useState([]);
  const toast = useToast();
  const { currentAnnee } = useAnneeAcademique();

  const loadSequences = useCallback(async () => {
    setLoading(true);
    setError(null);

    try {
      const res = await formateursApi.getMySequences();
      const data = res.data.data || [];
      setSequences(data);

      const nums = [...new Set(data.flatMap(s => (s.controles || []).map(c => c.numero)))].sort((a, b) => a - b);
      setAllControlNums(nums);

      if (data.length > 0) {
        setSelectedSequence(data[0].id);
      }
    } catch (err) {
      const errorInfo = handleApiError(err, toast, { showToast: false });
      setError(errorInfo.message);
    } finally {
      setLoading(false);
    }
  }, [toast]);

  useEffect(() => {
    loadSequences();
  }, [loadSequences]);

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
        <div className="card">
          <div className="card-header">
            <h5 className="mb-0">Notes par Sequence</h5>
          </div>
          <div className="card-body">
            {sequences.map((seq) => (
              <SequenceRow
                key={seq.id}
                sequence={seq}
                allControlNums={allControlNums}
                isOpen={selectedSequence === seq.id}
                onToggle={() => setSelectedSequence(prev => prev === seq.id ? null : seq.id)}
              />
            ))}
          </div>
        </div>
      )}
    </div>
  );
}

function SequenceRow({ sequence, allControlNums, isOpen, onToggle }) {
  const [editingNote, setEditingNote] = useState(null);
  const [editValue, setEditValue] = useState('');
  const [saving, setSaving] = useState(false);
  const [notesByControl, setNotesByControl] = useState({});
  const [loading, setLoading] = useState(false);
  const toast = useToast();

  useEffect(() => {
    if (isOpen) {
      setLoading(true);
      const ctrlIds = sequence.controles?.map(c => c.id) || [];
      Promise.all(
        ctrlIds.map(id =>
          import('../../api/formateurs').then(m => m.formateursApi.getNotes({ controle_id: id }))
        )
      ).then(responses => {
        const map = {};
        responses.forEach((res, idx) => {
          const ctrlId = ctrlIds[idx];
          map[ctrlId] = res.data?.data || [];
        });
        setNotesByControl(map);
      }).catch(() => {
        setNotesByControl({});
      }).finally(() => {
        setLoading(false);
      });
    }
  }, [isOpen, sequence.controles]);

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
        const ctrlIds = sequence.controles?.map(c => c.id) || [];
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

  const etudiants = [...new Map(
    Object.values(notesByControl).flat().map(n => [n.etudiant_id, n.etudiant])
  ).values()];

  return (
    <div className="card mb-2">
      <div
        className="card-header"
        style={{ cursor: 'pointer' }}
        onClick={onToggle}
      >
        <h6 className="mb-0 d-flex justify-content-between align-items-center">
          <span>
            {sequence.unite?.nom} — {sequence.nom}
            <Badge color="info" className="ml-2">Coef: {sequence.coefficient}</Badge>
          </span>
          <span className="chevron">{isOpen ? '▲' : '▼'}</span>
        </h6>
      </div>
      {isOpen && (
        <div className="card-body p-0">
          {loading ? (
            <div className="p-3"><Spinner /></div>
          ) : etudiants.length === 0 ? (
            <div className="p-3 empty-state">Aucune note</div>
          ) : (
            <div className="table-wrap">
              <table className="table mb-0">
                <thead>
                  <tr>
                    <th>Etudiant</th>
                    {allControlNums.map(num => (
                      <th key={num}>C{num}</th>
                    ))}
                  </tr>
                </thead>
                <tbody>
                  {etudiants.map((etudiant) => {
                    const ctrlCells = allControlNums.map(num => {
                      const ctrl = sequence.controles?.find(c => c.numero === num);
                      const note = ctrl ? (notesByControl[ctrl.id] || []).find(n => n.etudiant_id === etudiant.id) : null;
                      return { ctrl, note };
                    });

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
                                  style={{ width: '70px' }}
                                  onChange={e => setEditValue(e.target.value)}
                                  onBlur={() => handleSave(note.id)}
                                  onKeyDown={e => { if (e.key === 'Enter') handleSave(note.id); }}
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
                                >
                                  <strong>{note.valeur ?? '—'}</strong>/20
                                </span>
                              )
                            ) : (
                              <span>—</span>
                            )}
                          </td>
                        ))}
                      </tr>
                    );
                  })}
                </tbody>
              </table>
            </div>
          )}
        </div>
      )}
    </div>
  );
}
