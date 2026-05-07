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
  const { currentAnnee, annees, loading, refreshAnnees, warning } = useAnneeAcademique();
  const [open, setOpen]       = useState(false);
  const [label, setLabel]     = useState('');
  const [startDate, setStartDate] = useState('');
  const [endDate, setEndDate] = useState('');
  const [saving, setSaving]   = useState(false);
  const [archiveId, setArchiveId] = useState(null);
  const [deleteId, setDeleteId] = useState(null);
  const [archiveCounts, setArchiveCounts] = useState(null);
  const toast                 = useToast();

  const handleCreate = async () => {
    if (!label.trim()) return;
    setSaving(true);
    try {
      await adminApi.createAnnee({
        label: label.trim(),
        start_date: startDate || null,
        end_date: endDate || null,
      });
      toast.success('Année créée avec succès');
      setOpen(false);
      setLabel('');
      setStartDate('');
      setEndDate('');
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

  const handleDelete = (id) => {
    const annee = annees.find(a => a.id === id);
    setArchiveCounts({
      etudiants: annee?.etudiants_count || 0,
      groupes: annee?.groupes_count || 0,
    });
    setDeleteId(id);
  };

  const confirmDelete = async () => {
    try {
      await adminApi.deleteAnnee(deleteId);
      toast.success('Année supprimée');
      setDeleteId(null);
      setArchiveCounts(null);
      refreshAnnees();
    } catch (e) {
      console.error('Failed to delete academic year:', e);
      toast.error(e.response?.data?.message ?? 'Erreur');
    }
  };

  const handleArchive = (id) => {
    const annee = annees.find(a => a.id === id);
    setArchiveCounts({
      etudiants: annee?.etudiants_count || 0,
      groupes: annee?.groupes_count || 0,
    });
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

      {warning && (
        <div className="alert alert-warning" style={{ marginBottom: 16 }}>
          <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
            <span>{warning.message}</span>
            {warning.can_archive && (
              <button className="btn btn-sm btn-warning" onClick={() => handleArchive(currentAnnee.id)}>
                Archiver maintenant
              </button>
            )}
          </div>
        </div>
      )}

      {loading ? <Spinner /> : (
        <div className="table-wrap">
          <table>
            <thead>
              <tr>
                <th>Année</th>
                <th>Date début</th>
                <th>Date fin</th>
                <th>Statut</th>
                <th>Créée le</th>
                <th>Actions</th>
              </tr>
            </thead>
            <tbody>
              {annees.length === 0 ? (
                <tr>
                  <td colSpan={6} className="table-empty">
                    Aucune année académique
                  </td>
                </tr>
              ) : annees.map(a => (
                <tr key={a.id}>
                  <td><strong>{a.label}</strong></td>
                  <td>{a.start_date ? new Date(a.start_date).toLocaleDateString('fr-FR') : '—'}</td>
                  <td>{a.end_date ? new Date(a.end_date).toLocaleDateString('fr-FR') : '—'}</td>
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
        <div className="form-group">
          <label className="form-label">Date de début</label>
          <input
            type="date"
            className="form-input"
            value={startDate}
            onChange={e => setStartDate(e.target.value)}
          />
        </div>
        <div className="form-group">
          <label className="form-label">Date de fin</label>
          <input
            type="date"
            className="form-input"
            value={endDate}
            onChange={e => setEndDate(e.target.value)}
          />
        </div>
        <div className="modal-footer">
          <button className="btn btn-outline" onClick={() => { setOpen(false); setStartDate(''); setEndDate(''); }}>
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

      <Modal open={archiveId !== null} onClose={() => { setArchiveId(null); setArchiveCounts(null); }} title="Archiver l'année">
        <p>Archiver cette année académique ? Elle ne sera plus l'année courante.</p>
        {archiveCounts && (archiveCounts.etudiants > 0 || archiveCounts.groupes > 0) && (
          <div style={{ marginTop: 16, padding: 16, background: '#fef2f2', border: '1px solid #fca5a5', borderRadius: 8 }}>
            <p style={{ color: '#dc2626', fontWeight: 600, margin: 0, marginBottom: 8 }}>
              ⚠ Attention : archiver cette année supprimera définitivement les données associées :
            </p>
            <ul style={{ margin: 0, paddingLeft: 20, color: '#dc2626' }}>
              {archiveCounts.etudiants > 0 && <li><strong>{archiveCounts.etudiants}</strong> étudiant(s)</li>}
              {archiveCounts.groupes > 0 && <li><strong>{archiveCounts.groupes}</strong> groupe(s)</li>}
            </ul>
          </div>
        )}
        <div className="modal-footer">
          <button className="btn btn-outline" onClick={() => { setArchiveId(null); setArchiveCounts(null); }}>Annuler</button>
          <button className="btn btn-danger" onClick={confirmArchive} disabled={saving}>
            {saving ? 'Archivage...' : 'Archiver'}
          </button>
        </div>
      </Modal>

      <Modal open={deleteId !== null} onClose={() => { setDeleteId(null); setArchiveCounts(null); }} title="Supprimer l'année">
        <p>Supprimer cette année académique ? Cette action est irréversible.</p>
        {archiveCounts && (archiveCounts.etudiants > 0 || archiveCounts.groupes > 0) && (
          <div style={{ marginTop: 16, padding: 16, background: '#fef2f2', border: '1px solid #fca5a5', borderRadius: 8 }}>
            <p style={{ color: '#dc2626', fontWeight: 600, margin: 0, marginBottom: 8 }}>
              ⚠ Impossible de supprimer car cette année contient des données :
            </p>
            <ul style={{ margin: 0, paddingLeft: 20, color: '#dc2626' }}>
              {archiveCounts.etudiants > 0 && <li><strong>{archiveCounts.etudiants}</strong> étudiant(s)</li>}
              {archiveCounts.groupes > 0 && <li><strong>{archiveCounts.groupes}</strong> groupe(s)</li>}
            </ul>
            <p style={{ color: '#991b1b', margin: '8px 0 0', fontSize: 13 }}>
              Supprimez d'abord les groupes et étudiants associés, ou archivez l'année.
            </p>
          </div>
        )}
        <div className="modal-footer">
          <button className="btn btn-outline" onClick={() => { setDeleteId(null); setArchiveCounts(null); }}>Annuler</button>
          <button className="btn btn-danger" onClick={confirmDelete} disabled={archiveCounts && (archiveCounts.etudiants > 0 || archiveCounts.groupes > 0)}>Supprimer</button>
        </div>
      </Modal>
    </div>
  );
}