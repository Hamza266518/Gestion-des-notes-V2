import { useState, useEffect, useCallback } from 'react';
import { formateursApi } from '../../api/formateurs';
import { notesApi } from '../../api/notes';
import { useToast } from '../../context/ToastContext';
import { useAnneeAcademique } from '../../context/AnneeAcademiqueContext';
import Spinner from '../../components/common/Spinner';
import Badge from '../../components/common/Badge';
import { handleApiError, showSuccess } from '../../utils/errorHandler';
import '../../css/components.css';
import '../../css/layout.css';

export default function NotesList() {
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);
  const [sequences, setSequences] = useState([]);
  const [selectedSequence, setSelectedSequence] = useState(null);
  const [controles, setControles] = useState([]);
  const [selectedControle, setSelectedControle] = useState(null);
  const [notes, setNotes] = useState([]);
  const [editedValues, setEditedValues] = useState({});
  const [saving, setSaving] = useState(false);
  const toast = useToast();
  const { currentAnnee } = useAnneeAcademique();

  const loadSequences = useCallback(async () => {
    setLoading(true);
    setError(null);

    try {
      const res = await formateursApi.getMySequences();
      setSequences(res.data.data || []);
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

  const loadControles = (sequenceId) => {
    const seq = sequences.find(s => s.id === sequenceId);
    setSelectedSequence(sequenceId);
    setSelectedControle(null);
    setNotes([]);
    if (seq && seq.controles) {
      setControles(seq.controles);
    } else {
      setControles([]);
    }
  };

  const loadNotes = async (controleId) => {
    if (!controleId) return;
    setSelectedControle(controleId);
    setLoading(true);
    setError(null);
    try {
      const res = await formateursApi.getNotes({ controle_id: controleId });
      setNotes(res.data.data || []);
      setEditedValues({});
    } catch (err) {
      const errorInfo = handleApiError(err, toast, { showToast: false });
      setError(errorInfo.message);
    } finally {
      setLoading(false);
    }
  };

  const handleSaveNote = async (noteId, valeur) => {
    setSaving(true);
    try {
      await notesApi.updateNote(noteId, valeur);
      showSuccess(toast, 'Note enregistree avec succes');
      if (selectedControle) {
        loadNotes(selectedControle);
      }
    } catch (err) {
      handleApiError(err, toast);
    } finally {
      setSaving(false);
    }
  };

  if (loading && sequences.length === 0) return <Spinner />;

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
        <h2 className="page-title">Mes Unites et Sequences</h2>
      </div>

      <div className="card mb-3">
        <div className="card-header">
          <h5 className="mb-0">Mes Unites et Sequences</h5>
        </div>
        <div className="card-body">
          <div className="table-wrap">
            <table className="table">
              <thead>
                <tr>
                  <th>Unite</th>
                  <th>Sequence</th>
                  <th>Coef</th>
                  <th>Controles</th>
                  <th></th>
                </tr>
              </thead>
              <tbody>
                {sequences.length === 0 ? (
                  <tr><td colSpan="5" className="text-center">Aucune sequence assignee</td></tr>
                ) : (
                  sequences.map((seq) => (
                    <tr key={seq.id} className={selectedSequence === seq.id ? 'table-active' : ''} style={{ cursor: 'pointer' }} onClick={() => loadControles(seq.id)}>
                      <td><strong>{seq.unite?.nom}</strong></td>
                      <td>{seq.nom}</td>
                      <td><Badge color="info">{seq.coefficient}</Badge></td>
                      <td>{seq.nombre_controles || 0} controle(s)</td>
                      <td>
                        <Badge color={selectedSequence === seq.id ? 'primary' : 'outline'}>
                          {selectedSequence === seq.id ? 'Selectionnee' : 'Selectionner'}
                        </Badge>
                      </td>
                    </tr>
                  ))
                )}
              </tbody>
            </table>
          </div>
        </div>
      </div>

      {selectedSequence && controles.length > 0 && (
        <div className="card mb-3">
          <div className="card-header">
            <h5 className="mb-0">Controles - {sequences.find(s => s.id === selectedSequence)?.nom}</h5>
          </div>
          <div className="card-body">
            <div className="d-flex gap-2 flex-wrap">
              {controles.map((ctrl) => (
                <button
                  key={ctrl.id}
                  className={`btn ${selectedControle === ctrl.id ? 'btn-primary' : 'btn-outline'}`}
                  onClick={() => loadNotes(ctrl.id)}
                >
                  Controle {ctrl.numero}
                </button>
              ))}
            </div>
          </div>
        </div>
      )}

      {loading ? (
        <Spinner />
      ) : selectedControle && notes.length > 0 ? (
        <div className="card">
          <div className="card-header">
            <h5 className="mb-0">
              Notes - {sequences.find(s => s.id === selectedSequence)?.nom} — Controle {notes[0]?.controle?.numero}
            </h5>
          </div>
          <div className="card-body">
            <div className="table-wrap">
              <table className="table">
                <thead>
                  <tr>
                    <th>Etudiant</th>
                    <th>CIN</th>
                    <th>Note /20</th>
                    <th>Statut</th>
                    <th>Action</th>
                  </tr>
                </thead>
                <tbody>
                  {notes.map((note) => (
                    <tr key={note.id}>
                      <td><strong>{note.etudiant?.nom_prenom}</strong></td>
                      <td><code>{note.etudiant?.cin}</code></td>
                      <td>
                        <input
                          type="number"
                          className="form-input form-input-sm"
                          value={editedValues[note.id] ?? note.valeur ?? ''}
                          min="0"
                          max="20"
                          step="0.25"
                          style={{ width: '80px' }}
                          onChange={(e) => setEditedValues(prev => ({ ...prev, [note.id]: e.target.value }))}
                          disabled={saving || note.is_confirmed}
                        />
                      </td>
                      <td>
                        {note.is_confirmed ? (
                          <Badge color="success">Confirmee</Badge>
                        ) : (
                          <Badge color="warning">En attente</Badge>
                        )}
                      </td>
                      <td>
                        {!note.is_confirmed && (
                          <button
                            className="btn btn-sm btn-primary"
                            onClick={() => {
                              handleSaveNote(note.id, parseFloat(editedValues[note.id] ?? note.valeur));
                            }}
                            disabled={saving}
                          >
                            Enregistrer
                          </button>
                        )}
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          </div>
        </div>
      ) : selectedControle ? (
        <div className="card">
          <div className="card-body">
            <div className="empty-state"><p>Aucune note saisie pour ce controle</p></div>
          </div>
        </div>
      ) : null}
    </div>
  );
}
