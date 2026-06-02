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
  const [archiveCounts, setArchiveCounts] = useState(null);
  const toast                 = useToast();

  // History modal state
  const [historyYear, setHistoryYear] = useState(null);
  const [historyGroups, setHistoryGroups] = useState([]);
  const [historyLoading, setHistoryLoading] = useState(false);

  // Redoublant modal state
  const [redoublantModal, setRedoublantModal] = useState(null); // { newYearId, students: [...] }
  const [redoublantChecks, setRedoublantChecks] = useState({});
  const [processingRedoublants, setProcessingRedoublants] = useState(false);
  const [checkingRedoublants, setCheckingRedoublants] = useState(false);

  const predictNextYear = (label) => {
    if (!label || !/^\d{4}\/\d{4}$/.test(label)) return '';
    const parts = label.split('/');
    const start = parseInt(parts[0]) + 1;
    return `${start}/${start + 1}`;
  };

  const handleOpenCreate = () => {
    if (currentAnnee && !currentAnnee.is_archived) {
      toast.error(`Vous devez d'abord archiver l'année ${currentAnnee.label} avant d'en créer une nouvelle.`);
      return;
    }
    setLabel(predictNextYear(currentAnnee?.label));
    setStartDate('');
    setEndDate('');
    setOpen(true);
  };
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
      toast.error(e.response?.data?.message ?? 'Erreur');
    } finally {
      setSaving(false);
    }
  };

  const handleSetCurrent = async (newYearId) => {
    setCheckingRedoublants(true);
    try {
      if (!currentAnnee) {
        // No current year — find most recent archived year with groups to transition from
        const fromYear = [...annees]
          .filter(a => a.is_archived && (a.groupes_count || 0) > 0)
          .sort((a, b) => new Date(b.created_at) - new Date(a.created_at))[0];
        if (fromYear) {
          const promoteRes = await transitionToNewYear(fromYear.id, newYearId);
          const promoteMsg = promoteRes.data?.message || '';
          toast.success(`Année mise à jour. ${promoteMsg}`);
        } else {
          await adminApi.setCurrentAnnee(newYearId);
          toast.success('Année courante mise à jour');
        }
        refreshAnnees();
        return;
      }

      // Check for redoublants from the previous year
      const res = await adminApi.checkRedoublants({ from_annee_id: currentAnnee.id });
      const redoublants = res.data.data || [];

      if (redoublants.length === 0) {
        const promoteRes = await transitionToNewYear(currentAnnee.id, newYearId);
        const promoteMsg = promoteRes.data?.message || '';
        toast.success(`Année mise à jour. ${promoteMsg}`);
        refreshAnnees();
        return;
      }

      // Show redoublant modal
      const initialChecks = {};
      redoublants.forEach(s => { initialChecks[s.id] = true; });
      setRedoublantChecks(initialChecks);
      setRedoublantModal({ newYearId, fromAnneeId: currentAnnee.id, students: redoublants });
      setCheckingRedoublants(false);
    } catch (e) {
      const msg = e.response?.data?.message || e.message || 'Erreur lors de la vérification des redoublants';
      toast.error(msg);
    } finally {
      setCheckingRedoublants(false);
    }
  };

  const handleRedoublantConfirm = async () => {
    if (!redoublantModal) return;
    setProcessingRedoublants(true);

    const confirmIds = Object.entries(redoublantChecks)
      .filter(([, checked]) => checked)
      .map(([id]) => parseInt(id));

    const dropIds = Object.entries(redoublantChecks)
      .filter(([, checked]) => !checked)
      .map(([id]) => parseInt(id));

    try {
      const promoteRes = await transitionToNewYear(
        redoublantModal.fromAnneeId,
        redoublantModal.newYearId,
        { confirmIds, dropIds }
      );
      const promoteMsg = promoteRes.data?.message || '';
      toast.success(`Année mise à jour. ${promoteMsg}`);
      setRedoublantModal(null);
      refreshAnnees();
    } catch (e) {
      const msg = e.response?.data?.message || e.message || 'Erreur lors du traitement des redoublants';
      toast.error(msg);
      console.error('Redoublant confirm error:', e.response?.data || e);
    } finally {
      setProcessingRedoublants(false);
    }
  };

  const handleRedoublantDropAll = async () => {
    if (!redoublantModal) return;
    setProcessingRedoublants(true);

    const allIds = redoublantModal.students.map(s => s.id);

    try {
      const promoteRes = await transitionToNewYear(
        redoublantModal.fromAnneeId,
        redoublantModal.newYearId,
        { dropIds: allIds }
      );
      const promoteMsg = promoteRes.data?.message || '';
      toast.success(`Année mise à jour. ${promoteMsg}`);
      setRedoublantModal(null);
      refreshAnnees();
    } catch (e) {
      const msg = e.response?.data?.message || e.message || 'Erreur';
      toast.error(msg);
      console.error('Redoublant drop error:', e.response?.data || e);
    } finally {
      setProcessingRedoublants(false);
    }
  };

  const transitionToNewYear = async (fromId, toId, { confirmIds = [], dropIds = [] } = {}) => {
    await adminApi.copyGroups({ from_annee_id: fromId, to_annee_id: toId });
    if (confirmIds.length > 0)
      await adminApi.confirmRedoublants({ etudiant_ids: confirmIds, to_annee_id: toId });
    if (dropIds.length > 0)
      await adminApi.dropRedoublants({ etudiant_ids: dropIds });
    const promoteRes = await adminApi.promoteAdmis({ from_annee_id: fromId, to_annee_id: toId });
    await adminApi.setCurrentAnnee(toId);
    return promoteRes;
  };

  const handleViewHistory = async (anneeId) => {
    setHistoryLoading(true);
    try {
      const res = await adminApi.getAnneeStats(anneeId);
      setHistoryGroups(res.data.data || {});
      setHistoryYear(annees.find(a => a.id === anneeId) || null);
    } catch (e) {
      toast.error('Erreur lors du chargement');
    } finally {
      setHistoryLoading(false);
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
      toast.error(e.response?.data?.message ?? 'Erreur');
    } finally {
      setSaving(false);
    }
  };

  return (
    <div className="page">
      <div className="page-header">
        <button className="btn btn-primary" onClick={handleOpenCreate}>
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
                <tr key={a.id} className={a.is_archived ? 'row-archived' : ''} style={a.is_archived ? { opacity: 0.65, background: '#f9fafb' } : {}}>
                  <td><strong>{a.label}</strong></td>
                  <td>{a.start_date ? new Date(a.start_date).toLocaleDateString('fr-FR') : '—'}</td>
                  <td>{a.end_date ? new Date(a.end_date).toLocaleDateString('fr-FR') : '—'}</td>
                  <td>
                    <Badge
                      label={a.is_archived ? 'Archivée' : a.is_current ? 'En cours' : 'Inactive'}
                      color={a.is_archived ? 'gray' : a.is_current ? 'green' : 'gray'}
                    />
                  </td>
                  <td>{new Date(a.created_at).toLocaleDateString('fr-FR')}</td>
                  <td style={{ display: 'flex', gap: 8 }}>
                    {a.is_current ? (
                      <button
                        className="btn btn-sm btn-warning"
                        onClick={() => handleArchive(a.id)}
                      >
                        Archiver
                      </button>
                    ) : (
                      <>
                        {a.is_archived && (
                          <button
                            className="btn btn-sm btn-outline"
                            onClick={() => handleViewHistory(a.id)}
                            disabled={historyLoading}
                          >
                            {historyLoading ? 'Chargement...' : 'Afficher'}
                          </button>
                        )}
                        {!a.is_archived && (
                          <button
                            className="btn btn-sm btn-accent"
                            onClick={() => handleSetCurrent(a.id)}
                            disabled={checkingRedoublants}
                          >
                            {checkingRedoublants ? 'Vérification...' : 'Définir comme courante'}
                          </button>
                        )}
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
        <p>Archiver cette année académique ? Elle ne sera plus l'année courante mais toutes les données restent accessibles.</p>
        {archiveCounts && (archiveCounts.etudiants > 0 || archiveCounts.groupes > 0) && (
          <div style={{ marginTop: 16, padding: 16, background: '#f0fdf9', border: '1px solid #a7f3d0', borderRadius: 8 }}>
            <p style={{ color: '#0f766e', fontWeight: 600, margin: 0, marginBottom: 4 }}>
              Cette année contient actuellement :
            </p>
            <ul style={{ margin: 0, paddingLeft: 20, color: '#0f766e' }}>
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

      {checkingRedoublants && (
        <div className="loading-overlay">
          <div className="spinner" />
          <span className="loading-overlay-label">Transition en cours...</span>
        </div>
      )}

      {processingRedoublants && (
        <div className="loading-overlay">
          <div className="spinner" />
          <span className="loading-overlay-label">Traitement en cours...</span>
        </div>
      )}

      {historyLoading && (
        <div className="loading-overlay">
          <div className="spinner" />
          <span className="loading-overlay-label">Chargement des statistiques...</span>
        </div>
      )}

      <Modal open={historyYear !== null} onClose={() => { setHistoryYear(null); setHistoryGroups({}); }} title={`Année archivée ${historyYear?.label || ''}`}>
        {historyYear && historyGroups && (
          <>
            <div style={{ marginBottom: 20 }}>
              <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 16, marginBottom: 16 }}>
                <div style={{ padding: 12, background: '#f3f4f6', borderRadius: 8 }}>
                  <div style={{ fontSize: 12, color: '#666', marginBottom: 4 }}>Diplômés</div>
                  <div style={{ fontSize: 28, fontWeight: 'bold', color: '#059669' }}>{historyGroups.graduates_count || 0}</div>
                </div>
                <div style={{ padding: 12, background: '#f3f4f6', borderRadius: 8 }}>
                  <div style={{ fontSize: 12, color: '#666', marginBottom: 4 }}>Groupes</div>
                  <div style={{ fontSize: 28, fontWeight: 'bold', color: '#1f2937' }}>{historyGroups.groups_count || 0}</div>
                </div>
                <div style={{ padding: 12, background: '#f3f4f6', borderRadius: 8 }}>
                  <div style={{ fontSize: 12, color: '#666', marginBottom: 4 }}>Rejetés/Abandons</div>
                  <div style={{ fontSize: 28, fontWeight: 'bold', color: '#dc2626' }}>{historyGroups.dropped_count || 0}</div>
                </div>
                <div style={{ padding: 12, background: '#f3f4f6', borderRadius: 8 }}>
                  <div style={{ fontSize: 12, color: '#666', marginBottom: 4 }}>Total Étudiants</div>
                  <div style={{ fontSize: 28, fontWeight: 'bold', color: '#1f2937' }}>{historyGroups.total_students || 0}</div>
                </div>
              </div>
            </div>

            {historyGroups.graduates_count > 0 && (
              <>
                <h4 style={{ margin: '16px 0 12px', fontSize: 14, fontWeight: 600 }}>Diplômés par filière</h4>
                {historyGroups.graduates_by_filiere && historyGroups.graduates_by_filiere.length > 0 ? (
                  <div style={{ marginBottom: 16 }}>
                    {historyGroups.graduates_by_filiere.map((item, idx) => (
                      <div key={idx} style={{ marginBottom: 12, padding: 12, background: '#f9fafb', borderRadius: 6, border: '1px solid #e5e7eb' }}>
                        <div style={{ fontWeight: 600, marginBottom: 8 }}>
                          {item.filiere} <span style={{ color: '#666', fontWeight: 'normal' }}>({item.count})</span>
                        </div>
                        <ul style={{ margin: 0, paddingLeft: 20, fontSize: 12 }}>
                          {item.students && item.students.map((s, sidx) => (
                            <li key={sidx} style={{ marginBottom: 4, color: '#374151' }}>
                              {s.nom_prenom} ({s.numero_inscription})
                            </li>
                          ))}
                        </ul>
                      </div>
                    ))}
                  </div>
                ) : null}
              </>
            )}

            <div style={{ marginTop: 16, padding: 12, background: '#eff6ff', border: '1px solid #bfdbfe', borderRadius: 6, fontSize: 12, color: '#1e40af' }}>
              <strong>Année de promotion :</strong> {historyGroups.annee?.label || '—'}
            </div>
          </>
        )}
        <div className="modal-footer">
          <button className="btn btn-outline" onClick={() => { setHistoryYear(null); setHistoryGroups({}); }}>
            Fermer
          </button>
        </div>
      </Modal>

      <Modal open={redoublantModal !== null} onClose={() => !processingRedoublants && setRedoublantModal(null)} title="Redoublants détectés">
        {redoublantModal && (
          <>
            <p style={{ margin: 0, marginBottom: 12 }}>
              <strong>{redoublantModal.students.length} étudiant(s)</strong> de l'année <strong>{currentAnnee?.label}</strong> sont redoublant(s).
              Cochez ceux qui continuent (confirmés) ou décochez ceux qui ne continuent pas (supprimés).
            </p>
            <div className="table-wrap" style={{ maxHeight: 300, overflowY: 'auto' }}>
              <table>
                <thead>
                  <tr>
                    <th style={{ width: 40 }}></th>
                    <th>Nom et Prénom</th>
                    <th>N° Inscription</th>
                    <th>Filière</th>
                    <th>Niveau</th>
                    <th>Moyenne</th>
                  </tr>
                </thead>
                <tbody>
                  {redoublantModal.students.map(s => (
                    <tr key={s.id}>
                      <td style={{ textAlign: 'center' }}>
                        <input
                          type="checkbox"
                          checked={redoublantChecks[s.id] ?? true}
                          onChange={e => setRedoublantChecks(prev => ({ ...prev, [s.id]: e.target.checked }))}
                        />
                      </td>
                      <td>{s.nom_prenom}</td>
                      <td>{s.numero_inscription || '—'}</td>
                      <td>{s.filiere || '—'}</td>
                      <td>{s.niveau || '—'}</td>
                      <td>{s.moyenne !== null ? s.moyenne.toFixed(2) : '—'}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
            <div style={{ marginTop: 12, fontSize: 12, color: '#666' }}>
              <label>
                <input
                  type="checkbox"
                  checked={Object.values(redoublantChecks).every(Boolean) && Object.keys(redoublantChecks).length > 0}
                  onChange={e => {
                    const allChecked = e.target.checked;
                    const updated = {};
                    redoublantModal.students.forEach(s => { updated[s.id] = allChecked; });
                    setRedoublantChecks(updated);
                  }}
                  style={{ marginRight: 6 }}
                />
                Tout sélectionner
              </label>
            </div>
            <div className="modal-footer" style={{ marginTop: 16 }}>
              <button
                className="btn btn-outline"
                onClick={() => setRedoublantModal(null)}
                disabled={processingRedoublants}
              >
                Annuler
              </button>
              <button
                className="btn btn-danger"
                onClick={handleRedoublantDropAll}
                disabled={processingRedoublants}
              >
                Tout supprimer
              </button>
              <button
                className="btn btn-primary"
                onClick={handleRedoublantConfirm}
                disabled={processingRedoublants}
              >
                {processingRedoublants ? 'Traitement...' : 'Confirmer et passer à la nouvelle année'}
              </button>
            </div>
          </>
        )}
      </Modal>
    </div>
  );
}