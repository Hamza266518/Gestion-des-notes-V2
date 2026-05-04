import { useEffect, useState, useCallback } from 'react';
import { adminApi } from '../../api/admin';
import { useToast } from '../../context/ToastContext';
import { useAnneeAcademique } from '../../context/AnneeAcademiqueContext';
import Modal from '../../components/common/Modal';
import Badge from '../../components/common/Badge';
import Spinner from '../../components/common/Spinner';
import '../../css/components.css';
import '../../css/layout.css';

export default function AnneesAcademiques() {
  const { annees, loading, refreshAnnees } = useAnneeAcademique();
  const [open, setOpen]       = useState(false);
  const [label, setLabel]     = useState('');
  const [saving, setSaving]   = useState(false);
  const [archiveId, setArchiveId] = useState(null);
  const [deleteId, setDeleteId] = useState(null);
  const toast                 = useToast();

  const handleCreate = async () => {
    if (!label.trim()) return;
    setSaving(true);
    try {
      await adminApi.createAnnee(label.trim());
      toast.success('Année créée avec succès');
      setOpen(false);
      setLabel('');
      refreshAnnees();
    } catch (e) {
      console.error('Failed to create academic year:', e);
      toast.error(e.response?.data?.message ?? 'Erreur');
    } finally {
      setSaving(false);
    }
  };

  const handleSetCurrent = async (id) => {
    try {
      await adminApi.setCurrentAnnee(id);
      toast.success('Année courante mise à jour');
      refreshAnnees();
    } catch (e) {
      console.error('Failed to set current year:', e);
      toast.error('Erreur');
    }
  };

  const handleDelete = async (id) => {
    setDeleteId(id);
  };

  const confirmDelete = async () => {
    try {
      await adminApi.deleteAnnee(deleteId);
      toast.success('Année supprimée');
      setDeleteId(null);
      refreshAnnees();
    } catch (e) {
      console.error('Failed to delete academic year:', e);
      toast.error(e.response?.data?.message ?? 'Erreur');
    }
  };

  const handleArchive = (id) => {
    setArchiveId(id);
  };

  const confirmArchive = async () => {
    setSaving(true);
    try {
      await adminApi.archiveAnnee(archiveId);
      toast.success('Année archivée');
      setArchiveId(null);
      refreshAnnees();
    } catch (e) {
      console.error('Failed to archive year:', e);
      toast.error(e.response?.data?.message ?? 'Erreur');
    } finally {
      setSaving(false);
    }
  };

  return (
    <div className="page">
      <div className="page-header">
        <h2 className="page-title">Années Académiques</h2>
        <button className="btn btn-primary" onClick={() => setOpen(true)}>
          + Nouvelle année
        </button>
      </div>

      {loading ? <Spinner /> : (
        <div className="table-wrap">
          <table>
            <thead>
              <tr>
                <th>Année</th>
                <th>Statut</th>
                <th>Créée le</th>
                <th>Actions</th>
              </tr>
            </thead>
            <tbody>
              {annees.length === 0 ? (
                <tr>
                  <td colSpan={4} className="table-empty">
                    Aucune année académique
                  </td>
                </tr>
              ) : annees.map(a => (
                <tr key={a.id}>
                  <td><strong>{a.label}</strong></td>
                  <td>
                    <Badge
                      label={a.is_current ? 'En cours' : 'Inactive'}
                      color={a.is_current ? 'green' : 'gray'}
                    />
                  </td>
                  <td>{new Date(a.created_at).toLocaleDateString('fr-FR')}</td>
                  <td style={{ display: 'flex', gap: 8 }}>
                    {a.is_current ? (
                      <button
                        className="btn btn-sm btn-outline"
                        onClick={() => handleArchive(a.id)}
                      >
                        Archiver
                      </button>
                    ) : (
                      <>
                        <button
                          className="btn btn-sm btn-accent"
                          onClick={() => handleSetCurrent(a.id)}
                        >
                          Définir comme courante
                        </button>
                        <button
                          className="btn btn-sm btn-danger"
                          onClick={() => handleDelete(a.id)}
                        >
                          Supprimer
                        </button>
                      </>
                    )}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}

      <Modal open={open} onClose={() => setOpen(false)} title="Nouvelle Année Académique">
        <div className="form-group">
          <label className="form-label">Label</label>
          <input
            className="form-input"
            placeholder="ex: 2025/2026"
            value={label}
            onChange={e => setLabel(e.target.value)}
          />
        </div>
        <div className="modal-footer">
          <button className="btn btn-outline" onClick={() => setOpen(false)}>
            Annuler
          </button>
          <button
            className="btn btn-primary"
            onClick={handleCreate}
            disabled={saving}
          >
            {saving ? 'Création...' : 'Créer'}
          </button>
        </div>
      </Modal>

      <Modal open={archiveId !== null} onClose={() => setArchiveId(null)} title="Archiver l'année">
        <p>Archiver cette année académique ? Elle ne sera plus l'année courante.</p>
        <div className="modal-footer">
          <button className="btn btn-outline" onClick={() => setArchiveId(null)}>Annuler</button>
          <button className="btn btn-primary" onClick={confirmArchive} disabled={saving}>
            {saving ? 'Archivage...' : 'Archiver'}
          </button>
        </div>
      </Modal>

      <Modal open={deleteId !== null} onClose={() => setDeleteId(null)} title="Supprimer l'année">
        <p>Supprimer cette année académique ? Cette action est irréversible.</p>
        <div className="modal-footer">
          <button className="btn btn-outline" onClick={() => setDeleteId(null)}>Annuler</button>
          <button className="btn btn-danger" onClick={confirmDelete}>Supprimer</button>
        </div>
      </Modal>
    </div>
  );
}