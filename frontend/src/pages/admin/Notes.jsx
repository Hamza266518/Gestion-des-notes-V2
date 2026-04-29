import { useEffect, useState } from 'react';
import { adminApi } from '../../api/admin';
import { notesApi } from '../../api/notes';
import { unitesApi } from '../../api/unites';
import { sequencesApi } from '../../api/sequences';
import { controlesApi } from '../../api/controles';
import { useToast } from '../../context/ToastContext';
import Spinner from '../../components/common/Spinner';
import Badge from '../../components/common/Badge';
import '../../css/components.css';
import '../../css/layout.css';

const mention = (v) => {
  if (v >= 16) return { label: 'Très Bien', color: 'green' };
  if (v >= 14) return { label: 'Bien', color: 'teal' };
  if (v >= 12) return { label: 'Assez Bien', color: 'yellow' };
  if (v >= 10) return { label: 'Passable', color: 'orange' };
  return { label: 'Insuffisant', color: 'red' };
};

export default function Notes() {
  const [notes, setNotes]       = useState([]);
  const [filieres, setFilieres] = useState([]);
  const [niveaux, setNiveaux]   = useState([]);
  const [groupes, setGroupes]   = useState([]);
  const [annees, setAnnees]     = useState([]);
  const [unites, setUnites]     = useState([]);
  const [sequences, setSequences] = useState([]);
  const [controles, setControles] = useState([]);
  const [loading, setLoading]   = useState(false);
  const [editing, setEditing]   = useState({});
  const [sel, setSel] = useState({
    filiere_id: '', niveau_id: '', annee_id: '',
    groupe_id: '', unite_id: '', sequence_id: '', controle_id: ''
  });
  const toast = useToast();

  useEffect(() => {
    Promise.all([adminApi.getFilieres(), adminApi.getAnnees()])
      .then(([f, a]) => { setFilieres(f.data.data); setAnnees(a.data.data); });
  }, []);

  useEffect(() => {
    if (sel.filiere_id) adminApi.getNiveaux(sel.filiere_id).then(r => setNiveaux(r.data.data));
  }, [sel.filiere_id]);

  useEffect(() => {
    if (sel.niveau_id && sel.annee_id) {
      adminApi.getGroupes({ niveau_id: sel.niveau_id, annee_academique_id: sel.annee_id })
        .then(r => setGroupes(r.data.data));
    }
  }, [sel.niveau_id, sel.annee_id]);

  useEffect(() => {
    if (sel.filiere_id) unitesApi.getUnites({ filiere_id: sel.filiere_id }).then(r => setUnites(r.data.data));
  }, [sel.filiere_id]);

  useEffect(() => {
    if (sel.unite_id) sequencesApi.getSequences(sel.unite_id).then(r => setSequences(r.data.data));
  }, [sel.unite_id]);

  useEffect(() => {
    if (sel.sequence_id) controlesApi.getControles(sel.sequence_id).then(r => setControles(r.data.data));
  }, [sel.sequence_id]);

  useEffect(() => {
    if (sel.controle_id && sel.groupe_id) {
      setLoading(true);
      notesApi.getNotes({ controle_id: sel.controle_id, groupe_id: sel.groupe_id })
        .then(r => setNotes(r.data.data))
        .finally(() => setLoading(false));
    }
  }, [sel.controle_id, sel.groupe_id]);

  const handleSave = async (id) => {
    try {
      await notesApi.updateNote(id, editing[id]);
      toast.success('Note mise à jour');
      setEditing(p => { const n = { ...p }; delete n[id]; return n; });
    } catch {
      toast.error('Erreur');
    }
  };

  return (
    <div className="page">
      <div className="page-header">
        <h2 className="page-title">Gestion des Notes</h2>
      </div>

      <div className="filter-bar" style={{ flexWrap: 'wrap', gap: 10 }}>
        <select className="form-select" value={sel.filiere_id} onChange={e => setSel(p => ({ ...p, filiere_id: e.target.value, niveau_id: '', groupe_id: '', unite_id: '', sequence_id: '', controle_id: '' }))}>
          <option value="">Filière</option>
          {filieres.map(f => <option key={f.id} value={f.id}>{f.nom}</option>)}
        </select>

        <select className="form-select" value={sel.annee_id} onChange={e => setSel(p => ({ ...p, annee_id: e.target.value }))} disabled={!sel.filiere_id}>
          <option value="">Année académique</option>
          {annees.map(a => <option key={a.id} value={a.id}>{a.label}</option>)}
        </select>

        <select className="form-select" value={sel.niveau_id} onChange={e => setSel(p => ({ ...p, niveau_id: e.target.value, groupe_id: '' }))} disabled={!sel.filiere_id}>
          <option value="">Niveau</option>
          {niveaux.map(n => <option key={n.id} value={n.id}>{n.numero}ère année</option>)}
        </select>

        <select className="form-select" value={sel.groupe_id} onChange={e => setSel(p => ({ ...p, groupe_id: e.target.value }))} disabled={!sel.niveau_id}>
          <option value="">Groupe</option>
          {groupes.map(g => <option key={g.id} value={g.id}>{g.nom}</option>)}
        </select>

        <select className="form-select" value={sel.unite_id} onChange={e => setSel(p => ({ ...p, unite_id: e.target.value, sequence_id: '', controle_id: '' }))} disabled={!sel.filiere_id}>
          <option value="">Unité</option>
          {unites.map(u => <option key={u.id} value={u.id}>{u.nom}</option>)}
        </select>

        <select className="form-select" value={sel.sequence_id} onChange={e => setSel(p => ({ ...p, sequence_id: e.target.value, controle_id: '' }))} disabled={!sel.unite_id}>
          <option value="">Séquence</option>
          {sequences.map(s => <option key={s.id} value={s.id}>{s.nom}</option>)}
        </select>

        <select className="form-select" value={sel.controle_id} onChange={e => setSel(p => ({ ...p, controle_id: e.target.value }))} disabled={!sel.sequence_id}>
          <option value="">Contrôle</option>
          {controles.map(c => <option key={c.id} value={c.id}>Contrôle {c.numero}</option>)}
        </select>
      </div>

      {!sel.controle_id ? (
        <div className="card card-body" style={{ textAlign: 'center', color: 'var(--gray-400)' }}>
          Sélectionnez une filière, groupe, unité, séquence et contrôle pour voir les notes
        </div>
      ) : loading ? <Spinner /> : (
        <div className="table-wrap">
          <table>
            <thead>
              <tr>
                <th>#</th>
                <th>Stagiaire</th>
                <th>N° Inscription</th>
                <th>Note /20</th>
                <th>Mention</th>
                <th>Statut</th>
                <th>Actions</th>
              </tr>
            </thead>
            <tbody>
              {notes.length === 0 ? (
                <tr><td colSpan={7} className="table-empty">Aucune note pour ce contrôle</td></tr>
              ) : notes.map((n, i) => {
                const m = n.valeur !== null ? mention(n.valeur) : null;
                return (
                  <tr key={n.id}>
                    <td>{i + 1}</td>
                    <td><strong>{n.etudiant?.nom_prenom}</strong></td>
                    <td>{n.etudiant?.numero_inscription}</td>
                    <td>
                      <input
                        type="number"
                        min={0} max={20}
                        step={0.5}
                        className="form-input"
                        style={{ width: 70 }}
                        value={editing[n.id] ?? n.valeur ?? ''}
                        onChange={e => setEditing(p => ({ ...p, [n.id]: e.target.value }))}
                      />
                    </td>
                    <td>{m && <Badge label={m.label} color={m.color} />}</td>
                    <td>
                      <Badge
                        label={n.is_confirmed ? 'Confirmée' : 'En attente'}
                        color={n.is_confirmed ? 'green' : 'gray'}
                      />
                    </td>
                    <td>
                      {editing[n.id] !== undefined && (
                        <button className="btn btn-sm btn-primary" onClick={() => handleSave(n.id)}>
                          Sauvegarder
                        </button>
                      )}
                    </td>
                  </tr>
                );
              })}
            </tbody>
          </table>
        </div>
      )}
    </div>
  );
}