import { useEffect, useState, useCallback } from 'react';
import { adminApi } from '../../api/admin';
import { sequencesApi } from '../../api/sequences';
import { unitesApi } from '../../api/unites';
import { useToast } from '../../context/ToastContext';
import { useAnneeAcademique } from '../../context/AnneeAcademiqueContext';
import Modal from '../../components/common/Modal';
import Spinner from '../../components/common/Spinner';
import Badge from '../../components/common/Badge';
import FilterBar from '../../components/common/FilterBar';
import FilterSelect from '../../components/common/FilterSelect';
import { formatNiveau } from '../../utils/helpers';
import { FiEdit2, FiTrash2 } from 'react-icons/fi';
import usePersistedState from '../../hooks/usePersistedState';
import '../../css/components.css';
import '../../css/layout.css';

export default function Sequences() {
  const [sequences, setSequences] = useState([]);
  const [filieres, setFilieres] = useState([]);
  const [niveaux, setNiveaux] = useState([]);
  const [groupes, setGroupes] = useState([]);
  const [unites, setUnites] = useState([]);
  const [loading, setLoading] = useState(true);
  const [open, setOpen] = useState(false);
  const [saving, setSaving] = useState(false);
  const [saved, setSaved] = usePersistedState('sequences', { filiere_id: '', niveau_id: '', groupe_id: '', selUnite: '' });
  const [filters, setFilters] = useState(() => ({ filiere_id: saved.filiere_id, niveau_id: saved.niveau_id, groupe_id: saved.groupe_id }));
  const [selUnite, setSelUnite] = useState(() => saved.selUnite);

  useEffect(() => {
    setSaved({ filiere_id: filters.filiere_id, niveau_id: filters.niveau_id, groupe_id: filters.groupe_id, selUnite });
  }, [filters, selUnite, setSaved]);
  const [form, setForm] = useState({
    unite_id: '', nom: '', nombre_controles: 2
  });
  const [deleteId, setDeleteId] = useState(null);
  const [editSeq, setEditSeq] = useState(null);
  const [editForm, setEditForm] = useState({ nom: '', nombre_controles: 2 });
  const [reduceConfirm, setReduceConfirm] = useState(null);
  const toast = useToast();
  const { currentAnnee } = useAnneeAcademique();

  const load = useCallback(() => {
    if (!selUnite) {
      setSequences([]);
      return Promise.resolve();
    }
    setLoading(true);
    return sequencesApi.getSequences(selUnite)
      .then(res => setSequences(res.data.data))
      .catch((err) => {
        console.error('Failed to load sequences:', err);
        toast.error('Erreur');
      })
      .finally(() => setLoading(false));
  }, [selUnite]);

  useEffect(() => {
    load();
  }, [load]);

  useEffect(() => {
    adminApi.getFilieres().then(r => setFilieres(r.data.data));
  }, []);

  useEffect(() => {
    const filiereId = filters.filiere_id;
    if (!filiereId) {
      setNiveaux([]);
      setFilters(p => ({ ...p, niveau_id: '', groupe_id: '' }));
      setSelUnite('');
      setUnites([]);
      return;
    }
    adminApi.getNiveaux(filiereId).then(r => {
      const niveauxData = r.data.data || [];
      setNiveaux(niveauxData);
      setFilters(p => {
        if (p.niveau_id && !niveauxData.some(n => String(n.id) === String(p.niveau_id))) {
          return { ...p, niveau_id: '', groupe_id: '' };
        }
        return p;
      });
    }).catch(() => setNiveaux([]));
  }, [filters.filiere_id]);

  useEffect(() => {
    const niveauId = filters.niveau_id;
    if (!niveauId) {
      setGroupes([]);
      setUnites([]);
      setSelUnite('');
      setFilters(p => ({ ...p, groupe_id: '' }));
      return;
    }
    const params = { niveau_id: niveauId };
    if (currentAnnee?.id) {
      params.annee_academique_id = currentAnnee.id;
    }
    adminApi.getGroupes(params).then(r => {
      const groupesData = r.data.data || [];
      setGroupes(groupesData);
      setFilters(p => {
        if (p.groupe_id && !groupesData.some(g => String(g.id) === String(p.groupe_id))) {
          return { ...p, groupe_id: '' };
        }
        return p;
      });
    }).catch(() => setGroupes([]));
    unitesApi.getUnites({ niveau_id: niveauId }).then(r => {
      const unitesData = r.data.data || [];
      setUnites(unitesData);
      setSelUnite(cur => (unitesData.some(u => String(u.id) === String(cur)) ? cur : ''));
    }).catch(() => setUnites([]));
  }, [filters.niveau_id, currentAnnee]);

  const handleCreate = async () => {
    setSaving(true);
    try {
      await sequencesApi.createSequence({
        ...form,
        unite_id: selUnite,
      });
      toast.success('Séquence créée');
      setOpen(false);
      setForm({ unite_id: '', nom: '', nombre_controles: 2 });
      load();
    } catch (e) {
      toast.error(e.response?.data?.message ?? 'Erreur');
    } finally {
      setSaving(false);
    }
  };

  const handleDelete = (id) => {
    setDeleteId(id);
  };

  const openEdit = (s) => {
    setEditSeq(s);
    setEditForm({ nom: s.nom, nombre_controles: s.nombre_controles });
  };

  const handleUpdate = async () => {
    const payload = {
      nom: editForm.nom,
      nombre_controles: Number(editForm.nombre_controles),
    };

    setSaving(true);
    try {
      await sequencesApi.updateSequence(editSeq.id, payload);
    } catch (e) {
      const res = e.response?.data;
      if (e.response?.status === 409 && res?.requires_confirmation) {
        setReduceConfirm({ notesCount: res.notes_count, payload });
        return;
      }
      toast.error(res?.message ?? 'Erreur');
      return;
    } finally {
      setSaving(false);
    }

    toast.success('Séquence mise à jour');
    setEditSeq(null);
    load();
  };

  const confirmReduce = async () => {
    setSaving(true);
    try {
      await sequencesApi.updateSequence(editSeq.id, { ...reduceConfirm.payload, force: true });
      toast.success('Séquence mise à jour');
      setReduceConfirm(null);
      setEditSeq(null);
      load();
    } catch (e) {
      toast.error(e.response?.data?.message ?? 'Erreur');
    } finally {
      setSaving(false);
    }
  };

  const confirmDelete = async () => {
    try {
      await sequencesApi.deleteSequence(deleteId);
      toast.success('Séquence supprimée');
      setDeleteId(null);
      load();
    } catch (err) {
      console.error('Failed to delete sequence:', err);
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
      </div>

      <FilterBar
        value={{ filiere_id: filters.filiere_id, niveau_id: filters.niveau_id, groupe_id: filters.groupe_id, unite_id: selUnite }}
        onChange={(next) => {
          setFilters({ filiere_id: next.filiere_id ?? '', niveau_id: next.niveau_id ?? '', groupe_id: next.groupe_id ?? '' });
        }}
        placeholder="Filtrer…"
        debounceMs={0}
        showSearch={false}
        onClear={() => {
          setFilters({ filiere_id: '', niveau_id: '', groupe_id: '' });
          setSelUnite('');
        }}
        actions={(
          <button
            className="btn btn-primary"
            onClick={() => setOpen(true)}
            disabled={!selUnite}
          >
            + Nouvelle séquence
          </button>
        )}
        chips={[
          filters.filiere_id && {
            key: 'filiere',
            label: `Filière: ${filieres.find(f => String(f.id) === String(filters.filiere_id))?.nom ?? ''}`,
            onRemove: () => {
              setFilters(p => ({ ...p, filiere_id: '', niveau_id: '', groupe_id: '' }));
              setSelUnite('');
            },
          },
          filters.niveau_id && {
            key: 'niveau',
            label: `Niveau: ${formatNiveau(niveaux.find(n => String(n.id) === String(filters.niveau_id))?.numero)}`,
            onRemove: () => {
              setFilters(p => ({ ...p, niveau_id: '', groupe_id: '' }));
              setSelUnite('');
            },
          },
          filters.groupe_id && {
            key: 'groupe',
            label: `Groupe: ${groupes.find(g => String(g.id) === String(filters.groupe_id))?.nom ?? ''}`,
            onRemove: () => setFilters(p => ({ ...p, groupe_id: '' })),
          },
          selUnite && {
            key: 'unite',
            label: `Unité: ${unites.find(u => String(u.id) === String(selUnite))?.nom ?? ''}`,
            onRemove: () => setSelUnite(''),
          },
        ].filter(Boolean)}
      >
        <FilterSelect
          label="Filière"
          value={filters.filiere_id}
          options={filieres.map(f => ({ id: f.id, label: f.nom }))}
          onChange={(v) => {
            setFilters(p => ({ ...p, filiere_id: v, niveau_id: '', groupe_id: '' }));
            setSelUnite('');
          }}
          placeholder="Toutes les filières"
          allLabel="Toutes les filières"
        />
        <FilterSelect
          label="Niveau"
          value={filters.niveau_id}
          options={niveaux.map(n => ({ id: n.id, label: formatNiveau(n.numero) }))}
          onChange={(v) => {
            setFilters(p => ({ ...p, niveau_id: v, groupe_id: '' }));
            setSelUnite('');
          }}
          disabled={!filters.filiere_id}
          placeholder="Tous les niveaux"
          allLabel="Tous les niveaux"
        />
        <FilterSelect
          label="Groupe"
          value={filters.groupe_id}
          options={groupes.map(g => ({ id: g.id, label: g.nom }))}
          onChange={(v) => setFilters(p => ({ ...p, groupe_id: v }))}
          disabled={!filters.niveau_id}
          placeholder="Tous les groupes"
          allLabel="Tous les groupes"
        />
        <FilterSelect
          label="Unité"
          value={selUnite}
          options={unites.map(u => ({ id: u.id, label: u.nom }))}
          onChange={setSelUnite}
          disabled={!filters.niveau_id}
          placeholder="Sélectionner une unité"
          allLabel="Sélectionner une unité"
        />
      </FilterBar>

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
                <th>Nb Contrôles</th>
                <th>Statut</th>
                <th>Actions</th>
              </tr>
            </thead>
            <tbody>
              {sequences.length === 0 ? (
                <tr><td colSpan={5} className="table-empty">Aucune séquence</td></tr>
              ) : sequences.map((s, i) => (
                <tr key={s.id}>
                  <td>{i + 1}</td>
                  <td><strong>{s.nom}</strong></td>
                  <td><Badge label={`${s.nombre_controles} contrôle(s)`} color="blue" /></td>
                  <td>
                    <Badge
                      label={s.is_active ? 'Actif' : 'Inactif'}
                      color={s.is_active ? 'green' : 'red'}
                    />
                  </td>
                  <td style={{ display: 'flex', gap: 8 }}>
                    <button
                      className="btn btn-sm btn-outline"
                      onClick={() => openEdit(s)}
                      title="Modifier"
                    >
                      <FiEdit2 size={14} /> Modifier
                    </button>
                    <button
                      className="btn btn-sm btn-outline"
                      onClick={() => handleToggle(s.id)}
                    >
                      {s.is_active ? 'Désactiver' : 'Activer'}
                    </button>
                    <button
                      className="btn btn-sm btn-danger"
                      onClick={() => handleDelete(s.id)}
                      title="Supprimer"
                      aria-label="Supprimer"
                      style={{ padding: '5px 9px' }}
                    >
                      <FiTrash2 size={14} />
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
            <label className="form-label">Nombre de contrôles</label>
            <select
              className="form-select"
              value={form.nombre_controles}
              onChange={e => setForm(p => ({ ...p, nombre_controles: e.target.value }))}
            >
              {[1, 2, 3, 4, 5, 6, 7, 8, 9, 10].map(n => (
                <option key={n} value={n}>{n}</option>
              ))}
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

        <Modal open={editSeq !== null} onClose={() => setEditSeq(null)} title="Modifier la Séquence">
          <div className="form-group">
            <label className="form-label">Nom de la séquence</label>
            <input
              className="form-input"
              value={editForm.nom}
              onChange={e => setEditForm(p => ({ ...p, nom: e.target.value }))}
            />
          </div>
          <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 12 }}>
            <div className="form-group">
              <label className="form-label">Nombre de contrôles</label>
              <select
                className="form-select"
                value={editForm.nombre_controles}
                onChange={e => setEditForm(p => ({ ...p, nombre_controles: e.target.value }))}
              >
                {[1, 2, 3, 4, 5, 6, 7, 8, 9, 10].map(n => (
                  <option key={n} value={n}>{n}</option>
                ))}
              </select>
            </div>
          </div>
          <div className="modal-footer">
            <button className="btn btn-outline" onClick={() => setEditSeq(null)}>Annuler</button>
            <button className="btn btn-primary" onClick={handleUpdate} disabled={saving || !editForm.nom.trim()}>
              {saving ? 'Enregistrement...' : 'Enregistrer'}
            </button>
          </div>
        </Modal>

        <Modal open={deleteId !== null} onClose={() => setDeleteId(null)} title="Confirmer la suppression">
          <p>Supprimer cette séquence ?</p>
          <div className="modal-footer">
            <button className="btn btn-outline" onClick={() => setDeleteId(null)}>Annuler</button>
            <button className="btn btn-danger" onClick={confirmDelete}>Supprimer</button>
          </div>
        </Modal>

        <Modal open={reduceConfirm !== null} onClose={() => setReduceConfirm(null)} title="Confirmer la réduction">
          <p>
            Réduire le nombre de contrôles supprimera <strong>{reduceConfirm?.notesCount}</strong> note(s)
            déjà saisie(s) sur les contrôles retirés. Cette action est irréversible.
          </p>
          <div className="modal-footer">
            <button className="btn btn-outline" onClick={() => setReduceConfirm(null)}>Annuler</button>
            <button className="btn btn-danger" onClick={confirmReduce} disabled={saving}>
              {saving ? 'Suppression...' : 'Confirmer'}
            </button>
          </div>
        </Modal>
    </div>
  );
}
