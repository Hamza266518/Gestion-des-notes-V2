import { useEffect, useState, useCallback } from 'react';
import { adminApi } from '../../api/admin';
import { useToast } from '../../context/ToastContext';
import { useAnneeAcademique } from '../../context/AnneeAcademiqueContext';
import Modal from '../../components/common/Modal';
import ArchiveAnneeModal from '../../components/admin/ArchiveAnneeModal';
import Badge from '../../components/common/Badge';
import Spinner from '../../components/common/Spinner';
import FilterBar from '../../components/common/FilterBar';
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
  const [editingId, setEditingId] = useState(null);
  const [editLabel, setEditLabel] = useState('');
  const [editStart, setEditStart] = useState('');
  const [editEnd, setEditEnd] = useState('');
  const toast                 = useToast();

  // History modal state
  const [historyYear, setHistoryYear] = useState(null);
  const [historyGroups, setHistoryGroups] = useState([]);
  const [historyLoading, setHistoryLoading] = useState(false);

  // Read-only archive viewer (relevé + bulletins) for a chosen year
  const [archiveViewYear, setArchiveViewYear] = useState(null);

  // Redoublant modal state
  const [redoublantModal, setRedoublantModal] = useState(null); // { newYearId, students: [...] }
  const [redoublantChecks, setRedoublantChecks] = useState({});
  const [processingRedoublants, setProcessingRedoublants] = useState(false);
  const [checkingRedoublants, setCheckingRedoublants] = useState(false);

  // Destination picker, shown when a niveau runs several groups in the new year
  const [ambiguousModal, setAmbiguousModal] = useState(null); // { fromAnneeId, toAnneeId, entries: [...] }
  const [groupChoices, setGroupChoices] = useState({});
  const [resolvingGroups, setResolvingGroups] = useState(false);

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
    // Guarded: with no current year the old call yielded "NaN/NaN" in the field.
    setLabel(currentAnnee?.label ? predictNextYear(currentAnnee.label) : '');
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
      // The year we transition FROM: the live one when there is one, otherwise
      // the most recent archived year that still holds groups. The redoublant
      // check below runs for both. Previously the archived-year path skipped it,
      // so those redoublants were never moved or dropped and silently stayed
      // behind in the old year.
      const fromYear = currentAnnee
        || [...annees]
          .filter(a => a.is_archived && (a.groupes_count || 0) > 0)
          .sort((a, b) => new Date(b.created_at) - new Date(a.created_at))[0];

      if (!fromYear) {
        await adminApi.setCurrentAnnee(newYearId);
        toast.success('Année courante mise à jour');
        refreshAnnees();
        return;
      }

      // Check for redoublants from the previous year
      const res = await adminApi.checkRedoublants({ from_annee_id: fromYear.id });
      const redoublants = res.data.data || [];

      if (redoublants.length === 0) {
        await runTransition(fromYear.id, newYearId);
        refreshAnnees();
        return;
      }

      // Show redoublant modal
      const initialChecks = {};
      redoublants.forEach(s => { initialChecks[s.id] = true; });
      setRedoublantChecks(initialChecks);
      setRedoublantModal({ newYearId, fromAnneeId: fromYear.id, students: redoublants });
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
      await runTransition(
        redoublantModal.fromAnneeId,
        redoublantModal.newYearId,
        { confirmIds, dropIds }
      );
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
      await runTransition(
        redoublantModal.fromAnneeId,
        redoublantModal.newYearId,
        { dropIds: allIds }
      );
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

  const transitionToNewYear = async (fromId, toId, { confirmIds = [], dropIds = [], groupeIds = {} } = {}) => {
    const copyRes = await adminApi.copyGroups({ from_annee_id: fromId, to_annee_id: toId });
    // copyGroups errors are not fatal on their own: a group that could not be
    // built only matters when a student actually needs it, and that surfaces
    // below as a placement error. Keep them visible but let the placement
    // errors decide whether the year may switch.
    if ((copyRes.data?.errors || []).length > 0) {
      console.warn('copyGroups errors:', copyRes.data.errors);
    }

    const ambiguous = [];
    const placementErrors = [];

    if (confirmIds.length > 0) {
      const res = await adminApi.confirmRedoublants({
        etudiant_ids: confirmIds,
        to_annee_id: toId,
        groupe_ids: groupeIds,
      });
      (res.data?.ambiguous || []).forEach(a => ambiguous.push({ ...a, kind: 'redoublant' }));
      placementErrors.push(...(res.data?.errors || []));
    }

    if (dropIds.length > 0)
      await adminApi.dropRedoublants({ etudiant_ids: dropIds });

    const promoteRes = await adminApi.promoteAdmis({
      from_annee_id: fromId,
      to_annee_id: toId,
      groupe_ids: groupeIds,
    });
    (promoteRes.data?.ambiguous || []).forEach(a => ambiguous.push({ ...a, kind: 'admis' }));
    placementErrors.push(...(promoteRes.data?.errors || []));

    return { promoteRes, ambiguous, placementErrors };
  };

  /**
   * Run the transition, but only close the year once every student has a group.
   *
   * A niveau that runs several groups in the new year used to leave those
   * students behind: the backend reported the ambiguity in a message string and
   * the year was switched anyway. Now the year stays put until the admin has
   * named a destination for each of them.
   */
  const runTransition = async (fromId, toId, options = {}) => {
    const { promoteRes, ambiguous, placementErrors } = await transitionToNewYear(fromId, toId, options);

    if (ambiguous.length > 0) {
      setAmbiguousModal({ fromAnneeId: fromId, toAnneeId: toId, entries: ambiguous });
      setGroupChoices({});
      return false;
    }

    if (placementErrors.length > 0) {
      // A student with no destination group would be left behind in the old
      // year while the new one became current. Refuse to switch and let the
      // admin create the missing group first, instead of silently dropping them.
      toast.error(
        `Transition interrompue : ${placementErrors.length} étudiant(s) sans destination. ${placementErrors[0]}`
      );
      console.error('Transition placement errors:', placementErrors);
      return false;
    }

    await adminApi.setCurrentAnnee(toId);
    toast.success(`Année mise à jour. ${promoteRes.data?.message || ''}`);
    return true;
  };

  const handleAmbiguousConfirm = async () => {
    if (!ambiguousModal) return;

    const entries = ambiguousModal.entries;
    const groupeIds = {};
    entries.forEach(e => { groupeIds[e.etudiant_id] = parseInt(groupChoices[e.etudiant_id], 10); });
    const redoublantIds = entries.filter(e => e.kind === 'redoublant').map(e => e.etudiant_id);

    setResolvingGroups(true);
    try {
      // Re-running is safe: students already moved are no longer in the old year,
      // and copyGroups skips the groups it created.
      const done = await runTransition(ambiguousModal.fromAnneeId, ambiguousModal.toAnneeId, {
        confirmIds: redoublantIds,
        groupeIds,
      });

      if (done) {
        setAmbiguousModal(null);
        refreshAnnees();
      }
    } catch (e) {
      toast.error(e.response?.data?.message || e.message || 'Erreur lors de la répartition');
    } finally {
      setResolvingGroups(false);
    }
  };

  const handleViewHistory = async (annee) => {
    setHistoryLoading(true);
    try {
      const res = await adminApi.getAnneeStats(annee.id);
      setHistoryGroups(res.data.data || {});
      setHistoryYear(annee);
    } catch (e) {
      if (e.response?.status === 404) {
        toast.warning('Bientôt disponible');
      } else {
        toast.error('Erreur lors du chargement');
      }
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

  const handleUnarchive = async (id) => {
    try {
      await adminApi.unarchiveAnnee(id);
      toast.success('Année désarchivée');
      refreshAnnees();
    } catch (e) {
      toast.error(e.response?.data?.message ?? 'Erreur');
    }
  };

  const handleOpenEdit = (a) => {
    setEditingId(a.id);
    setEditLabel(a.label);
    setEditStart(a.start_date ? a.start_date.slice(0, 10) : '');
    setEditEnd(a.end_date ? a.end_date.slice(0, 10) : '');
  };

  const confirmEdit = async () => {
    if (!editLabel.trim()) return;
    setSaving(true);
    try {
      await adminApi.updateAnnee(editingId, {
        label: editLabel.trim(),
        start_date: editStart || null,
        end_date: editEnd || null,
      });
      toast.success('Année mise à jour');
      setEditingId(null);
      refreshAnnees();
    } catch (e) {
      toast.error(e.response?.data?.message ?? 'Erreur');
    } finally {
      setSaving(false);
    }
  };

  return (
    <div className="page">
      <FilterBar
        showSearch={false}
        value={{}}
        onChange={() => {}}
        actions={(
          <button className="btn btn-primary" onClick={handleOpenCreate}>
            + Nouvelle année
          </button>
        )}
      />

      {warning && (
        <div className="alert alert-warning" style={{ marginBottom: 16 }}>
          <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
            <span>{warning.message}</span>
            {warning.can_archive && currentAnnee && (
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
                    <button
                      className="btn btn-sm btn-outline"
                      onClick={() => setArchiveViewYear(a)}
                    >
                      Consulter
                    </button>
                    {a.is_current ? (
                      <button
                        className="btn btn-sm btn-warning"
                        onClick={() => handleArchive(a.id)}
                      >
                        Archiver
                      </button>
                    ) : (
                      <>
                        <button
                          className="btn btn-sm btn-outline"
                          onClick={() => handleOpenEdit(a)}
                        >
                          Modifier
                        </button>
                        {a.is_archived && (
                          <>
                            <button
                              className="btn btn-sm btn-outline"
                              onClick={() => handleViewHistory(a)}
                              disabled={historyLoading}
                            >
                              {historyLoading && historyYear?.id === a.id ? 'Chargement...' : 'Statistiques'}
                            </button>
                            <button
                              className="btn btn-sm btn-outline"
                              onClick={() => handleUnarchive(a.id)}
                            >
                              Désarchiver
                            </button>
                          </>
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

      <Modal open={editingId !== null} onClose={() => setEditingId(null)} title="Modifier l'année académique">
        <div className="form-group">
          <label className="form-label">Label</label>
          <input
            className="form-input"
            placeholder="ex: 2025/2026"
            value={editLabel}
            onChange={e => setEditLabel(e.target.value)}
          />
        </div>
        <div className="form-group">
          <label className="form-label">Date de début</label>
          <input type="date" className="form-input" value={editStart} onChange={e => setEditStart(e.target.value)} />
        </div>
        <div className="form-group">
          <label className="form-label">Date de fin</label>
          <input type="date" className="form-input" value={editEnd} onChange={e => setEditEnd(e.target.value)} />
        </div>
        <div className="modal-footer">
          <button className="btn btn-outline" onClick={() => setEditingId(null)}>Annuler</button>
          <button className="btn btn-primary" onClick={confirmEdit} disabled={saving}>
            {saving ? 'Enregistrement...' : 'Enregistrer'}
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

      <Modal
        open={ambiguousModal !== null}
        onClose={() => !resolvingGroups && setAmbiguousModal(null)}
        title="Répartition dans les groupes"
        style={{ maxWidth: 720 }}
      >
        {ambiguousModal && (
          <>
            <div style={{ padding: 12, background: '#fffbeb', border: '1px solid #fde68a', borderRadius: 6, fontSize: 12, color: '#92400e', marginBottom: 12 }}>
              {ambiguousModal.entries.length} étudiant(s) ont plusieurs groupes possibles dans la nouvelle année.
              L'année ne sera pas mise en cours tant que chaque étudiant n'a pas son groupe.
            </div>

            <div className="table-wrap" style={{ maxHeight: 340, overflowY: 'auto' }}>
              <table>
                <thead>
                  <tr>
                    <th>Nom et Prénom</th>
                    <th>Passage</th>
                    <th>Groupe de destination</th>
                  </tr>
                </thead>
                <tbody>
                  {ambiguousModal.entries.map(e => (
                    <tr key={`${e.kind}-${e.etudiant_id}`}>
                      <td>{e.nom_prenom}</td>
                      <td style={{ whiteSpace: 'nowrap' }}>
                        {e.kind === 'redoublant' ? 'Redouble' : `Passe au niveau ${e.niveau_numero ?? ''}`}
                      </td>
                      <td>
                        <select
                          className="form-select"
                          value={groupChoices[e.etudiant_id] ?? ''}
                          onChange={ev => setGroupChoices(prev => ({ ...prev, [e.etudiant_id]: ev.target.value }))}
                        >
                          <option value="">— Choisir —</option>
                          {e.groupes.map(g => (
                            <option key={g.id} value={g.id}>{g.nom}</option>
                          ))}
                        </select>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>

            <div className="modal-footer" style={{ marginTop: 16 }}>
              <button
                className="btn btn-outline"
                onClick={() => setAmbiguousModal(null)}
                disabled={resolvingGroups}
              >
                Plus tard
              </button>
              <button
                className="btn btn-primary"
                onClick={handleAmbiguousConfirm}
                disabled={resolvingGroups || ambiguousModal.entries.some(e => !groupChoices[e.etudiant_id])}
              >
                {resolvingGroups ? 'Traitement...' : 'Confirmer la répartition'}
              </button>
            </div>
          </>
        )}
      </Modal>

      <ArchiveAnneeModal
        open={archiveViewYear !== null}
        annee={archiveViewYear}
        onClose={() => setArchiveViewYear(null)}
      />
    </div>
  );
}