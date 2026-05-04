import { useEffect, useState, useCallback } from 'react';
import { adminApi } from '../../api/admin';
import { formateursApi } from '../../api/formateurs';
import { unitesApi } from '../../api/unites';
import { sequencesApi } from '../../api/sequences';
import { scanFormateurSequencesDocument, scanFormateursList } from '../../api/gemini';
import { useToast } from '../../context/ToastContext';
import Modal from '../../components/common/Modal';
import Spinner from '../../components/common/Spinner';
import Badge from '../../components/common/Badge';
import { handleApiError, showSuccess, getFieldErrors } from '../../utils/errorHandler';
import '../../css/components.css';
import '../../css/layout.css';

export default function Formateurs() {
  const [formateurs, setFormateurs] = useState([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);
  const [openCreate, setOpenCreate] = useState(false);
  const [openManage, setOpenManage] = useState(false);
  const [openScan, setOpenScan] = useState(false);
  const [openBulkScan, setOpenBulkScan] = useState(false);
  const [selectedFormateur, setSelectedFormateur] = useState(null);
  const [formateurSequences, setFormateurSequences] = useState([]);
  const [saving, setSaving] = useState(false);
  const [deleting, setDeleting] = useState(null);
  const [removingSequence, setRemovingSequence] = useState(null);
  const [formErrors, setFormErrors] = useState({});
  const [scanImage, setScanImage] = useState(null);
  const [scanResults, setScanResults] = useState([]);
  const [extracting, setExtracting] = useState(false);
  const [scanSaving, setScanSaving] = useState(false);
  const [createForm, setCreateForm] = useState({ name: '', email: '', password: '' });
  const [bulkImages, setBulkImages] = useState([]);
  const [bulkResults, setBulkResults] = useState([]);
  const [bulkExtracting, setBulkExtracting] = useState(false);
  const [bulkSaving, setBulkSaving] = useState(false);
  const [bulkPassword, setBulkPassword] = useState('');
  const [openPassword, setOpenPassword] = useState(false);
  const [passwordForm, setPasswordForm] = useState({ password: '', confirm: '' });
  const [passwordErrors, setPasswordErrors] = useState({});
  const [copiedId, setCopiedId] = useState(null);
  const [masseHoraire, setMasseHoraire] = useState('');
  const [searchSequence, setSearchSequence] = useState('');
  const [allSequences, setAllSequences] = useState([]);
  const [deleteConfirm, setDeleteConfirm] = useState({ id: null, name: '' });
  const toast = useToast();

  const load = useCallback(() => {
    setLoading(true);
    setError(null);
    const controller = new AbortController();

    formateursApi.getFormateurs({ signal: controller.signal })
      .then(res => {
        if (!controller.signal.aborted) {
          setFormateurs(res.data.data);
        }
      })
      .catch((error) => {
        if (error.name === 'AbortError' || controller.signal.aborted) return;
        const errorInfo = handleApiError(error, toast, { showToast: false });
        setError(errorInfo.message);
      })
      .finally(() => {
        if (!controller.signal.aborted) {
          setLoading(false);
        }
      });

    return () => controller.abort();
  }, [toast]);

  useEffect(() => {
    const cleanup = load();
    return cleanup;
  }, []);

  useEffect(() => {
    unitesApi.getUnites({})
      .then(res => {
        const sequences = [];
        (res.data.data || []).forEach(u => {
          (u.sequences || []).forEach(s => {
            sequences.push({
              id: s.id,
              nom: s.nom,
              unite: u.nom,
              filiere: u.filiere?.nom || ''
            });
          });
        });
        setAllSequences(sequences);
      })
      .catch(() => setAllSequences([]));
  }, []);

  const handleCreate = async () => {
    const errors = {};
    if (!createForm.name) errors.name = 'Le nom est requis';
    if (!createForm.email) errors.email = "L'email est requis";
    if (!createForm.password) errors.password = 'Le mot de passe est requis';

    if (Object.keys(errors).length > 0) {
      setFormErrors(errors);
      toast.error('Veuillez remplir tous les champs obligatoires');
      return;
    }

    setSaving(true);
    setFormErrors({});

    try {
      await formateursApi.createFormateur(createForm);
      showSuccess(toast, 'Formateur créé');
      setOpenCreate(false);
      setCreateForm({ name: '', email: '', password: '' });
      load();
    } catch (error) {
      const fieldErrors = getFieldErrors(error);
      if (Object.keys(fieldErrors).length > 0) {
        setFormErrors(fieldErrors);
      }
      handleApiError(error, toast);
    } finally {
      setSaving(false);
    }
  };

  const handleDelete = (id, name) => {
    setDeleteConfirm({ id, name });
  };

  const confirmDelete = async () => {
    const { id } = deleteConfirm;
    setDeleting(id);
    try {
      await formateursApi.deleteFormateur(id);
      showSuccess(toast, 'Formateur supprimé');
      setDeleteConfirm({ id: null, name: '' });
      load();
    } catch (error) {
      console.error('Failed to delete formateur:', error);
      handleApiError(error, toast);
    } finally {
      setDeleting(null);
    }
  };

  const handleCopyCredentials = async (formateur) => {
    const text = `Email: ${formateur.user?.email}\nMot de passe: ${formateur.user?.password || '(non disponible)'}`;
    try {
      await navigator.clipboard.writeText(text);
      setCopiedId(formateur.id);
      setTimeout(() => setCopiedId(null), 2000);
      showSuccess(toast, 'Identifiants copiés');
    } catch {
      toast.error('Erreur lors de la copie');
    }
  };

  const openPasswordModal = (formateur) => {
    setSelectedFormateur(formateur);
    setPasswordForm({ password: '', confirm: '' });
    setPasswordErrors({});
    setOpenPassword(true);
  };

  const handleUpdatePassword = async () => {
    const errors = {};
    if (!passwordForm.password) errors.password = 'Le mot de passe est requis';
    else if (passwordForm.password.length < 6) errors.password = 'Minimum 6 caractères';
    if (passwordForm.password !== passwordForm.confirm) errors.confirm = 'Les mots de passe ne correspondent pas';

    if (Object.keys(errors).length > 0) {
      setPasswordErrors(errors);
      return;
    }

    setSaving(true);
    try {
      await formateursApi.updatePassword(selectedFormateur.id, passwordForm.password);
      showSuccess(toast, 'Mot de passe mis à jour');
      setOpenPassword(false);
    } catch (error) {
      handleApiError(error, toast);
    } finally {
      setSaving(false);
    }
  };

  const openManageModal = useCallback(async (formateur) => {
    setSelectedFormateur(formateur);
    setSearchSequence('');
    setOpenManage(true);
    try {
      const seqRes = await adminApi.getFormateurSequences(formateur.id);
      setFormateurSequences(seqRes.data.data || []);
    } catch (error) {
      handleApiError(error, toast);
    }
  }, [toast]);

  const openScanModal = (formateur) => {
    setSelectedFormateur(formateur);
    setScanResults([]);
    setScanImage(null);
    setOpenScan(true);
  };

  const handleRemoveSequence = async (sequenceId) => {
    if (!selectedFormateur) return;

    setRemovingSequence(sequenceId);
    try {
      await adminApi.removeFormateurSequence(selectedFormateur.id, sequenceId);
      showSuccess(toast, 'Séquence retirée');
      const res = await adminApi.getFormateurSequences(selectedFormateur.id);
      setFormateurSequences(res.data.data || []);
    } catch (error) {
      handleApiError(error, toast);
    } finally {
      setRemovingSequence(null);
    }
  };

  const handleAssignSequence = async (sequenceId, masseHoraire = null) => {
    if (!selectedFormateur || !sequenceId) return;

    try {
      await adminApi.assignFormateurSequence(selectedFormateur.id, {
        sequence_id: sequenceId,
        masse_horaire: masseHoraire
      });
      showSuccess(toast, 'Séquence assignée');
      const res = await adminApi.getFormateurSequences(selectedFormateur.id);
      setFormateurSequences(res.data.data || []);
      setSearchSequence('');
      setMasseHoraire('');
    } catch (error) {
      handleApiError(error, toast);
    }
  };

  const handleScan = async () => {
    if (!scanImage || !selectedFormateur) {
      toast.error('Sélectionner un document');
      return;
    }

    setExtracting(true);
    setScanResults([]);

    try {
      const fd = new FormData();
      fd.append('image', scanImage);

      const res = await adminApi.scanFormateurSequences(selectedFormateur.id, fd);
      const data = res.data.data || {};

      if ((data.assignees || []).length === 0) {
        toast.warning('Aucune séquence trouvée. Vérifiez l\'image.');
      } else {
        showSuccess(toast, `${(data.assignees || []).length} séquence(s) trouvée(s)`);
      }
      setScanResults(data.assignees || []);
    } catch (error) {
      handleApiError(error, toast);
      setScanResults([]);
    } finally {
      setExtracting(false);
    }
  };

  const handleConfirmScan = async () => {
    if (scanResults.length === 0 || !selectedFormateur) return;

    setScanSaving(true);
    try {
      const fd = new FormData();
      scanResults.forEach((s, i) => {
        fd.append(`sequences[${i}][nom]`, s || '');
      });

      const res = await adminApi.importFormateurSequences(selectedFormateur.id, fd);
      showSuccess(toast, `${res.data.data.assignees || scanResults.length} séquence(s) assignée(s)`);
      setScanResults([]);
      setScanImage(null);
      setOpenScan(false);

      const seqRes = await adminApi.getFormateurSequences(selectedFormateur.id);
      setFormateurSequences(seqRes.data.data || []);
      load();
    } catch (error) {
      handleApiError(error, toast);
    } finally {
      setScanSaving(false);
    }
  };

  const handleBulkImages = (e) => {
    const files = Array.from(e.target.files);
    setBulkImages(files);
  };

  const handleBulkScan = async () => {
    if (bulkImages.length === 0) {
      toast.warning('Veuillez sélectionner au moins une image');
      return;
    }

    setBulkExtracting(true);
    setBulkResults([]);

    try {
      const scanned = [];

      for (const img of bulkImages) {
        try {
          const data = await scanFormateursList(img);
          if (Array.isArray(data)) {
            for (const f of data) {
              if (f.name) {
                scanned.push({
                  name: f.name,
                  email: f.email || '',
                });
              }
            }
          }
        } catch (imgError) {
          // Continue with other images
        }
      }

      if (scanned.length === 0) {
        toast.warning('Aucun formateur détecté');
      } else {
        showSuccess(toast, `${scanned.length} formateur(s) détecté(s)`);
      }
      setBulkResults(scanned);
    } catch (error) {
      handleApiError(error, toast);
      setBulkResults([]);
    } finally {
      setBulkExtracting(false);
    }
  };

  const updateBulkResult = (i, key, val) => {
    setBulkResults(prev => prev.map((r, idx) => idx === i ? { ...r, [key]: val } : r));
  };

  const removeBulkResult = (i) => {
    setBulkResults(prev => prev.filter((_, idx) => idx !== i));
  };

  const handleConfirmBulkScan = async () => {
    if (bulkResults.length === 0 || !bulkPassword) {
      toast.error('Veuillez définir un mot de passe pour les formateurs');
      return;
    }

    setBulkSaving(true);
    try {
      let savedCount = 0;
      let errorCount = 0;

      for (const f of bulkResults) {
        try {
          await formateursApi.createFormateur({
            name: f.name,
            email: f.email || `${f.name.toLowerCase().replace(/\s+/g, '.')}@ifp.ma`,
            password: bulkPassword,
          });
          savedCount++;
        } catch (formError) {
          errorCount++;
        }
      }

      if (errorCount > 0) {
        toast.warning(`${savedCount} formateur(s) créé(s), ${errorCount} en échec`);
      } else {
        showSuccess(toast, `${savedCount} formateur(s) créé(s)`);
      }

      setOpenBulkScan(false);
      setBulkResults([]);
      setBulkImages([]);
      setBulkPassword('');
      load();
    } catch (error) {
      handleApiError(error, toast);
    } finally {
      setBulkSaving(false);
    }
  };

  if (loading) return (
    <div className="text-center mt-5">
      <Spinner />
      <p className="mt-2 text-muted">Chargement des formateurs...</p>
    </div>
  );

  return (
    <div className="page">
      <div className="page-header">
        <h2 className="page-title">Formateurs</h2>
        <div style={{ display: 'flex', gap: 8 }}>
          <button className="btn btn-outline" onClick={() => { setOpenBulkScan(true); setBulkResults([]); setBulkImages([]); }}>
            Scanner
          </button>
          <button className="btn btn-primary" onClick={() => { setOpenCreate(true); setFormErrors({}); }}>
            + Nouveau formateur
          </button>
        </div>
      </div>

      {error && (
        <div className="alert alert-danger" style={{ marginBottom: 16 }}>
          <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
            <span>{error}</span>
            <button className="btn btn-sm btn-outline" onClick={load}>Réessayer</button>
          </div>
        </div>
      )}

      {formateurs.length === 0 && !error ? (
        <div className="text-center mt-5" style={{ padding: 40 }}>
          <h4 style={{ color: '#666', marginBottom: 12 }}>Aucun formateur trouvé</h4>
          <p style={{ color: '#999', marginBottom: 24 }}>
            Commencez par ajouter un formateur.
          </p>
          <div style={{ display: 'flex', gap: 8, justifyContent: 'center' }}>
            <button className="btn btn-outline" onClick={() => { setOpenBulkScan(true); setBulkResults([]); setBulkImages([]); }}>
              Scanner
            </button>
            <button className="btn btn-primary" onClick={() => { setOpenCreate(true); setFormErrors({}); }}>
              + Nouveau formateur
            </button>
          </div>
        </div>
      ) : (
        <div className="table-wrap" style={{ fontSize: '15px' }}>
          <table>
            <thead>
              <tr>
                <th>#</th>
                <th>Nom</th>
                <th>Email</th>
                <th>Mot de passe</th>
                <th>Séquences assignées</th>
                <th>Actions</th>
              </tr>
            </thead>
            <tbody>
              {formateurs.map((f, i) => (
                <tr key={f.id}>
                  <td>{i + 1}</td>
                  <td><strong>{f.user?.name}</strong></td>
                  <td>{f.user?.email ?? '—'}</td>
                  <td>
                    <span style={{ fontFamily: 'monospace', fontSize: '13px' }}>
                      •••••••
                    </span>
                  </td>
                  <td><Badge label={`${f.sequences?.length ?? 0} séquence(s)`} color="teal" /></td>
                  <td>
                    <div style={{ display: 'flex', gap: 8 }}>
                      <button
                        className="btn btn-sm btn-outline"
                        onClick={() => handleCopyCredentials(f)}
                        title="Copier email et mot de passe"
                      >
                        {copiedId === f.id ? 'Copié!' : 'Copier'}
                      </button>
                      <button className="btn btn-sm btn-outline" onClick={() => openScanModal(f)}>
                        Scanner
                      </button>
                      <button className="btn btn-sm btn-outline" onClick={() => openManageModal(f)}>
                        Gérer séquences
                      </button>
                      <button className="btn btn-sm btn-outline" onClick={() => openPasswordModal(f)}>
                        Modifier MDP
                      </button>
                      <button
                        className="btn btn-sm btn-danger"
                        onClick={() => handleDelete(f.id, f.user?.name)}
                        disabled={deleting === f.id}
                      >
                        {deleting === f.id ? 'Suppression...' : 'Supprimer'}
                      </button>
                    </div>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}

      {/* Create formateur modal */}
      <Modal open={openCreate} onClose={() => { setOpenCreate(false); setFormErrors({}); }} title="Nouveau Formateur">
        <div className="form-group">
          <label className="form-label">Nom *</label>
          <input
            className={`form-input ${formErrors.name ? 'border-red-500' : ''}`}
            value={createForm.name}
            onChange={e => setCreateForm(p => ({ ...p, name: e.target.value }))}
            style={formErrors.name ? { borderColor: '#ef4444' } : {}}
          />
          {formErrors.name && (
            <div style={{ color: '#ef4444', fontSize: 12, marginTop: 4 }}>{formErrors.name}</div>
          )}
        </div>
        <div className="form-group">
          <label className="form-label">Email *</label>
          <input
            type="email"
            className={`form-input ${formErrors.email ? 'border-red-500' : ''}`}
            value={createForm.email}
            onChange={e => setCreateForm(p => ({ ...p, email: e.target.value }))}
            style={formErrors.email ? { borderColor: '#ef4444' } : {}}
          />
          {formErrors.email && (
            <div style={{ color: '#ef4444', fontSize: 12, marginTop: 4 }}>{formErrors.email}</div>
          )}
        </div>
        <div className="form-group">
          <label className="form-label">Mot de passe *</label>
          <input
            type="password"
            className={`form-input ${formErrors.password ? 'border-red-500' : ''}`}
            value={createForm.password}
            onChange={e => setCreateForm(p => ({ ...p, password: e.target.value }))}
            style={formErrors.password ? { borderColor: '#ef4444' } : {}}
          />
          {formErrors.password && (
            <div style={{ color: '#ef4444', fontSize: 12, marginTop: 4 }}>{formErrors.password}</div>
          )}
        </div>
        <div className="modal-footer">
          <button className="btn btn-outline" onClick={() => { setOpenCreate(false); setFormErrors({}); }}>Annuler</button>
          <button className="btn btn-primary" onClick={handleCreate} disabled={saving}>
            {saving ? 'Création...' : 'Créer'}
          </button>
        </div>
      </Modal>

      {/* Password edit modal */}
      <Modal open={openPassword} onClose={() => { setOpenPassword(false); setPasswordErrors({}); }} title={`Modifier mot de passe - ${selectedFormateur?.user?.name}`}>
        <div className="form-group">
          <label className="form-label">Nouveau mot de passe *</label>
          <input
            type="password"
            className={`form-input ${passwordErrors.password ? 'border-red-500' : ''}`}
            value={passwordForm.password}
            onChange={e => setPasswordForm(p => ({ ...p, password: e.target.value }))}
            style={passwordErrors.password ? { borderColor: '#ef4444' } : {}}
          />
          {passwordErrors.password && (
            <div style={{ color: '#ef4444', fontSize: 12, marginTop: 4 }}>{passwordErrors.password}</div>
          )}
        </div>
        <div className="form-group">
          <label className="form-label">Confirmer le mot de passe *</label>
          <input
            type="password"
            className={`form-input ${passwordErrors.confirm ? 'border-red-500' : ''}`}
            value={passwordForm.confirm}
            onChange={e => setPasswordForm(p => ({ ...p, confirm: e.target.value }))}
            style={passwordErrors.confirm ? { borderColor: '#ef4444' } : {}}
          />
          {passwordErrors.confirm && (
            <div style={{ color: '#ef4444', fontSize: 12, marginTop: 4 }}>{passwordErrors.confirm}</div>
          )}
        </div>
        <div className="modal-footer">
          <button className="btn btn-outline" onClick={() => { setOpenPassword(false); setPasswordErrors({}); }}>Annuler</button>
          <button className="btn btn-primary" onClick={handleUpdatePassword} disabled={saving}>
            {saving ? 'Modification...' : 'Modifier'}
          </button>
        </div>
      </Modal>

      {/* Manage sequences modal */}
      <Modal open={openManage} onClose={() => { setOpenManage(false); setScanResults([]); setScanImage(null); }} title={`Gérer séquences - ${selectedFormateur?.user?.name}`} style={{ maxWidth: '95vw', width: '95vw' }}>
        <div style={{ margin: '-20px -24px -24px' }}>
          <div style={{ padding: '16px 24px', display: 'flex', gap: 12, alignItems: 'flex-end', borderBottom: '1px solid #e5e7eb', background: '#f9fafb' }}>
            <div style={{ flex: 1 }}>
              <label className="form-label" style={{ marginBottom: 4, fontSize: 12 }}>Rechercher</label>
              <input className="form-input" placeholder="Nom de la séquence..." value={searchSequence} onChange={e => setSearchSequence(e.target.value)} style={{ padding: '7px 12px', fontSize: 13 }} />
            </div>
            <div style={{ width: 130 }}>
              <label className="form-label" style={{ marginBottom: 4, fontSize: 12 }}>Masse horaire</label>
              <input type="number" className="form-input" placeholder="Heures" min={0} value={masseHoraire} onChange={e => setMasseHoraire(e.target.value)} style={{ padding: '7px 12px', fontSize: 13 }} />
            </div>
          </div>
          <div style={{ maxHeight: 500, overflowY: 'auto' }}>
            <table style={{ width: '100%', borderCollapse: 'collapse' }}>
              <thead>
                <tr style={{ background: '#fafafa', position: 'sticky', top: 0 }}>
                  <th style={{ padding: '10px 20px', textAlign: 'left', fontSize: 12, fontWeight: 600, color: '#6b7280', borderBottom: '1px solid #e5e7eb' }}>Séquence</th>
                  <th style={{ padding: '10px 20px', textAlign: 'left', fontSize: 12, fontWeight: 600, color: '#6b7280', borderBottom: '1px solid #e5e7eb' }}>Unité</th>
                  <th style={{ padding: '10px 20px', textAlign: 'left', fontSize: 12, fontWeight: 600, color: '#6b7280', borderBottom: '1px solid #e5e7eb' }}>Filière</th>
                  <th style={{ padding: '10px 20px', textAlign: 'center', fontSize: 12, fontWeight: 600, color: '#6b7280', borderBottom: '1px solid #e5e7eb' }}>H</th>
                  <th style={{ padding: '10px 20px', textAlign: 'center', fontSize: 12, fontWeight: 600, color: '#6b7280', borderBottom: '1px solid #e5e7eb' }}></th>
                </tr>
              </thead>
              <tbody>
                {allSequences
                  .filter(s => !searchSequence || s.nom.toLowerCase().includes(searchSequence.toLowerCase()))
                  .map(s => {
                    const assigned = formateurSequences.flatMap(fg => fg.unites || []).flatMap(ug => ug.sequences || []).find(seq => seq.id === s.id);
                    const isAssigned = !!assigned;
                    return (
                      <tr key={s.id} style={{ background: isAssigned ? '#f0fdf4' : '#fff' }}>
                        <td style={{ padding: '10px 20px', fontSize: 14, fontWeight: 500 }}>{s.nom}</td>
                        <td style={{ padding: '10px 20px', fontSize: 13, color: '#6b7280' }}>{s.unite}</td>
                        <td style={{ padding: '10px 20px', fontSize: 13, color: '#6b7280' }}>{s.filiere}</td>
                        <td style={{ padding: '10px 20px', textAlign: 'center', fontSize: 13 }}>{isAssigned && assigned.masse_horaire ? assigned.masse_horaire + 'h' : '—'}</td>
                        <td style={{ padding: '8px 20px', textAlign: 'center' }}>
                          {isAssigned ? (
                            <button className="btn btn-sm btn-danger" onClick={() => handleRemoveSequence(assigned.id)} disabled={removingSequence === assigned.id} style={{ fontSize: 12, padding: '3px 10px' }}>{removingSequence === assigned.id ? '...' : 'Retirer'}</button>
                          ) : (
                            <button className="btn btn-sm btn-accent" onClick={() => handleAssignSequence(s.id, masseHoraire)} style={{ fontSize: 12, padding: '3px 10px' }}>Assigner</button>
                          )}
                        </td>
                      </tr>
                    );
                  })}
              </tbody>
            </table>
          </div>
          <div style={{ padding: '12px 24px', borderTop: '1px solid #e5e7eb', background: '#f9fafb' }}>
            <button className="btn btn-outline" onClick={() => { setOpenManage(false); setScanResults([]); setScanImage(null); }}>Fermer</button>
          </div>
        </div>
      </Modal>

      {/* Scan sequences for formateur modal */}
      <Modal open={openScan} onClose={() => { setOpenScan(false); setScanResults([]); setScanImage(null); }} title={`Scanner séquences - ${selectedFormateur?.user?.name}`} style={{ maxWidth: 1000, width: '90vw', maxHeight: '80vh', overflowY: 'auto' }}>
        <div style={{ display: 'grid', gridTemplateColumns: '1fr', gap: 12, marginBottom: 16 }}>
          <div className="form-group">
            <label className="form-label">Document photo (Cahier de Suivi Pédagogique) *</label>
            <input type="file" className="form-input" accept="image/jpeg,image/png,image/jpg,image/webp" onChange={e => setScanImage(e.target.files[0])} />
            <div style={{ marginTop: 8, fontSize: 12, color: '#6b7280' }}>
              Prenez une photo du document officiel listant les séquences enseignées par ce formateur
            </div>
          </div>
        </div>

        <button
          className="btn btn-primary"
          onClick={handleScan}
          disabled={!scanImage || extracting}
          style={{ marginBottom: 16 }}
        >
          {extracting ? 'Extraction en cours...' : 'Scanner le document'}
        </button>

        {extracting && (
          <div style={{ textAlign: 'center', padding: 20, marginBottom: 16 }}>
            <Spinner />
            <p style={{ marginTop: 8, color: '#666' }}>Analyse du document en cours...</p>
          </div>
        )}

        {!extracting && scanResults.length === 0 && scanImage && (
          <div style={{ textAlign: 'center', padding: 32, border: '1px solid #e5e7eb', borderRadius: 8, marginBottom: 16 }}>
            <h4 style={{ color: '#666', marginBottom: 8 }}>Aucune séquence détectée</h4>
            <p style={{ color: '#999', marginBottom: 16, fontSize: 14 }}>
              Vérifiez l'image ou réessayez le scan.
            </p>
            <button className="btn btn-primary" onClick={handleScan}>
              Réessayer le scan
            </button>
          </div>
        )}

        {scanResults.length > 0 && (
          <div style={{ overflowX: 'auto', marginBottom: 16, border: '1px solid #e5e7eb', borderRadius: 8 }}>
            <table style={{ fontSize: '14px', minWidth: '400px', width: '100%' }}>
              <thead>
                <tr style={{ backgroundColor: '#f9fafb' }}>
                  <th style={{ padding: '10px 14px', textAlign: 'left', fontWeight: 600 }}>Séquence détectée</th>
                  <th style={{ padding: '10px 14px', textAlign: 'left', fontWeight: 600 }}>Unité</th>
                  <th style={{ padding: '10px 14px', textAlign: 'left', fontWeight: 600 }}>Filière</th>
                </tr>
              </thead>
                <tbody>
                  {scanResults.map((s, idx) => (
                    <tr key={idx} style={{ borderBottom: '1px solid #e5e7eb' }}>
                      <td style={{ padding: '10px 14px' }}><strong>{s}</strong></td>
                      <td colSpan={2} style={{ padding: '10px 14px' }}>—</td>
                    </tr>
                  ))}
                </tbody>
            </table>
          </div>
        )}

        <div className="modal-footer" style={{ marginTop: 16 }}>
          <button className="btn btn-outline" onClick={() => { setOpenScan(false); setScanResults([]); setScanImage(null); }}>Annuler</button>
          <button className="btn btn-primary" onClick={handleConfirmScan} disabled={scanSaving || scanResults.length === 0}>
            {scanSaving ? (
              <>
                <Spinner /> Assignation...
              </>
            ) : `Assigner ${scanResults.length} séquence(s)`}
          </button>
        </div>
      </Modal>

      {/* Bulk scan formateurs modal */}
      <Modal open={openBulkScan} onClose={() => { setOpenBulkScan(false); setBulkResults([]); setBulkImages([]); setBulkPassword(''); }} title="Scanner Liste Formateurs" style={{ maxWidth: 1200, width: '90vw', maxHeight: '80vh', overflowY: 'auto' }}>
        {bulkResults.length === 0 && (
          <div style={{ marginBottom: 16, padding: 12, backgroundColor: '#f0f9ff', borderRadius: 8, fontSize: 14 }}>
            Prenez une photo ou un screenshot du document contenant la liste des formateurs.
          </div>
        )}

        <div className="form-group" style={{ marginBottom: 16 }}>
          <label className="form-label">Photos du document (une ou plusieurs) *</label>
          <input type="file" className="form-input" accept="image/jpeg,image/png,image/jpg,image/webp" multiple onChange={handleBulkImages} />
          {bulkImages.length > 0 && (
            <div style={{ marginTop: 8, fontSize: 13, color: '#666' }}>
              {bulkImages.length} photo(s) sélectionnée(s)
            </div>
          )}
        </div>

        <button
          className="btn btn-primary"
          onClick={handleBulkScan}
          disabled={bulkImages.length === 0 || bulkExtracting}
          style={{ marginBottom: 16 }}
        >
          {bulkExtracting ? 'Extraction en cours...' : 'Scanner le document'}
        </button>

        {bulkExtracting && (
          <div style={{ textAlign: 'center', padding: 20, marginBottom: 16 }}>
            <Spinner />
            <p style={{ marginTop: 8, color: '#666' }}>Analyse du document en cours...</p>
          </div>
        )}

        {!bulkExtracting && bulkResults.length === 0 && bulkImages.length > 0 && (
          <div style={{ textAlign: 'center', padding: 32, border: '1px solid #e5e7eb', borderRadius: 8, marginBottom: 16 }}>
            <h4 style={{ color: '#666', marginBottom: 8 }}>Aucun formateur détecté</h4>
            <p style={{ color: '#999', marginBottom: 0, fontSize: 14 }}>
              Vérifiez l'image ou réessayez le scan.
            </p>
          </div>
        )}

        {bulkResults.length > 0 && (
          <>
            <div className="form-group" style={{ marginBottom: 16 }}>
              <label className="form-label">Mot de passe par défaut *</label>
              <input
                type="password"
                className="form-input"
                value={bulkPassword}
                onChange={e => setBulkPassword(e.target.value)}
                placeholder="Mot de passe pour tous les formateurs"
              />
            </div>

            <div style={{ overflowX: 'auto', marginBottom: 16, border: '1px solid #e5e7eb', borderRadius: 8 }}>
              <table style={{ fontSize: '14px', minWidth: '500px', width: '100%' }}>
                <thead>
                  <tr style={{ backgroundColor: '#f9fafb' }}>
                    <th style={{ padding: '10px 14px', textAlign: 'left', fontWeight: 600 }}>Nom</th>
                    <th style={{ padding: '10px 14px', textAlign: 'left', fontWeight: 600 }}>Email</th>
                    <th style={{ padding: '10px 14px', textAlign: 'left', fontWeight: 600 }}>Actions</th>
                  </tr>
                </thead>
                <tbody>
                  {bulkResults.map((f, i) => (
                    <tr key={i} style={{ borderBottom: '1px solid #e5e7eb' }}>
                      <td style={{ padding: '10px 14px' }}>
                        <input
                          className="form-input"
                          value={f.name}
                          onChange={e => updateBulkResult(i, 'name', e.target.value)}
                          style={{ padding: '6px 10px', fontSize: '14px' }}
                        />
                      </td>
                      <td style={{ padding: '10px 14px' }}>
                        <input
                          className="form-input"
                          value={f.email}
                          onChange={e => updateBulkResult(i, 'email', e.target.value)}
                          style={{ padding: '6px 10px', fontSize: '14px' }}
                        />
                      </td>
                      <td style={{ padding: '10px 14px' }}>
                        <button className="btn btn-sm btn-danger" onClick={() => removeBulkResult(i)}>Retirer</button>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          </>
        )}

        <div className="modal-footer" style={{ marginTop: 16 }}>
          <button className="btn btn-outline" onClick={() => { setOpenBulkScan(false); setBulkResults([]); setBulkImages([]); setBulkPassword(''); }}>Annuler</button>
          <button className="btn btn-primary" onClick={handleConfirmBulkScan} disabled={bulkSaving || bulkResults.length === 0 || !bulkPassword}>
            {bulkSaving ? (
              <>
                <Spinner /> Création en cours...
              </>
            ) : `Créer ${bulkResults.length} formateur(s)`}
          </button>
        </div>
      </Modal>

      <Modal open={deleteConfirm.id !== null} onClose={() => setDeleteConfirm({ id: null, name: '' })} title="Confirmer la suppression">
        <p>Supprimer le formateur "{deleteConfirm.name}" ? Cette action est irréversible.</p>
        <div className="modal-footer">
          <button className="btn btn-outline" onClick={() => setDeleteConfirm({ id: null, name: '' })}>Annuler</button>
          <button className="btn btn-danger" onClick={confirmDelete}>Supprimer</button>
        </div>
      </Modal>
    </div>
  );
}
