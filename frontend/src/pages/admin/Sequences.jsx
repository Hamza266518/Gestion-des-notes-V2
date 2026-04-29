import { useEffect, useState, useCallback } from 'react';
import { adminApi } from '../../api/admin';
import { sequencesApi } from '../../api/sequences';
import { unitesApi } from '../../api/unites';
import { useToast } from '../../context/ToastContext';
import Modal from '../../components/common/Modal';
import Spinner from '../../components/common/Spinner';
import Badge from '../../components/common/Badge';
import '../../css/components.css';
import '../../css/layout.css';

export default function Sequences() {
  const [sequences, setSequences] = useState([]);
  const [filieres, setFilieres]   = useState([]);
  const [niveaux, setNiveaux]     = useState([]);
  const [groupes, setGroupes]     = useState([]);
  const [unites, setUnites]       = useState([]);
  const [loading, setLoading]     = useState(true);
  const [open, setOpen]           = useState(false);
  const [saving, setSaving]       = useState(false);
  const [selFiliere, setSelFiliere] = useState('');
  const [selNiveau, setSelNiveau]   = useState('');
  const [selUnite, setSelUnite]   = useState('');
  const [filters, setFilters]     = useState({ groupe_id: '', filiere_id: '', niveau_id: '' });
  const [form, setForm] = useState({
    unite_id: '', nom: '', coefficient: '', nombre_controles: 2
  });
  const toast = useToast();

  const load = useCallback(() => {
    if (!selUnite) {
      setSequences([]);
      setLoading(false);
      return;
    }
    setLoading(true);
    sequencesApi.getSequences(selUnite)
      .then(res => setSequences(res.data.data))
      .catch(() => toast.error('Erreur'))
      .finally(() => setLoading(false));
  }, [selUnite, toast]);

  useEffect(() => {
    setLoading(true);
    load()
      .finally(() => setLoading(false));
  }, [load]);

  useEffect(() => {
    adminApi.getFilieres().then(r => setFilieres(r.data.data));
  }, []);

  useEffect(() => {
    if (selFiliere) {
      adminApi.getNiveaux(selFiliere).then(r => setNiveaux(r.data.data));
    } else {
      setNiveaux([]);
    }
    setSelNiveau('');
    setSelUnite('');
    setUnites([]);
    setFilters(p => ({ ...p, niveau_id: '', groupe_id: '' }));
  }, [selFiliere]);

  useEffect(() => {
    if (selNiveau) {
      adminApi.getGroupes({ niveau_id: selNiveau }).then(r => setGroupes(r.data.data));
      unitesApi.getUnites({ niveau_id: selNiveau }).then(r => setUnites(r.data.data));
    } else {
      setGroupes([]);
      setUnites([]);
    }
    setSelUnite('');
    setFilters(p => ({ ...p, groupe_id: '' }));
  }, [selNiveau]);

  const handleCreate = async () => {
    setSaving(true);
    try {
      await sequencesApi.createSequence({ ...form, unite_id: selUnite });
      toast.success('Séquence créée');
      setOpen(false);
      setForm({ unite_id: '', nom: '', coefficient: '', nombre_controles: 2 });
      load();
    } catch (e) {
      toast.error(e.response?.data?.message ?? 'Erreur');
    } finally {
      setSaving(false);
    }
  };

  const handleDelete = async (id) => {
    if (!confirm('Supprimer cette séquence ?')) return;
    try {
      await sequencesApi.deleteSequence(id);
      toast.success('Séquence supprimée');
      load();
    } catch {
      toast.error('Erreur');
    }
  };

  const handleToggle = async (id) => {
    try {
      await sequencesApi.toggleSequence(id);
      load();
    } catch {
      toast.error('Erreur');
    }
  };

  return (
    <div className="page">
      <div className="page-header">
        <h2 className="page-title">Séquences</h2>
        <button
          className="btn btn-primary"
          onClick={() => setOpen(true)}
          disabled={!selUnite}
        >
          + Nouvelle séquence
        </button>
      </div>

      <div className="filter-bar">
        <select
          className="form-select"
          value={filters.filiere_id}
          onChange={e => {
            setFilters(p => ({ ...p, filiere_id: e.target.value, niveau_id: '', groupe_id: '' }));
            setSelFiliere(e.target.value);
            setSelNiveau('');
            setSelUnite('');
          }}
        >
          <option value="">Toutes les filières</option>
          {filieres.map(f => <option key={f.id} value={f.id}>{f.nom}</option>)}
        </select>
        <select
          className="form-select"
          value={filters.niveau_id}
          onChange={e => {
            setFilters(p => ({ ...p, niveau_id: e.target.value, groupe_id: '' }));
            setSelNiveau(e.target.value);
            setSelUnite('');
          }}
          disabled={!filters.filiere_id}
        >
          <option value="">Tous les niveaux</option>
          {niveaux.map(n => {
            const suffix = n.numero == 1 ? 'ère' : 'ème';
            return <option key={n.id} value={n.id}>{n.numero}{suffix} année</option>;
          })}
        </select>
        <select
          className="form-select"
          value={filters.groupe_id}
          onChange={e => setFilters(p => ({ ...p, groupe_id: e.target.value }))}
          disabled={!filters.niveau_id}
        >
          <option value="">Tous les groupes</option>
          {groupes.map(g => <option key={g.id} value={g.id}>{g.nom}</option>)}
        </select>
        <select
          className="form-select"
          value={selUnite}
          onChange={e => setSelUnite(e.target.value)}
          disabled={!filters.niveau_id}
        >
          <option value="">Sélectionner une unité</option>
          {unites.map(u => <option key={u.id} value={u.id}>{u.nom}</option>)}
        </select>
      </div>

      {!selUnite ? (
        <div className="card card-body" style={{ textAlign: 'center', color: 'var(--gray-400)' }}>
          Sélectionnez une filière, un niveau et une unité pour voir les séquences
        </div>
      ) : loading ? <Spinner /> : (
        <div className="table-wrap">
          <table>
            <thead>
              <tr>
                <th>#</th>
                <th>Nom</th>
                <th>Coefficient</th>
                <th>Nb Contrôles</th>
                <th>Statut</th>
                <th>Actions</th>
              </tr>
            </thead>
            <tbody>
              {sequences.length === 0 ? (
                <tr><td colSpan={6} className="table-empty">Aucune séquence</td></tr>
              ) : sequences.map((s, i) => (
                <tr key={s.id}>
                  <td>{i + 1}</td>
                  <td><strong>{s.nom}</strong></td>
                  <td><Badge label={`Coeff ${s.coefficient}`} color="blue" /></td>
                  <td><Badge label={`${s.nombre_controles} contrôle(s)`} color="gray" /></td>
                  <td>
                    <Badge
                      label={s.is_active ? 'Actif' : 'Inactif'}
                      color={s.is_active ? 'green' : 'red'}
                    />
                  </td>
                  <td style={{ display: 'flex', gap: 8 }}>
                    <button
                      className="btn btn-sm btn-outline"
                      onClick={() => handleToggle(s.id)}
                    >
                      {s.is_active ? 'Désactiver' : 'Activer'}
                    </button>
                    <button
                      className="btn btn-sm btn-danger"
                      onClick={() => handleDelete(s.id)}
                    >
                      Supprimer
                    </button>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}

      <Modal open={open} onClose={() => setOpen(false)} title="Nouvelle Séquence">
        <div className="form-group">
          <label className="form-label">Nom de la séquence</label>
          <input
            className="form-input"
            value={form.nom}
            onChange={e => setForm(p => ({ ...p, nom: e.target.value }))}
            placeholder="ex: Hygiène individuelle"
          />
        </div>
        <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 12 }}>
          <div className="form-group">
            <label className="form-label">Coefficient</label>
            <input
              type="number"
              className="form-input"
              value={form.coefficient}
              onChange={e => setForm(p => ({ ...p, coefficient: e.target.value }))}
            />
          </div>
          <div className="form-group">
            <label className="form-label">Nombre de contrôles</label>
            <select
              className="form-select"
              value={form.nombre_controles}
              onChange={e => setForm(p => ({ ...p, nombre_controles: e.target.value }))}
            >
              <option value="1">1</option>
              <option value="2">2</option>
              <option value="3">3</option>
            </select>
          </div>
        </div>
        <div className="modal-footer">
          <button className="btn btn-outline" onClick={() => setOpen(false)}>Annuler</button>
          <button className="btn btn-primary" onClick={handleCreate} disabled={saving}>
            {saving ? 'Création...' : 'Créer'}
          </button>
        </div>
      </Modal>
    </div>
  );
}
