import { useEffect, useState, useCallback } from 'react';
import { FiClipboard, FiSearch } from 'react-icons/fi';
import { adminApi } from '../../api/admin';
import { etudiantsApi } from '../../api/etudiants';
import { scanApi } from '../../api/scan';
import { useToast } from '../../context/ToastContext';
import { useAnneeAcademique } from '../../context/AnneeAcademiqueContext';
import Modal from '../../components/common/Modal';
import Spinner from '../../components/common/Spinner';
import Badge from '../../components/common/Badge';
import handleApiError, { showSuccess, getFieldErrors } from '../../utils/errorHandler';
import { formatNiveau, toArabicDate, toWesternDigits } from '../../utils/helpers';
import '../../css/components.css';
import '../../css/layout.css';

export default function Etudiants() {
  // ─── ALL HOOKS MUST BE AT THE TOP — NO EARLY RETURNS BEFORE THIS LINE ───
  const { currentAnnee, loading: anneeLoading } = useAnneeAcademique();
  const [etudiants, setEtudiants] = useState([]);
  const [filieres, setFilieres]   = useState([]);
  const [niveaux, setNiveaux]     = useState([]);
  const [loading, setLoading]     = useState(true);
  const [error, setError]         = useState(null);
  const [search, setSearch]       = useState('');
  const [filters, setFilters]     = useState({ filiere_id: '', niveau_id: '' });
  const [scanOpen, setScanOpen]     = useState(false);
  const [scanResults, setScanResults] = useState([]);
  const [scanSaving, setScanSaving]   = useState(false);
  const [extracting, setExtracting]   = useState(false);
  const [deleteModal, setDeleteModal] = useState(null);
  const [deleting, setDeleting]       = useState(null);
  const [editOpen, setEditOpen]       = useState(false);
  const [editData, setEditData]       = useState(null);
  const [editLoading, setEditLoading] = useState(false);
  const [editSaving, setEditSaving]   = useState(false);
  const toast = useToast();
  const [printMode, setPrintMode] = useState(false);
  const [printFiliere, setPrintFiliere] = useState('');
  const [printNiveau, setPrintNiveau] = useState('');
  const [printGroupe, setPrintGroupe] = useState('');
  const [printEtudiants, setPrintEtudiants] = useState([]);
  const [printLoading, setPrintLoading] = useState(false);
  const [printNiveaux, setPrintNiveaux] = useState([]);
  const [printGroupes, setPrintGroupes] = useState([]);

  // ─── load does NOT depend on currentAnnee — it just sends whatever filters exist ───
  const load = useCallback(() => {
    setLoading(true);
    setError(null);
    const params = { ...filters, search };
    etudiantsApi.getEtudiants(params)
      .then(res => setEtudiants(res.data.data || []))
      .catch((err) => {
        const errorInfo = handleApiError(err, toast, { showToast: false });
        setError(errorInfo.message);
      })
      .finally(() => setLoading(false));
  }, [filters, search]); // ← currentAnnee REMOVED from deps

  useEffect(() => { load(); }, [load]);

  useEffect(() => {
    adminApi.getFilieres()
      .then(r => setFilieres(r.data.data || []))
      .catch((err) => handleApiError(err, toast));
  }, []);

  useEffect(() => {
    if (filters.filiere_id) {
      adminApi.getNiveaux(filters.filiere_id)
        .then(res => setNiveaux(res.data.data || []))
        .catch((err) => handleApiError(err, toast));
    } else {
      setNiveaux([]);
    }
  }, [filters.filiere_id]);

  useEffect(() => {
    if (!printFiliere) { setPrintNiveaux([]); setPrintNiveau(''); setPrintGroupe(''); setPrintGroupes([]); return; }
    adminApi.getNiveaux(printFiliere).then(res => setPrintNiveaux(res.data.data || [])).catch(() => setPrintNiveaux([]));
    setPrintNiveau(''); setPrintGroupe(''); setPrintGroupes([]);
  }, [printFiliere]);

  useEffect(() => {
    if (!printNiveau) { setPrintGroupes([]); setPrintGroupe(''); return; }
    const params = { niveau_id: printNiveau };
    if (currentAnnee?.id) params.annee_academique_id = currentAnnee.id;
    adminApi.getGroupes(params).then(res => setPrintGroupes(res.data.data || [])).catch(() => setPrintGroupes([]));
    setPrintGroupe('');
  }, [printNiveau, currentAnnee]);

  useEffect(() => {
    if (!printGroupe) { setPrintEtudiants([]); return; }
    setPrintLoading(true);
    etudiantsApi.getEtudiants({ groupe_id: printGroupe }).then(res => setPrintEtudiants(res.data.data || [])).catch(() => setPrintEtudiants([])).finally(() => setPrintLoading(false));
  }, [printGroupe]);

  const handleDelete = async (id, nomPrenom) => {
    setDeleteModal({ id, nomPrenom });
  };

  const confirmDelete = async () => {
    if (!deleteModal) return;
    setDeleting(deleteModal.id);
    try {
      await etudiantsApi.deleteEtudiant(deleteModal.id);
      showSuccess(toast, 'Étudiant supprimé');
      setDeleteModal(null);
      load();
    } catch (err) {
      const errorInfo = handleApiError(err, toast, { showToast: false });
      if (err.response?.data?.message?.includes('constraint')) {
        toast.error('Impossible de supprimer. Cet étudiant a peut-être des notes associées.');
      } else {
        toast.error(errorInfo.message);
      }
    } finally {
      setDeleting(null);
    }
  };

  const handleEdit = async (etudiant) => {
    setEditLoading(true);
    try {
      const res = await adminApi.getGroupes({ annee_academique_id: currentAnnee?.id || '' });
      const allGroupes = res.data.data || [];

      const groupe = allGroupes.find(g => g.id === etudiant.groupe_id);
      const niveauId = groupe?.niveau_id || '';
      const filiereId = groupe?.niveau?.filiere_id || '';

      let niveaux = [];
      if (filiereId) {
        const nivRes = await adminApi.getNiveaux(filiereId);
        niveaux = nivRes.data.data || [];
      }

      setEditData({
        id: etudiant.id,
        nom_prenom: etudiant.nom_prenom,
        cin: etudiant.cin,
        date_naissance: etudiant.date_naissance || '',
        lieu_naissance: etudiant.lieu_naissance || '',
        nationalite: etudiant.nationalite || 'Marocaine',
        date_inscription: etudiant.date_inscription || '',
        groupe_id: etudiant.groupe_id,
        filiere_id: filiereId,
        niveau_id: niveauId,
        niveaux: niveaux,
        groupe_nom: groupe?.nom || '',
        nom_ar: etudiant.nom_ar || '',
        date_naissance_ar: etudiant.date_naissance_ar || '',
        lieu_naissance_ar: etudiant.lieu_naissance_ar || '',
        cin_ar: etudiant.cin_ar || '',
        nationalite_ar: etudiant.nationalite_ar || '',
        numero_inscription_ar: etudiant.numero_inscription_ar || '',
        date_inscription_ar: etudiant.date_inscription_ar || '',
      });
      setEditOpen(true);
    } catch (err) {
      handleApiError(err, toast);
    } finally {
      setEditLoading(false);
    }
  };

  const handleSaveEdit = async () => {
    if (!editData) return;
    setEditSaving(true);
    try {
      await etudiantsApi.updateEtudiant(editData.id, {
        nom_prenom: editData.nom_prenom,
        cin: editData.cin,
        date_naissance: editData.date_naissance || null,
        lieu_naissance: editData.lieu_naissance || null,
        nationalite: editData.nationalite || 'Marocaine',
        date_inscription: editData.date_inscription || null,
        groupe_id: editData.groupe_id,
        nom_ar: editData.nom_ar || null,
        date_naissance_ar: editData.date_naissance_ar || null,
        lieu_naissance_ar: editData.lieu_naissance_ar || null,
        cin_ar: editData.cin_ar || null,
        nationalite_ar: editData.nationalite_ar || null,
        numero_inscription_ar: editData.numero_inscription_ar || null,
        date_inscription_ar: editData.date_inscription_ar || null,
      });
      showSuccess(toast, 'Étudiant modifié avec succès');
      setEditOpen(false);
      setEditData(null);
      load();
    } catch (err) {
      handleApiError(err, toast);
    } finally {
      setEditSaving(false);
    }
  };

  const handleScan = async (files, scanFiliere, scanNiveau, scanGroupe) => {
    if (files.length === 0) return;
    if (!currentAnnee) {
      toast.error("Veuillez définir l'année académique courante");
      return;
    }
    setExtracting(true);
    setScanResults([]);
    const fd = new FormData();
    files.forEach((file, index) => fd.append(`pdfs[${index}]`, file));
    fd.append('groupe_id', scanGroupe);
    fd.append('annee_academique_id', currentAnnee.id);
    fd.append('filiere_code', filieres.find(f => f.id == scanFiliere)?.code ?? '');
    try {
      const today = new Date();
      const dateStr = today.toISOString().split('T')[0];
      const res = await scanApi.scanCin(fd);
      const results = res.data.data.resultats || [];
      setScanResults(results.map(r => ({ ...r, date_inscription: r.date_inscription || dateStr, date_inscription_ar: r.date_inscription_ar || toArabicDate(today), editable: true })));
    } catch (err) {
      console.error('Scan error:', err);
      const fieldErrors = getFieldErrors(err);
      if (Object.keys(fieldErrors).length > 0) {
        const firstError = Object.values(fieldErrors)[0];
        toast.error(Array.isArray(firstError) ? firstError[0] : firstError);
      } else {
        handleApiError(err, toast);
      }
    } finally {
      setExtracting(false);
    }
  };

  const updateResult = (idx, field, value) => {
    setScanResults(prev => prev.map((r, i) => i === idx ? { ...r, [field]: value } : r));
  };

  const removeResult = (idx) => {
    setScanResults(prev => prev.filter((_, i) => i !== idx));
  };

  const handleConfirmScan = async (scanFiliere, scanNiveau, scanGroupe) => {
    if (scanResults.length === 0) return;
    if (!currentAnnee) {
      toast.error("Veuillez définir l'année académique courante");
      return;
    }
    setScanSaving(true);
    try {
      const res = await scanApi.confirmScanCin({
        stagiaires: scanResults.map(r => ({
          nom_prenom: r.nom_prenom,
          nom_ar: r.nom_ar || null,
          cin: r.cin,
          date_naissance: r.date_naissance || null,
          lieu_naissance: r.lieu_naissance || null,
          nationalite: r.nationalite || null,
          date_inscription: r.date_inscription || null,
          numero_inscription: r.numero_inscription,
          groupe_id: scanGroupe,
          annee_academique_id: currentAnnee.id,
          date_naissance_ar: r.date_naissance_ar || null,
          lieu_naissance_ar: r.lieu_naissance_ar || null,
          cin_ar: r.cin_ar || null,
          nationalite_ar: r.nationalite_ar || null,
          numero_inscription_ar: r.numero_inscription_ar || null,
          date_inscription_ar: r.date_inscription_ar || null,
        })),
        filiere_code: filieres.find(f => f.id == scanFiliere)?.code ?? '',
      });
      const data = res.data.data;
      const msg = data.ignores > 0 ? `${data.crees} créé(s), ${data.ignores} ignoré(s)` : `${data.crees} étudiant(s) créé(s)`;
      showSuccess(toast, msg);
      setScanOpen(false);
      setScanResults([]);
      load();
    } catch (err) {
      console.error('Confirm scan error:', err);
      const fieldErrors = getFieldErrors(err);
      if (Object.keys(fieldErrors).length > 0) {
        const firstError = Object.values(fieldErrors)[0];
        toast.error(Array.isArray(firstError) ? firstError[0] : firstError);
      } else {
        handleApiError(err, toast);
      }
    } finally {
      setScanSaving(false);
    }
  };

  // ─── EARLY RETURN IS NOW INSIDE THE JSX — AFTER ALL HOOKS ───
  // While the academic year context is still loading, show a spinner
  if (anneeLoading) {
    return (
      <div className="page">
        <div className="text-center mt-5">
          <Spinner />
          <p className="mt-2 text-muted">Chargement...</p>
        </div>
      </div>
    );
  }

  return (
    <div className="page">
      <style>{`@media print { .navbar, .page-header { display: none !important; } }`}</style>
      <div className="page-main">
      <div className="page-header">
        <h2 className="page-title">Étudiants</h2>
        <div style={{ display: 'flex', gap: 8 }}>
          <button
            className="btn btn-outline"
            onClick={() => { setPrintMode(true); setPrintFiliere(''); setPrintNiveau(''); setPrintGroupe(''); setPrintEtudiants([]); setPrintNiveaux([]); setPrintGroupes([]); }}
          >
            Imprimer
          </button>
          <button
            className="btn btn-primary"
            onClick={() => { setScanOpen(true); setScanResults([]); }}
            disabled={filieres.length === 0}
            title={filieres.length === 0 ? "Veuillez d'abord ajouter une filière" : ''}
          >
            Scanner CIN
          </button>
        </div>
      </div>

      {/* Warning banner when no current year — but list still shows */}
      {!currentAnnee && (
        <div className="alert alert-warning" style={{ marginBottom: 16 }}>
          ⚠️ Aucune année académique n'est définie comme courante.
          Veuillez vous rendre dans "Années Académiques" et en définir une comme courante.
        </div>
      )}

      {error && (
        <div className="alert alert-danger" style={{ marginBottom: 16 }}>
          <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
            <span>{error}</span>
            <button className="btn btn-sm btn-outline" onClick={load}>Réessayer</button>
          </div>
        </div>
      )}

      <div className="filter-bar">
        <input
          className="form-input"
          placeholder="Rechercher par nom, CIN ou N° Inscription..."
          value={search}
          onChange={e => setSearch(e.target.value)}
        />
        <select
          className="form-select"
          value={filters.filiere_id}
          onChange={e => {
            setFilters(p => ({ ...p, filiere_id: e.target.value, niveau_id: '' }));
          }}
        >
          <option value="">Toutes les filières</option>
          {filieres.map(f => <option key={f.id} value={f.id}>{f.nom}</option>)}
        </select>
        <select
          className="form-select"
          value={filters.niveau_id}
          onChange={e => setFilters(p => ({ ...p, niveau_id: e.target.value }))}
          disabled={!filters.filiere_id}
        >
          <option value="">Tous les niveaux</option>
          {niveaux.map(n => <option key={n.id} value={n.id}>{formatNiveau(n.numero)}</option>)}
        </select>
      </div>

      {loading ? (
        <div className="text-center mt-5">
          <Spinner />
          <p className="mt-2 text-muted">Chargement des étudiants...</p>
        </div>
      ) : (
        <>
          {etudiants.length === 0 && !error ? (
            <div className="text-center mt-5" style={{ padding: 40 }}>
              <FiClipboard size={48} style={{ marginBottom: 16, color: 'var(--color-muted)' }} />
              <h4 style={{ color: '#666', marginBottom: 12 }}>Aucun étudiant trouvé</h4>
              <p style={{ color: '#999', marginBottom: 24 }}>
                {search || filters.filiere_id || filters.niveau_id
                  ? 'Aucun résultat pour ces filtres. Essayez de modifier vos critères.'
                  : 'Commencez par importer des étudiants en utilisant le scanner CIN.'}
              </p>
              <button
                className="btn btn-primary"
                onClick={() => { setScanOpen(true); setScanResults([]); }}
              >
                Scanner des CIN
              </button>
            </div>
          ) : (
            <div className="table-wrap" style={{ fontSize: '15px', overflowX: 'auto' }}>
              <table>
                <thead>
                  <tr>
                    <th>#</th>
                    <th>Nom et Prénom</th>
                    <th>CIN</th>
                    <th>Date de naissance</th>
                    <th>N° Inscription</th>
                    <th>Email</th>
                    <th>Mot de passe</th>
                    <th>Groupe</th>
                    <th className="no-print">Actions</th>
                  </tr>
                </thead>
                <tbody>
                  {etudiants.map((e, i) => (
                    <tr key={e.id}>
                      <td>{i + 1}</td>
                      <td><strong>{e.nom_prenom}</strong></td>
                      <td>{e.cin}</td>
                      <td>{e.date_naissance ?? '—'}</td>
                      <td><Badge label={e.numero_inscription} color="blue" /></td>
                      <td style={{ fontSize: 12 }}>{e.user?.email ?? '—'}</td>
                      <td><code style={{ fontSize: 12, background: 'var(--gray-100)', padding: '2px 8px', borderRadius: 4 }}>password</code></td>
                      <td>{e.groupe?.nom ?? '—'}</td>
                      <td className="no-print">
                        <div style={{ display: 'flex', gap: 6, flexWrap: 'nowrap' }}>
                          <button
                            className="btn btn-sm btn-outline"
                            onClick={() => {
                              const text = `${e.user?.email ?? ''}:password`;
                              navigator.clipboard.writeText(text);
                              toast.success('Identifiants copiés');
                            }}
                            title="Copier email:password"
                          >
                            Copier
                          </button>
                          <button
                            className="btn btn-sm btn-outline"
                            onClick={() => handleEdit(e)}
                            disabled={editLoading || editSaving}
                          >
                            Modifier
                          </button>
                          <button
                            className="btn btn-sm btn-danger"
                            onClick={() => handleDelete(e.id, e.nom_prenom)}
                            disabled={deleting === e.id}
                          >
                            {deleting === e.id ? <><span className="btn-spinner" />Suppression...</> : 'Supprimer'}
                          </button>
                        </div>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
        </>
      )}

      </div>

      {printMode && (
        <div className="print-overlay">
          <div className="print-header">
            <h2>Liste des emails des étudiants</h2>
            <div className="print-toolbar">
              <button className="btn btn-primary" onClick={() => window.print()} disabled={printLoading}>Imprimer</button>
              <button className="btn btn-outline" onClick={() => setPrintMode(false)}>Retour</button>
            </div>
          </div>
          <div className="print-filters">
            <select className="form-select" value={printFiliere} onChange={e => setPrintFiliere(e.target.value)}>
              <option value="">Toutes les filières</option>
              {filieres.map(f => <option key={f.id} value={f.id}>{f.nom}</option>)}
            </select>
            <select className="form-select" value={printNiveau} onChange={e => setPrintNiveau(e.target.value)} disabled={!printFiliere}>
              <option value="">Tous les niveaux</option>
              {printNiveaux.map(n => <option key={n.id} value={n.id}>{formatNiveau(n.numero)}</option>)}
            </select>
            <select className="form-select" value={printGroupe} onChange={e => setPrintGroupe(e.target.value)} disabled={!printNiveau}>
              <option value="">Tous les groupes</option>
              {printGroupes.map(g => <option key={g.id} value={g.id}>{g.nom}</option>)}
            </select>
          </div>
          {printLoading ? (
            <div className="text-center mt-5"><Spinner /><p className="mt-2 text-muted">Chargement...</p></div>
          ) : printGroupe && printEtudiants.length === 0 ? (
            <div className="text-center mt-5" style={{ padding: 40 }}>
              <FiClipboard size={48} style={{ marginBottom: 16, color: 'var(--color-muted)' }} />
              <p style={{ color: '#999' }}>Aucun étudiant trouvé pour ce groupe</p>
            </div>
          ) : printEtudiants.length > 0 ? (
            <div className="table-wrap">
              <table>
                <thead>
                  <tr>
                    <th>#</th>
                    <th>Nom et Prénom</th>
                    <th>N° Inscription</th>
                    <th>Email</th>
                    <th>Mot de passe</th>
                  </tr>
                </thead>
                <tbody>
                  {printEtudiants.map((e, i) => (
                    <tr key={e.id}>
                      <td>{i + 1}</td>
                      <td><strong>{e.nom_prenom}</strong></td>
                      <td>{e.numero_inscription}</td>
                      <td style={{ fontSize: 12 }}>{e.user?.email ?? '—'}</td>
                      <td><code style={{ fontSize: 12, background: 'var(--gray-100)', padding: '2px 8px', borderRadius: 4 }}>password</code></td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          ) : (
            <div className="text-center mt-5" style={{ padding: 40, color: '#999' }}>
              Sélectionnez une filière, un niveau et un groupe pour afficher la liste
            </div>
          )}
        </div>
      )}

      <ScanModal
        open={scanOpen}
        onClose={() => { setScanOpen(false); setScanResults([]); }}
        filieres={filieres}
        scanResults={scanResults}
        extracting={extracting}
        scanSaving={scanSaving}
        onScan={handleScan}
        onConfirm={handleConfirmScan}
        onUpdateResult={updateResult}
        onRemoveResult={removeResult}
        toast={toast}
      />

      {extracting && (
        <div className="loading-overlay">
          <div className="spinner" />
          <span className="loading-overlay-label">Extraction en cours...</span>
        </div>
      )}

      {scanSaving && (
        <div className="loading-overlay">
          <div className="spinner" />
          <span className="loading-overlay-label">Enregistrement...</span>
        </div>
      )}

      {editLoading && (
        <div className="loading-overlay">
          <div className="spinner" />
          <span className="loading-overlay-label">Chargement...</span>
        </div>
      )}

      {editSaving && (
        <div className="loading-overlay">
          <div className="spinner" />
          <span className="loading-overlay-label">Enregistrement...</span>
        </div>
      )}

      {deleting && (
        <div className="loading-overlay">
          <div className="spinner" />
          <span className="loading-overlay-label">Suppression...</span>
        </div>
      )}

      <EditModal
        open={editOpen}
        onClose={() => { setEditOpen(false); setEditData(null); }}
        editData={editData}
        filieres={filieres}
        onSave={handleSaveEdit}
        saving={editSaving}
        onUpdate={setEditData}
      />

      <Modal open={deleteModal !== null} onClose={() => !deleting && setDeleteModal(null)} title="Confirmer la suppression">
        {deleteModal && (
          <>
            <p style={{ margin: 0, marginBottom: 16 }}>
              Êtes-vous sûr de vouloir supprimer l'étudiant <strong>{deleteModal.nomPrenom}</strong> ?
            </p>
            <p style={{ fontSize: 13, color: 'var(--danger)', marginBottom: 20, padding: '8px 12px', background: 'var(--danger-light)', borderRadius: 6 }}>
              Cette action est irréversible.
            </p>
            <div className="modal-footer">
              <button className="btn btn-outline" onClick={() => setDeleteModal(null)} disabled={deleting}>
                Annuler
              </button>
              <button className="btn btn-danger" onClick={confirmDelete} disabled={deleting}>
                {deleting ? <><span className="btn-spinner" />Suppression...</> : 'Supprimer'}
              </button>
            </div>
          </>
        )}
      </Modal>
    </div>
  );
}

function ScanModal({ open, onClose, filieres, scanResults, extracting, scanSaving, onScan, onConfirm, onUpdateResult, onRemoveResult }) {
  const { currentAnnee } = useAnneeAcademique();
  const [scanFiliere, setScanFiliere] = useState('');
  const [scanNiveau, setScanNiveau]   = useState('');
  const [scanGroupe, setScanGroupe]   = useState('');
  const [scanImages, setScanImages]   = useState([]);
  const [modalNiveaux, setModalNiveaux] = useState([]);
  const [modalGroupes, setModalGroupes] = useState([]);
  const [scanAttempted, setScanAttempted] = useState(false);

  useEffect(() => {
    if (!scanFiliere) {
      setModalNiveaux([]);
      setScanNiveau('');
      setScanGroupe('');
      setModalGroupes([]);
      return;
    }
    import('../../api/admin').then(module => {
      module.adminApi.getNiveaux(scanFiliere)
        .then(res => setModalNiveaux(res.data.data || []))
        .catch(() => setModalNiveaux([]));
    });
    setScanNiveau('');
    setScanGroupe('');
    setModalGroupes([]);
  }, [scanFiliere]);

  useEffect(() => {
    if (!scanNiveau) {
      setModalGroupes([]);
      setScanGroupe('');
      return;
    }
    const params = { niveau_id: scanNiveau };
    if (currentAnnee?.id) {
      params.annee_academique_id = currentAnnee.id;
    }
    import('../../api/admin').then(module => {
      module.adminApi.getGroupes(params)
        .then(res => setModalGroupes(res.data.data || []))
        .catch(() => setModalGroupes([]));
    });
    setScanGroupe('');
  }, [scanNiveau]); // ← currentAnnee REMOVED from deps

  const handleFileChange = (e) => {
    setScanImages(Array.from(e.target.files));
  };

  const handleScanClick = () => {
    if (!scanFiliere || !scanNiveau || !scanGroupe || scanImages.length === 0) return;
    setScanAttempted(true);
    onScan(scanImages, scanFiliere, scanNiveau, scanGroupe);
  };

  const handleConfirm = () => {
    onConfirm(scanFiliere, scanNiveau, scanGroupe);
  };

  return (
    <Modal open={open} onClose={onClose} title="Scanner CIN" style={{
      maxWidth: 1200,
      width: '90vw',
      minWidth: '900px',
      maxHeight: '80vh',
      overflowY: 'auto',
      padding: '20px'
    }}>
      <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr 1fr', gap: 16, marginBottom: 16 }}>
        <div className="form-group">
          <label className="form-label">Filière *</label>
          <select className="form-select" value={scanFiliere} onChange={e => setScanFiliere(e.target.value)}>
            <option value="">Sélectionner</option>
            {filieres.map(f => <option key={f.id} value={f.id}>{f.nom}</option>)}
          </select>
        </div>
        <div className="form-group">
          <label className="form-label">Niveau *</label>
          <select className="form-select" value={scanNiveau} onChange={e => setScanNiveau(e.target.value)} disabled={!scanFiliere}>
            <option value="">Sélectionner</option>
            {modalNiveaux.map(n => <option key={n.id} value={n.id}>{formatNiveau(n.numero)}</option>)}
          </select>
        </div>
        <div className="form-group">
          <label className="form-label">Groupe *</label>
          <select className="form-select" value={scanGroupe} onChange={e => setScanGroupe(e.target.value)} disabled={!scanNiveau}>
            <option value="">Sélectionner</option>
            {modalGroupes.map(g => <option key={g.id} value={g.id}>{g.nom}</option>)}
          </select>
        </div>
      </div>

      <div className="form-group">
        <label className="form-label">Fichiers PDF (un ou plusieurs) *</label>
        <input type="file" className="form-input" accept=".pdf" multiple onChange={handleFileChange} />
        {scanImages.length > 0 && (
          <div style={{ marginTop: 8, fontSize: 13, color: '#666' }}>
            {scanImages.length} fichier(s) PDF sélectionné(s)
          </div>
        )}
      </div>

      <button
        className="btn btn-primary"
        onClick={handleScanClick}
        disabled={!scanFiliere || !scanNiveau || !scanGroupe || scanImages.length === 0 || extracting}
        style={{ marginBottom: 16 }}
      >
        {extracting ? 'Extraction en cours...' : 'Scanner les photos'}
      </button>

      {!extracting && scanAttempted && scanResults.length === 0 && (
        <div style={{ textAlign: 'center', padding: 32, border: '1px solid #e5e7eb', borderRadius: 8, marginTop: 16 }}>
          <FiSearch size={36} style={{ marginBottom: 12, color: 'var(--color-muted)' }} />
          <h4 style={{ color: '#666', marginBottom: 8 }}>Aucun étudiant détecté</h4>
          <p style={{ color: '#999', marginBottom: 16, fontSize: 14 }}>
            Vérifiez le fichier PDF et réessayez.
          </p>
          <button className="btn btn-primary" onClick={handleScanClick}>
            Réessayer le scan
          </button>
        </div>
      )}

      {scanResults.length > 0 && (
        <div style={{ overflowX: 'auto', marginTop: 16, border: '1px solid #e5e7eb', borderRadius: 8 }}>
          <table style={{ fontSize: '14px', minWidth: '1800px', width: '100%' }}>
              <thead>
                <tr style={{ backgroundColor: '#f9fafb' }}>
                  <th style={{ padding: '10px 12px', textAlign: 'left', fontWeight: 600, minWidth: '160px' }}>Nom</th>
                  <th style={{ padding: '10px 12px', textAlign: 'left', fontWeight: 600, minWidth: '130px' }}>الاسم</th>
                  <th style={{ padding: '10px 12px', textAlign: 'left', fontWeight: 600, minWidth: '100px' }}>CIN</th>
                  <th style={{ padding: '10px 12px', textAlign: 'left', fontWeight: 600, minWidth: '100px' }}>رقم البطاقة</th>
                  <th style={{ padding: '10px 12px', textAlign: 'left', fontWeight: 600, minWidth: '100px' }}>Date naiss.</th>
                  <th style={{ padding: '10px 12px', textAlign: 'left', fontWeight: 600, minWidth: '100px' }}>تاريخ الميلاد</th>
                  <th style={{ padding: '10px 12px', textAlign: 'left', fontWeight: 600, minWidth: '100px' }}>Lieu naiss.</th>
                  <th style={{ padding: '10px 12px', textAlign: 'left', fontWeight: 600, minWidth: '100px' }}>مكان الميلاد</th>
                  <th style={{ padding: '10px 12px', textAlign: 'left', fontWeight: 600, minWidth: '100px' }}>Nationalité</th>
                  <th style={{ padding: '10px 12px', textAlign: 'left', fontWeight: 600, minWidth: '100px' }}>الجنسية</th>
                  <th style={{ padding: '10px 12px', textAlign: 'left', fontWeight: 600, minWidth: '110px' }}>Date ins.</th>
                  <th style={{ padding: '10px 12px', textAlign: 'left', fontWeight: 600, minWidth: '100px' }}>تاريخ التسجيل</th>
                  <th style={{ padding: '10px 12px', textAlign: 'left', fontWeight: 600, minWidth: '110px' }}>N° Inscription</th>
                  <th style={{ padding: '10px 12px', textAlign: 'left', fontWeight: 600, minWidth: '80px' }}>Actions</th>
                </tr>
              </thead>
              <tbody>
                {scanResults.map((r, idx) => (
                  <tr key={idx} style={{ borderBottom: '1px solid #e5e7eb' }}>
                    <td style={{ padding: '8px 12px' }}>
                      <input className="form-input" value={r.nom_prenom} onChange={e => onUpdateResult(idx, 'nom_prenom', e.target.value)} style={{ minWidth: '140px', padding: '6px 10px', fontSize: '14px' }} />
                    </td>
                    <td style={{ padding: '8px 12px' }}>
                      <input className="form-input" value={r.nom_ar || ''} onChange={e => onUpdateResult(idx, 'nom_ar', e.target.value)} style={{ minWidth: '110px', padding: '6px 10px', fontSize: '14px', direction: 'rtl', textAlign: 'right' }} />
                    </td>
                    <td style={{ padding: '8px 12px' }}>
                      <div style={{ display: 'flex', alignItems: 'center', gap: 4 }}>
                        <input className="form-input" value={r.cin} onChange={e => onUpdateResult(idx, 'cin', e.target.value)} style={{ minWidth: '80px', padding: '6px 10px', fontSize: '14px' }} />
                        {r.existing && <Badge label="Exist" color="red" />}
                      </div>
                    </td>
                    <td style={{ padding: '8px 12px' }}>
                      <input className="form-input" value={r.cin_ar || ''} onChange={e => onUpdateResult(idx, 'cin_ar', e.target.value)} style={{ minWidth: '90px', padding: '6px 10px', fontSize: '14px', direction: 'rtl', textAlign: 'right' }} />
                    </td>
                    <td style={{ padding: '8px 12px' }}>
                      <input className="form-input" type="date" value={r.date_naissance} onChange={e => onUpdateResult(idx, 'date_naissance', e.target.value)} style={{ minWidth: '100px', padding: '6px 10px', fontSize: '14px' }} />
                    </td>
                    <td style={{ padding: '8px 12px' }}>
                      <input className="form-input" value={r.date_naissance_ar || ''} onChange={e => onUpdateResult(idx, 'date_naissance_ar', e.target.value)} style={{ minWidth: '90px', padding: '6px 10px', fontSize: '14px', direction: 'rtl', textAlign: 'right' }} />
                    </td>
                    <td style={{ padding: '8px 12px' }}>
                      <input className="form-input" value={r.lieu_naissance || ''} onChange={e => onUpdateResult(idx, 'lieu_naissance', e.target.value)} style={{ minWidth: '90px', padding: '6px 10px', fontSize: '14px' }} />
                    </td>
                    <td style={{ padding: '8px 12px' }}>
                      <input className="form-input" value={r.lieu_naissance_ar || ''} onChange={e => onUpdateResult(idx, 'lieu_naissance_ar', e.target.value)} style={{ minWidth: '90px', padding: '6px 10px', fontSize: '14px', direction: 'rtl', textAlign: 'right' }} />
                    </td>
                    <td style={{ padding: '8px 12px' }}>
                      <input className="form-input" value={r.nationalite || ''} onChange={e => onUpdateResult(idx, 'nationalite', e.target.value)} style={{ minWidth: '90px', padding: '6px 10px', fontSize: '14px' }} />
                    </td>
                    <td style={{ padding: '8px 12px' }}>
                      <input className="form-input" value={r.nationalite_ar || ''} onChange={e => onUpdateResult(idx, 'nationalite_ar', e.target.value)} style={{ minWidth: '90px', padding: '6px 10px', fontSize: '14px', direction: 'rtl', textAlign: 'right' }} />
                    </td>
                    <td style={{ padding: '8px 12px' }}>
                      <input className="form-input" value={r.date_inscription || ''} onChange={e => onUpdateResult(idx, 'date_inscription', e.target.value)} style={{ minWidth: '100px', padding: '6px 10px', fontSize: '14px' }} />
                    </td>
                    <td style={{ padding: '8px 12px' }}>
                      <input className="form-input" value={r.date_inscription_ar || ''} onChange={e => onUpdateResult(idx, 'date_inscription_ar', e.target.value)} style={{ minWidth: '90px', padding: '6px 10px', fontSize: '14px', direction: 'rtl', textAlign: 'right' }} />
                    </td>
                    <td style={{ padding: '8px 12px' }}>
                      <input className="form-input" value={r.numero_inscription} disabled style={{ minWidth: '100px', padding: '6px 10px', fontSize: '14px', backgroundColor: '#f3f4f6' }} />
                    </td>
                    <td style={{ padding: '8px 12px' }}>
                      <button className="btn btn-sm btn-danger" onClick={() => onRemoveResult(idx)}>Supprimer</button>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}

      <div className="modal-footer" style={{ marginTop: 16 }}>
        <button className="btn btn-outline" onClick={onClose}>Annuler</button>
        {scanResults.some(r => r.existing) && (
          <span style={{ color: 'var(--danger)', fontSize: 13, marginRight: 12 }}>Retirez les étudiants en double pour confirmer</span>
        )}
        <button className="btn btn-primary" onClick={handleConfirm} disabled={scanSaving || scanResults.length === 0 || scanResults.some(r => r.existing)}>
          {scanSaving ? (
            <><Spinner /> Enregistrement...</>
          ) : `Confirmer ${scanResults.length} étudiant(s)`}
        </button>
      </div>
    </Modal>
  );
}

function EditModal({ open, onClose, editData, filieres, onSave, saving, onUpdate }) {
  if (!editData) return null;

  const filiereNom = filieres.find(f => f.id == editData.filiere_id)?.nom || '';
  const niveauNum = (editData.niveaux || []).find(n => n.id == editData.niveau_id)?.numero || '';

  return (
    <Modal open={open} onClose={onClose} title="Modifier l'étudiant" style={{ maxWidth: 600, width: '90vw', maxHeight: '90vh', display: 'flex', flexDirection: 'column' }}>
      <div style={{ overflowY: 'auto', paddingRight: 8, maxHeight: '70vh' }}>
        {/* Personal info section */}
        <div style={{ marginBottom: 20 }}>
          <h4 style={{ fontSize: 14, fontWeight: 600, color: 'var(--primary)', marginBottom: 12, paddingBottom: 8, borderBottom: '2px solid var(--primary)' }}>
            Informations personnelles
          </h4>
          <div className="form-group">
            <label className="form-label">Nom et Prénom *</label>
            <input
              className="form-input"
              value={editData.nom_prenom}
              onChange={e => onUpdate({ ...editData, nom_prenom: e.target.value })}
            />
          </div>

          <div className="form-group">
            <label className="form-label">CIN *</label>
            <input
              className="form-input"
              value={editData.cin}
              onChange={e => onUpdate({ ...editData, cin: e.target.value })}
            />
          </div>

          <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 12 }}>
            <div className="form-group">
              <label className="form-label">Date de naissance</label>
              <input
                className="form-input"
                type="date"
                value={editData.date_naissance || ''}
                onChange={e => onUpdate({ ...editData, date_naissance: e.target.value })}
              />
            </div>

            <div className="form-group">
              <label className="form-label">Lieu de naissance</label>
              <input
                className="form-input"
                value={editData.lieu_naissance || ''}
                onChange={e => onUpdate({ ...editData, lieu_naissance: e.target.value })}
              />
            </div>
          </div>

          <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 12 }}>
            <div className="form-group">
              <label className="form-label">Nationalité</label>
              <input
                className="form-input"
                value={editData.nationalite || 'Marocaine'}
                onChange={e => onUpdate({ ...editData, nationalite: e.target.value })}
              />
            </div>

            <div className="form-group">
              <label className="form-label">Date d'inscription</label>
              <input
                className="form-input"
                type="date"
                value={editData.date_inscription || ''}
                onChange={e => onUpdate({ ...editData, date_inscription: e.target.value })}
              />
            </div>
          </div>
        </div>

        {/* Arabic info section */}
        <div style={{ marginBottom: 20 }}>
          <h4 style={{ fontSize: 14, fontWeight: 600, color: 'var(--primary)', marginBottom: 12, paddingBottom: 8, borderBottom: '2px solid var(--primary)' }}>
            Informations en arabe
          </h4>

          <div className="form-group">
            <label className="form-label">الاسم الكامل</label>
            <input
              className="form-input"
              value={toWesternDigits(editData.nom_ar || '')}
              onChange={e => onUpdate({ ...editData, nom_ar: toWesternDigits(e.target.value) })}
              style={{ direction: 'rtl', textAlign: 'right' }}
            />
          </div>

          <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 12 }}>
            <div className="form-group">
              <label className="form-label">تاريخ الميلاد</label>
              <input
                className="form-input"
                value={toWesternDigits(editData.date_naissance_ar || '')}
                onChange={e => onUpdate({ ...editData, date_naissance_ar: toWesternDigits(e.target.value) })}
                style={{ direction: 'rtl', textAlign: 'right' }}
              />
            </div>

            <div className="form-group">
              <label className="form-label">مكان الميلاد</label>
              <input
                className="form-input"
                value={toWesternDigits(editData.lieu_naissance_ar || '')}
                onChange={e => onUpdate({ ...editData, lieu_naissance_ar: toWesternDigits(e.target.value) })}
                style={{ direction: 'rtl', textAlign: 'right' }}
              />
            </div>
          </div>

          <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 12 }}>
            <div className="form-group">
              <label className="form-label">رقم البطاقة</label>
              <input
                className="form-input"
                value={toWesternDigits(editData.cin_ar || '')}
                onChange={e => onUpdate({ ...editData, cin_ar: toWesternDigits(e.target.value) })}
                style={{ direction: 'rtl', textAlign: 'right' }}
              />
            </div>

            <div className="form-group">
              <label className="form-label">الجنسية</label>
              <input
                className="form-input"
                value={toWesternDigits(editData.nationalite_ar || '')}
                onChange={e => onUpdate({ ...editData, nationalite_ar: toWesternDigits(e.target.value) })}
                style={{ direction: 'rtl', textAlign: 'right' }}
              />
            </div>
          </div>

          <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 12 }}>
            <div className="form-group">
              <label className="form-label">رقم التسجيل</label>
              <input
                className="form-input"
                value={toWesternDigits(editData.numero_inscription_ar || '')}
                onChange={e => onUpdate({ ...editData, numero_inscription_ar: toWesternDigits(e.target.value) })}
                style={{ direction: 'rtl', textAlign: 'right' }}
              />
            </div>

            <div className="form-group">
              <label className="form-label">تاريخ التسجيل</label>
              <input
                className="form-input"
                value={toWesternDigits(editData.date_inscription_ar || '')}
                onChange={e => onUpdate({ ...editData, date_inscription_ar: toWesternDigits(e.target.value) })}
                style={{ direction: 'rtl', textAlign: 'right' }}
              />
            </div>
          </div>
        </div>

        {/* Academic info section */}
        <div>
          <h4 style={{ fontSize: 14, fontWeight: 600, color: 'var(--primary)', marginBottom: 12, paddingBottom: 8, borderBottom: '2px solid var(--primary)' }}>
            Informations académiques
          </h4>

          <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 12 }}>
            <div className="form-group">
              <label className="form-label">Filière</label>
              <div style={{ padding: '8px 12px', background: 'var(--gray-50)', borderRadius: 6, fontSize: 14, color: 'var(--gray-700)', fontWeight: 500 }}>
                {filiereNom || '—'}
              </div>
            </div>

            <div className="form-group">
              <label className="form-label">Niveau</label>
              <div style={{ padding: '8px 12px', background: 'var(--gray-50)', borderRadius: 6, fontSize: 14, color: 'var(--gray-700)', fontWeight: 500 }}>
                {niveauNum ? formatNiveau(niveauNum) : '—'}
              </div>
            </div>
          </div>

          <div className="form-group">
            <label className="form-label">Groupe</label>
            <div style={{ padding: '8px 12px', background: 'var(--gray-50)', borderRadius: 6, fontSize: 14, color: 'var(--gray-700)', fontWeight: 500, display: 'flex', alignItems: 'center', gap: 8 }}>
              {editData.groupe_nom || '—'}
            </div>
          </div>
        </div>
      </div>

      <div className="modal-footer" style={{ marginTop: 16, paddingTop: 12, borderTop: '1px solid var(--gray-200)' }}>
        <button className="btn btn-outline" onClick={onClose}>Annuler</button>
        <button className="btn btn-primary" onClick={onSave} disabled={saving}>
          {saving ? <><span className="btn-spinner" />Enregistrement...</> : 'Enregistrer'}
        </button>
      </div>
    </Modal>
  );
}