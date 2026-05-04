import { useEffect, useState, useCallback } from 'react';
import { notesApi } from '../../api/notes';
import { controlesApi } from '../../api/controles';
import { sequencesApi } from '../../api/sequences';
import { unitesApi } from '../../api/unites';
import { adminApi } from '../../api/admin';
import { useToast } from '../../context/ToastContext';
import { useAnneeAcademique } from '../../context/AnneeAcademiqueContext';
import { handleApiError, showSuccess } from '../../utils/errorHandler';
import { formatNiveau } from '../../utils/helpers';
import Badge from '../../components/common/Badge';
import Spinner from '../../components/common/Spinner';
import '../../css/components.css';
import '../../css/layout.css';

export default function Notes() {
  const [tab, setTab] = useState('controles');

  const [filieres, setFilieres] = useState([]);
  const [niveaux, setNiveaux] = useState([]);
  const [groupes, setGroupes] = useState([]);
  const [unites, setUnites] = useState([]);

  const [filiereId, setFiliereId] = useState('');
  const [niveauId, setNiveauId] = useState('');
  const [groupeId, setGroupeId] = useState('');
  const [uniteId, setUniteId] = useState('');

  const [sequences, setSequences] = useState([]);
  const [controles, setControles] = useState([]);
  const [sequenceId, setSequenceId] = useState('');
  const [controleId, setControleId] = useState('');
  const [notes, setNotes] = useState([]);
  const [loadingNotes, setLoadingNotes] = useState(false);
  const [savingNote, setSavingNote] = useState(null);

  const [bloc, setBloc] = useState('');
  const [type, setType] = useState('');
  const [examenNotes, setExamenNotes] = useState([]);
  const [loadingExamens, setLoadingExamens] = useState(false);
  const [savingExamens, setSavingExamens] = useState(false);

  const toast = useToast();
  const { currentAnnee } = useAnneeAcademique();

  useEffect(() => {
    adminApi.getFilieres()
      .then(res => setFilieres(res.data.data || []))
      .catch(() => setFilieres([]));
  }, []);

  useEffect(() => {
    if (filiereId) {
      adminApi.getNiveaux(filiereId)
        .then(res => {
          setNiveaux(res.data.data || []);
          setNiveauId('');
          setGroupeId('');
          setGroupes([]);
        })
        .catch(() => { setNiveaux([]); setNiveauId(''); });
    } else {
      setNiveaux([]);
      setNiveauId('');
      setGroupeId('');
      setGroupes([]);
    }
  }, [filiereId]);

  useEffect(() => {
    if (niveauId && currentAnnee) {
      adminApi.getGroupes({ niveau_id: niveauId, annee_academique_id: currentAnnee.id })
        .then(res => {
          setGroupes(res.data.data || []);
          setGroupeId('');
        })
        .catch(() => { setGroupes([]); setGroupeId(''); });
    } else {
      setGroupes([]);
      setGroupeId('');
    }
  }, [niveauId, currentAnnee]);

  useEffect(() => {
    if (filiereId && currentAnnee) {
      unitesApi.getUnites({ filiere_id: filiereId, annee_academique_id: currentAnnee.id })
        .then(res => {
          setUnites(res.data.data || []);
          setUniteId('');
        })
        .catch(() => { setUnites([]); setUniteId(''); });
    } else {
      setUnites([]);
      setUniteId('');
    }
  }, [filiereId, currentAnnee]);

  useEffect(() => {
    if (uniteId) {
      sequencesApi.getSequences(uniteId)
        .then(res => {
          setSequences(res.data.data || []);
          setSequenceId('');
          setControleId('');
        })
        .catch(() => { setSequences([]); setSequenceId(''); });
    } else {
      setSequences([]);
      setSequenceId('');
      setControleId('');
    }
  }, [uniteId]);

  useEffect(() => {
    if (sequenceId) {
      controlesApi.getControles(sequenceId)
        .then(res => {
          setControles(res.data.data || []);
          setControleId('');
        })
        .catch(() => { setControles([]); setControleId(''); });
    } else {
      setControles([]);
      setControleId('');
    }
  }, [sequenceId]);

  const loadNotes = useCallback(() => {
    if (!controleId || !groupeId) return;
    setLoadingNotes(true);
    notesApi.getNotes({ controle_id: controleId, groupe_id: groupeId })
      .then(res => {
        const data = res.data.data || [];
        setNotes(data.map(n => ({ ...n, _original: n.valeur })));
      })
      .catch(() => setNotes([]))
      .finally(() => setLoadingNotes(false));
  }, [controleId, groupeId]);

  useEffect(() => {
    loadNotes();
  }, [loadNotes]);

  const loadExamens = useCallback(() => {
    if (!groupeId || !uniteId || !bloc || !type) return;
    setLoadingExamens(true);
    notesApi.getExamens({ groupe_id: groupeId, unite_id: uniteId, bloc, type })
      .then(res => {
        const data = res.data.data || [];
        setExamenNotes(data.map(n => ({ ...n, _original: n.valeur })));
      })
      .catch(() => setExamenNotes([]))
      .finally(() => setLoadingExamens(false));
  }, [groupeId, uniteId, bloc, type]);

  useEffect(() => {
    loadExamens();
  }, [loadExamens]);

  const handleNoteChange = (index, value) => {
    setNotes(prev => prev.map((n, i) =>
      i === index ? { ...n, valeur: value === '' ? null : parseFloat(value) } : n
    ));
  };

  const handleSaveNote = async (index) => {
    const note = notes[index];
    if (!note) return;

    setSavingNote(note.id);
    try {
      await notesApi.updateNote(note.id, note.valeur);
      showSuccess(toast, `Note de ${note.etudiant?.nom || note.etudiant?.nom_prenom} modifiee`);
      loadNotes();
    } catch (error) {
      handleApiError(error, toast);
    } finally {
      setSavingNote(null);
    }
  };

  const handleExamenNoteChange = (index, value) => {
    setExamenNotes(prev => prev.map((n, i) =>
      i === index ? { ...n, valeur: value === '' ? null : parseFloat(value) } : n
    ));
  };

  const handleSaveBulkExamens = async () => {
    setSavingExamens(true);
    try {
        await notesApi.saveBulkExamens({
          examens: examenNotes.map(n => ({
            etudiant_id: n.etudiant_id,
            valeur: n.valeur,
          })),
          unite_id: uniteId,
          bloc: parseInt(bloc),
          type,
          semestre: parseInt(bloc) === 1 ? 1 : parseInt(bloc) === 2 ? 2 : 1,
          annee_academique_id: currentAnnee?.id,
        });
      showSuccess(toast, 'Notes d\'examen enregistrees');
      loadExamens();
    } catch (error) {
      handleApiError(error, toast);
    } finally {
      setSavingExamens(false);
    }
  };

  const handleResetControleFilters = () => {
    setUniteId('');
    setSequenceId('');
    setControleId('');
  };

  const handleResetExamenFilters = () => {
    setUniteId('');
    setSequenceId('');
    setControleId('');
  };

  return (
    <div className="page-container">
      <div className="page-header">
        <h1>Gestion des Notes</h1>
      </div>

      <div className="tab-bar">
        <button
          className={`btn ${tab === 'controles' ? 'btn-primary' : 'btn-outline'}`}
          onClick={() => setTab('controles')}
        >
          Controles
        </button>
        <button
          className={`btn ${tab === 'examens' ? 'btn-primary' : 'btn-outline'}`}
          onClick={() => setTab('examens')}
        >
          Examens
        </button>
      </div>

      <div className="filter-bar">
        <select className="form-select" value={filiereId} onChange={e => { setFiliereId(e.target.value); setNiveauId(''); setGroupeId(''); handleResetControleFilters(); }}>
          <option value="">Filiere</option>
          {filieres.map(f => <option key={f.id} value={f.id}>{f.nom}</option>)}
        </select>
        <select className="form-select" value={niveauId} onChange={e => { setNiveauId(e.target.value); setGroupeId(''); }} disabled={!filiereId}>
          <option value="">Niveau</option>
          {niveaux.map(n => <option key={n.id} value={n.id}>{formatNiveau(n.numero)}</option>)}
        </select>
        <select className="form-select" value={groupeId} onChange={e => setGroupeId(e.target.value)} disabled={!niveauId}>
          <option value="">Groupe</option>
          {groupes.map(g => <option key={g.id} value={g.id}>{g.nom}</option>)}
        </select>
        <select className="form-select" value={uniteId} onChange={e => { setUniteId(e.target.value); setSequenceId(''); setControleId(''); }} disabled={!filiereId || !currentAnnee}>
          <option value="">Unite</option>
          {unites.map(u => <option key={u.id} value={u.id}>{u.nom}</option>)}
        </select>
        {tab === 'controles' && (
          <>
            <select className="form-select" value={sequenceId} onChange={e => { setSequenceId(e.target.value); setControleId(''); }} disabled={!uniteId}>
              <option value="">Sequence</option>
              {sequences.map(s => <option key={s.id} value={s.id}>{s.nom}</option>)}
            </select>
            <select className="form-select" value={controleId} onChange={e => setControleId(e.target.value)} disabled={!sequenceId}>
              <option value="">Controle</option>
              {controles.map(c => <option key={c.id} value={c.id}>C{c.numero} — {c.date || 'Sans date'}</option>)}
            </select>
          </>
        )}
        {tab === 'examens' && (
          <>
            <select className="form-select" value={bloc} onChange={e => setBloc(e.target.value)} disabled={!uniteId}>
              <option value="">Bloc</option>
              <option value="1">Bloc 1</option>
              <option value="2">Bloc 2</option>
            </select>
            <select className="form-select" value={type} onChange={e => setType(e.target.value)} disabled={!bloc}>
              <option value="">Type</option>
              <option value="theorique">Theorique</option>
              <option value="pratique">Pratique</option>
            </select>
          </>
        )}
      </div>

      {tab === 'controles' && (
        <>
          {loadingNotes ? (
            <div className="spinner-wrap"><Spinner /></div>
          ) : (
            <div className="table-wrap">
              <table>
                <thead>
                  <tr>
                    <th>#</th>
                    <th>Nom complet</th>
                    <th>N° Inscription</th>
                    <th>Note /20</th>
                    <th>Statut</th>
                    <th>Actions</th>
                  </tr>
                </thead>
                <tbody>
                  {notes.map((n, i) => {
                    return (
                      <tr key={n.id}>
                        <td>{i + 1}</td>
                        <td><strong>{n.etudiant?.nom || n.etudiant?.nom_prenom}</strong></td>
                        <td>{n.etudiant?.numero_inscription || '—'}</td>
                        <td>
                          <input
                            type="number"
                            min="0"
                            max="20"
                            step="0.25"
                            className="form-input note-input"
                            value={n.valeur ?? ''}
                            onChange={e => handleNoteChange(i, e.target.value)}
                          />
                        </td>
                        <td>
                          {n.confirme ? (
                            <Badge label="Confirmee" color="teal" />
                          ) : (
                            <Badge label="En attente" color="orange" />
                          )}
                        </td>
                        <td>
                          <button
                            className="btn btn-sm btn-primary"
                            onClick={() => handleSaveNote(i)}
                            disabled={savingNote === n.id}
                          >
                            {savingNote === n.id ? '...' : 'Sauvegarder'}
                          </button>
                        </td>
                      </tr>
                    );
                  })}
                </tbody>
              </table>
            </div>
          )}
        </>
      )}

      {tab === 'examens' && (
        <>
          {loadingExamens ? (
            <div className="spinner-wrap"><Spinner /></div>
          ) : (
            <>
              {examenNotes.length > 0 && (
                <div className="table-wrap">
                  <table>
                    <thead>
                      <tr>
                        <th>#</th>
                        <th>Nom complet</th>
                        <th>N° Inscription</th>
                        <th>Note /20</th>
                      </tr>
                    </thead>
                    <tbody>
                      {examenNotes.map((n, i) => (
                        <tr key={n.id || i}>
                          <td>{i + 1}</td>
                          <td><strong>{n.etudiant?.nom || n.etudiant?.nom_prenom}</strong></td>
                          <td>{n.etudiant?.numero_inscription || '—'}</td>
                          <td>
                            <input
                              type="number"
                              min="0"
                              max="20"
                              step="0.25"
                              className="form-input note-input"
                              value={n.valeur ?? ''}
                              onChange={e => handleExamenNoteChange(i, e.target.value)}
                            />
                          </td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>
              )}

              {examenNotes.length > 0 && (
                <div className="bulk-save-bar">
                  <button
                    className="btn btn-primary"
                    onClick={handleSaveBulkExamens}
                    disabled={savingExamens}
                  >
                    {savingExamens ? 'Enregistrement...' : 'Sauvegarder tout'}
                  </button>
                </div>
              )}
            </>
          )}
        </>
      )}
    </div>
  );
}
