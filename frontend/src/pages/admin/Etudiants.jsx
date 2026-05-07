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
import { formatNiveau } from '../../utils/helpers';
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
  const [deleting, setDeleting]     = useState(null);
  const [editOpen, setEditOpen]     = useState(false);
  const [editData, setEditData]     = useState(null);
  const [editSaving, setEditSaving] = useState(false);
  const toast = useToast();

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

  const handleDelete = async (id, nomPrenom) => {
    if (!window.confirm(`Supprimer l'étudiant ${nomPrenom} ? Cette action est irréversible.`)) return;
    setDeleting(id);
    try {
      await etudiantsApi.deleteEtudiant(id);
      showSuccess(toast, 'Étudiant supprimé');
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
      });
      setEditOpen(true);
    } catch (err) {
      handleApiError(err, toast);
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
      const res = await scanApi.scanCin(fd);
      const results = res.data.data.resultats || [];
      setScanResults(results.map(r => ({ ...r, editable: true })));
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
          numero_inscription: r.numero_inscription,
          groupe_id: scanGroupe,
          annee_academique_id: currentAnnee.id,
        })),
        filiere_code: filieres.find(f => f.id == scanFiliere)?.code ?? '',
      });
      const data = res.data.data;
      showSuccess(toast, `${data.crees} étudiants créés, ${data.mis_a_jour} mis à jour`);
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
      <div className="page-header">
        <h2 className="page-title">Étudiants</h2>
        <button
          className="btn btn-primary"
          onClick={() => { setScanOpen(true); setScanResults([]); }}
          disabled={filieres.length === 0}
          title={filieres.length === 0 ? "Veuillez d'abord ajouter une filière" : ''}
        >
          Scanner CIN
        </button>
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
            <div className="table-wrap" style={{ fontSize: '15px' }}>
              <table>
                <thead>
                  <tr>
                    <th>#</th>
                    <th>Nom et Prénom</th>
                    <th>CIN</th>
                    <th>Date de naissance</th>
                    <th>N° Inscription</th>
                    <th>Groupe</th>
                    <th>Actions</th>
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
                      <td>{e.groupe?.nom ?? '—'}</td>
                      <td>
                        <div style={{ display: 'flex', gap: 8 }}>
                          <button
                            className="btn btn-sm btn-outline"
                            onClick={() => handleEdit(e)}
                          >
                            Modifier
                          </button>
                          <button
                            className="btn btn-sm btn-danger"
                            onClick={() => handleDelete(e.id, e.nom_prenom)}
                            disabled={deleting === e.id}
                          >
                            {deleting === e.id ? 'Suppression...' : 'Supprimer'}
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

      <EditModal
        open={editOpen}
        onClose={() => { setEditOpen(false); setEditData(null); }}
        editData={editData}
        filieres={filieres}
        onSave={handleSaveEdit}
        saving={editSaving}
        onUpdate={setEditData}
      />
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
          <table style={{ fontSize: '15px', minWidth: '600px', width: '100%' }}>
            <thead>
              <tr style={{ backgroundColor: '#f9fafb' }}>
                <th style={{ padding: '12px 16px', textAlign: 'left', fontWeight: 600, minWidth: '200px' }}>Nom et Prénom</th>
                <th style={{ padding: '12px 16px', textAlign: 'left', fontWeight: 600, minWidth: '180px' }}>الاسم بالعربية</th>
                <th style={{ padding: '12px 16px', textAlign: 'left', fontWeight: 600, minWidth: '120px' }}>CIN</th>
                <th style={{ padding: '12px 16px', textAlign: 'left', fontWeight: 600, minWidth: '130px' }}>Date de naissance</th>
                <th style={{ padding: '12px 16px', textAlign: 'left', fontWeight: 600, minWidth: '130px' }}>N° Inscription</th>
                <th style={{ padding: '12px 16px', textAlign: 'left', fontWeight: 600, minWidth: '100px' }}>Actions</th>
              </tr>
            </thead>
            <tbody>
              {scanResults.map((r, idx) => (
                <tr key={idx} style={{ borderBottom: '1px solid #e5e7eb' }}>
                  <td style={{ padding: '12px 16px' }}>
                    <input
                      className="form-input"
                      value={r.nom_prenom}
                      onChange={e => onUpdateResult(idx, 'nom_prenom', e.target.value)}
                      style={{ minWidth: '180px', width: '100%', padding: '8px 12px', fontSize: '15px' }}
                    />
                  </td>
                  <td style={{ padding: '12px 16px' }}>
                    <input
                      className="form-input"
                      value={r.nom_ar || ''}
                      onChange={e => onUpdateResult(idx, 'nom_ar', e.target.value)}
                      style={{ minWidth: '160px', width: '100%', padding: '8px 12px', fontSize: '15px', direction: 'rtl', textAlign: 'right' }}
                    />
                  </td>
                  <td style={{ padding: '12px 16px' }}>
                    <input
                      className="form-input"
                      value={r.cin}
                      onChange={e => onUpdateResult(idx, 'cin', e.target.value)}
                      style={{ minWidth: '120px', padding: '8px 12px', fontSize: '15px' }}
                    />
                  </td>
                  <td style={{ padding: '12px 16px' }}>
                    <input
                      className="form-input"
                      type="date"
                      value={r.date_naissance}
                      onChange={e => onUpdateResult(idx, 'date_naissance', e.target.value)}
                      style={{ minWidth: '130px', padding: '8px 12px', fontSize: '15px' }}
                    />
                  </td>
                  <td style={{ padding: '12px 16px' }}>
                    <input
                      className="form-input"
                      value={r.numero_inscription}
                      disabled
                      style={{ minWidth: '120px', padding: '8px 12px', fontSize: '15px', backgroundColor: '#f3f4f6' }}
                    />
                  </td>
                  <td style={{ padding: '12px 16px' }}>
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
        <button className="btn btn-primary" onClick={handleConfirm} disabled={scanSaving || scanResults.length === 0}>
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
          {saving ? 'Enregistrement...' : 'Enregistrer'}
        </button>
      </div>
    </Modal>
  );
}