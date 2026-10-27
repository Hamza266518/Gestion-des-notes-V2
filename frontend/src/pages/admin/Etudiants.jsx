import { useEffect, useState, useCallback } from 'react';
import { FiClipboard, FiSearch, FiEye, FiEyeOff } from 'react-icons/fi';
import { adminApi } from '../../api/admin';
import { etudiantsApi, translateLieu } from '../../api/etudiants';
import { scanApi } from '../../api/scan';
import { useToast } from '../../context/ToastContext';
import { useAnneeAcademique } from '../../context/AnneeAcademiqueContext';
import Modal from '../../components/common/Modal';
import Spinner from '../../components/common/Spinner';
import Badge from '../../components/common/Badge';
import FilterBar from '../../components/common/FilterBar';
import FilterSelect from '../../components/common/FilterSelect';
import { DOCX_MIME, saveBlob, filenameFromDisposition } from '../../components/admin/RecapTable';
import handleApiError, { showSuccess, getFieldErrors } from '../../utils/errorHandler';
import { formatNiveau, toArabicDate, toWesternDigits, isoToArabicDate, lieuToArabic, nationaliteToArabic, normalizeNationality, autoNationaliteAr } from '../../utils/helpers';
import usePersistedState from '../../hooks/usePersistedState';
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
  const [filters, setFilters, resetFilters] = usePersistedState('etudiants', { search: '', filiere_id: '', niveau_id: '' });
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
  const [visiblePasswords, setVisiblePasswords] = useState({});
  const [downloading, setDownloading] = useState(null);
// ─── load does NOT depend on currentAnnee — it just sends whatever filters exist ───
  const load = useCallback(() => {
    setLoading(true);
    setError(null);
const params = { ...filters };
    etudiantsApi.getEtudiants(params)
      .then(res => setEtudiants(res.data.data || []))
      .catch((err) => {
        const errorInfo = handleApiError(err, toast, { showToast: false });
        setError(errorInfo.message);
      })
      .finally(() => setLoading(false));
  }, [filters]); // ← currentAnnee REMOVED from deps

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

  // Word export of the current selection: either the student list shaped like the
  // reference sheet, or the identifiants / mots de passe sheet.
  const downloadDocx = async (kind) => {
    setDownloading(kind);
    try {
      const params = { ...filters };
      const res = kind === 'liste'
        ? await etudiantsApi.downloadListeDocx(params)
        : await etudiantsApi.downloadIdentifiantsDocx(params);
      const blob = new Blob([res.data], { type: DOCX_MIME });
      saveBlob(blob, filenameFromDisposition(
        res.headers?.['content-disposition'],
        kind === 'liste' ? 'Liste_stagiaires.docx' : 'Identifiants_etudiants.docx'
      ));
    } catch (err) {
      toast.error(err?.response?.data?.message || 'Erreur lors de la génération du document');
    } finally {
      setDownloading(null);
    }
  };

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
        date_naissance_ar: etudiant.date_naissance_ar || isoToArabicDate(etudiant.date_naissance) || '',
        lieu_naissance_ar: etudiant.lieu_naissance_ar || lieuToArabic(etudiant.lieu_naissance),
        cin_ar: etudiant.cin_ar || '',
        nationalite_ar: etudiant.nationalite_ar || nationaliteToArabic(etudiant.nationalite),
        numero_inscription: etudiant.numero_inscription || '',
        numero_inscription_ar: etudiant.numero_inscription_ar || '',
        date_inscription_ar: etudiant.date_inscription_ar || '',
        is_final_year: etudiant.groupe?.niveau?.numero === etudiant.groupe?.niveau?.filiere?.nombre_annees,
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
        date_naissance_ar: editData.date_naissance_ar || isoToArabicDate(editData.date_naissance) || null,
        lieu_naissance_ar: editData.lieu_naissance_ar || lieuToArabic(editData.lieu_naissance) || null,
        // Same value as the French side: CIN and N° inscription are language-neutral.
        cin_ar: editData.cin || editData.cin_ar || null,
        nationalite_ar: editData.nationalite_ar || nationaliteToArabic(editData.nationalite) || null,
        numero_inscription_ar: editData.numero_inscription || editData.numero_inscription_ar || null,
        date_inscription_ar: isoToArabicDate(editData.date_inscription) || editData.date_inscription_ar || null,
        ...(editData.is_final_year ? { numero_inscription: editData.numero_inscription?.trim() || null } : {}),
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

  const handleScan = async (files, scanFiliere, scanNiveau, scanGroupe, scanMode = 'create') => {
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
    fd.append('mode', scanMode);
    try {
      const today = new Date();
      const dateStr = today.toISOString().split('T')[0];
      const res = await scanApi.scanCin(fd);
      const results = res.data.data.resultats || [];
      setScanResults(results.map(r => {
        const dateInscription = r.date_inscription || dateStr;
        return {
          ...r,
          date_inscription: dateInscription,
          date_inscription_ar: r.date_inscription_ar || isoToArabicDate(dateInscription) || toArabicDate(today),
          lieu_naissance_ar: r.lieu_naissance_ar || lieuToArabic(r.lieu_naissance || ''),
          nationalite: normalizeNationality(r.nationalite) || 'MAROCAINE',
          nationalite_ar: autoNationaliteAr(r.nationalite || '', r.nationalite || '', r.nationalite_ar || '') || '',
          cin_ar: r.cin_ar || r.cin || '',
          numero_inscription_ar: r.numero_inscription_ar || r.numero_inscription || '',
          apply: r.found ? true : false,
          editable: true,
        };
      }));
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
    setScanResults(prev => prev.map((r, i) => {
      if (i !== idx) return r;
      const next = { ...r, [field]: value };
      if (field === 'cin') next.cin_ar = toWesternDigits(value).toUpperCase();
      if (field === 'date_naissance') next.date_naissance_ar = isoToArabicDate(value) || next.date_naissance_ar || '';
      if (field === 'date_inscription') next.date_inscription_ar = isoToArabicDate(value) || next.date_inscription_ar || '';
      if (field === 'lieu_naissance') {
        const prevAuto = lieuToArabic(r.lieu_naissance || '');
        const ar = r.lieu_naissance_ar || '';
        const auto = lieuToArabic(value);
        next.lieu_naissance_ar = (!ar || ar === prevAuto) ? (auto || (value ? ar : '')) : ar;
        if (!auto && String(value || '').trim()) {
          translateLieu(value).then(res => {
            const ar2 = res?.lieu_ar;
            if (!ar2) return;
            setScanResults(prev => prev.map((row, j) => {
              if (j !== idx) return row;
              if (String(row.lieu_naissance || '') !== value) return row;
              const pa = lieuToArabic(row.lieu_naissance || '');
              const cur = row.lieu_naissance_ar || '';
              return (!cur || cur === pa) ? { ...row, lieu_naissance_ar: ar2 } : row;
            }));
          }).catch(() => {});
        }
      }
      if (field === 'nationalite') {
        next.nationalite = normalizeNationality(value);
        next.nationalite_ar = autoNationaliteAr(r.nationalite || '', next.nationalite, r.nationalite_ar || '');
      }
      if (field === 'numero_inscription') next.numero_inscription_ar = value || next.numero_inscription_ar || '';
      return next;
    }));
  };

  const removeResult = (idx) => {
    setScanResults(prev => prev.filter((_, i) => i !== idx));
  };

  const handleConfirmScan = async (scanFiliere, scanNiveau, scanGroupe, scanMode = 'create') => {
    if (scanResults.length === 0) return;
    if (!currentAnnee) {
      toast.error("Veuillez définir l'année académique courante");
      return;
    }
    setScanSaving(true);
    try {
      if (scanMode === 'sync') {
        const applied = scanResults.filter(r => r.found && r.apply);
        if (applied.length === 0) {
          toast.error('Aucune correction à appliquer');
          return;
        }
        const res = await scanApi.applyScanCin({
          stagiaires: applied.map(r => ({
            etudiant_id: r.etudiant_id,
            nom_prenom: r.nom_prenom || null,
            nom_ar: r.nom_ar || null,
            cin: r.cin || null,
            cin_ar: r.cin_ar || null,
            date_naissance: r.date_naissance || null,
            date_naissance_ar: r.date_naissance_ar || null,
            lieu_naissance: r.lieu_naissance || null,
            lieu_naissance_ar: r.lieu_naissance_ar || null,
            nationalite: r.nationalite || null,
            nationalite_ar: r.nationalite_ar || null,
          })),
        });
        const data = res.data.data;
        const parts = [`${data.maj} mis à jour`];
        if (data.cin_diff > 0) parts.push(`${data.cin_diff} CIN différent(s) ignoré(s)`);
        if (data.introuvables > 0) parts.push(`${data.introuvables} introuvable(s)`);
        showSuccess(toast, `Corrections appliquées : ${parts.join(', ')}`);
        setScanOpen(false);
        setScanResults([]);
        load();
        return;
      }

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
        </div>
      </div>
    );
  }

  return (
    <div className="page">
      <div className="page-main">
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

      <FilterBar
        value={filters}
        onChange={setFilters}
        placeholder="Rechercher par nom, CIN ou N° Inscription..."
        onClear={resetFilters}
        actions={(
          <>
            <button
              className="btn btn-outline"
              onClick={() => downloadDocx('liste')}
              disabled={etudiants.length === 0 || downloading !== null}
              title="Télécharger la liste des étudiants au format Word"
            >
              {downloading === 'liste' ? 'Génération…' : 'Liste (.docx)'}
            </button>
            <button
              className="btn btn-outline"
              onClick={() => downloadDocx('identifiants')}
              disabled={etudiants.length === 0 || downloading !== null}
              title="Télécharger les identifiants et mots de passe au format Word"
            >
              {downloading === 'identifiants' ? 'Génération…' : 'Identifiants (.docx)'}
            </button>
            <button
              className="btn btn-primary"
              onClick={() => { setScanOpen(true); setScanResults([]); }}
              disabled={filieres.length === 0}
              title={filieres.length === 0 ? "Veuillez d'abord ajouter une filière" : ''}
            >
              Scanner CIN
            </button>
          </>
        )}
        chips={[
          filters.filiere_id && {
            key: 'filiere',
            label: `Filière: ${filieres.find(f => String(f.id) === String(filters.filiere_id))?.nom ?? ''}`,
            onRemove: () => setFilters(p => ({ ...p, filiere_id: '', niveau_id: '' })),
          },
          filters.niveau_id && {
            key: 'niveau',
            label: `Niveau: ${formatNiveau(niveaux.find(n => String(n.id) === String(filters.niveau_id))?.numero)}`,
            onRemove: () => setFilters(p => ({ ...p, niveau_id: '' })),
          },
        ].filter(Boolean)}
      >
        <FilterSelect
          label="Filière"
          value={filters.filiere_id}
          options={filieres.map(f => ({ id: f.id, label: f.nom }))}
          onChange={(v) => setFilters(p => ({ ...p, filiere_id: v, niveau_id: '' }))}
          placeholder="Toutes les filières"
          allLabel="Toutes les filières"
        />
        <FilterSelect
          label="Niveau"
          value={filters.niveau_id}
          options={niveaux.map(n => ({ id: n.id, label: formatNiveau(n.numero) }))}
          onChange={(v) => setFilters(p => ({ ...p, niveau_id: v }))}
          disabled={!filters.filiere_id}
          placeholder="Tous les niveaux"
          allLabel="Tous les niveaux"
        />
      </FilterBar>

      {loading ? (
        <div className="text-center mt-5">
          <Spinner />
        </div>
      ) : (
        <>
          {etudiants.length === 0 && !error ? (
            <div className="text-center mt-5" style={{ padding: 40 }}>
              <FiClipboard size={48} style={{ marginBottom: 16, color: 'var(--color-muted)' }} />
              <h4 style={{ color: '#666', marginBottom: 12 }}>Aucun étudiant trouvé</h4>
              <p style={{ color: '#999', marginBottom: 24 }}>
                {filters.search || filters.filiere_id || filters.niveau_id
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
                    <th>Identifiant</th>
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
<td>{e.cin ?? '—'}</td>
                      <td>{e.date_naissance ?? '—'}</td>
                      <td><Badge label={e.numero_inscription} color="blue" /></td>
                      <td style={{ fontSize: 12 }}>{e.user?.username ?? '—'}</td>
                      <td>
                        <div style={{ display: 'flex', alignItems: 'center', gap: 4 }}>
                          <code style={{ fontSize: 12, background: 'var(--gray-100)', padding: '2px 8px', borderRadius: 4 }}>
                            {e.user?.password_plain
                              ? (visiblePasswords[e.id] ? e.user.password_plain : '••••••••')
                              : '—'}
                          </code>
                          {e.user?.password_plain && (
                            <button
                              className="btn-ghost btn-sm"
                              onClick={() => setVisiblePasswords(p => ({ ...p, [e.id]: !p[e.id] }))}
                              style={{ padding: '2px 6px', border: 'none', background: 'none', cursor: 'pointer', lineHeight: 1 }}
                              title={visiblePasswords[e.id] ? 'Masquer' : 'Afficher'}
                            >
                              {visiblePasswords[e.id] ? <FiEyeOff size={14} /> : <FiEye size={14} />}
                            </button>
                          )}
                        </div>
                      </td>
                      <td>{e.groupe?.nom ?? '—'}</td>
                      <td className="no-print">
                        <div style={{ display: 'flex', gap: 6, flexWrap: 'nowrap' }}>
                          <button
                            className="btn btn-sm btn-outline"
                            onClick={() => {
                              const pwd = e.user?.password_plain || '';
                              const text = `${e.user?.username ?? ''}:${pwd}`;
                              navigator.clipboard.writeText(text);
                              toast.success(pwd ? 'Identifiants copiés' : 'Mot de passe non disponible');
                            }}
                            title="Copier identifiant:mot_de_passe"
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
        onResetResults={() => setScanResults([])}
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

function ScanModal({ open, onClose, filieres, scanResults, extracting, scanSaving, onScan, onConfirm, onUpdateResult, onRemoveResult, onResetResults }) {
  const { currentAnnee } = useAnneeAcademique();
  const [scanFiliere, setScanFiliere] = useState('');
  const [scanNiveau, setScanNiveau]   = useState('');
  const [scanGroupe, setScanGroupe]   = useState('');
  const [scanImages, setScanImages]   = useState([]);
  const [modalNiveaux, setModalNiveaux] = useState([]);
  const [modalGroupes, setModalGroupes] = useState([]);
  const [scanAttempted, setScanAttempted] = useState(false);
  const [mode, setMode] = useState('create');

  const DIFF_FIELDS = [
    { key: 'nom_prenom',       label: 'Nom' },
    { key: 'nom_ar',           label: 'الاسم' },
    { key: 'date_naissance',   label: 'Date naissance' },
    { key: 'date_naissance_ar',label: 'تاريخ الميلاد' },
    { key: 'lieu_naissance',   label: 'Lieu naissance' },
    { key: 'lieu_naissance_ar',label: 'مكان الميلاد' },
    { key: 'nationalite',      label: 'Nationalité' },
    { key: 'nationalite_ar',   label: 'الجنسية' },
  ];

  const cinDiffers = r => {
    if (!r.existing_data?.cin || !r.cin) return false;
    const a = String(r.cin).toUpperCase().replace(/[^A-Z0-9]/g, '');
    const b = String(r.existing_data.cin).toUpperCase().replace(/[^A-Z0-9]/g, '');
    return a !== b;
  };

  const fieldStatus = (r, key) => {
    const oldV = r.existing_data?.[key];
    const newV = r[key];
    const oldS = oldV === null || oldV === undefined ? '' : String(oldV);
    const newS = newV === null || newV === undefined ? '' : String(newV);
    if (!oldS && !newS) return 'same';
    if (!newS) return 'unchanged';
    return oldS === newS ? 'same' : 'diff';
  };

  const STATUS_STYLE = {
    same:      { bg: '#d1fae5', color: '#065f46', label: 'Identique' },
    diff:      { bg: '#fee2e2', color: '#991b1b', label: 'Différent' },
    unchanged: { bg: '#f3f4f6', color: '#6b7280', label: 'Inchangé (valeur gardée)' },
  };

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
    onScan(scanImages, scanFiliere, scanNiveau, scanGroupe, mode);
  };

  const handleConfirm = () => {
    onConfirm(scanFiliere, scanNiveau, scanGroupe, mode);
  };

  const handleModeChange = (nextMode) => {
    setMode(nextMode);
    setScanAttempted(false);
    if (onResetResults) onResetResults();
  };

  return (
    <Modal open={open} onClose={onClose} title="Scanner CIN" style={{
      maxWidth: 1100,
      width: '100%',
      maxHeight: '80vh',
      overflowY: 'auto',
      padding: '20px'
    }}>
      <div style={{ display: 'flex', gap: 8, marginBottom: 16, flexWrap: 'wrap' }}>
        <button
          className={mode === 'create' ? 'btn btn-sm btn-primary' : 'btn btn-sm btn-outline'}
          onClick={() => handleModeChange('create')}
        >
          Créer de nouveaux étudiants
        </button>
        <button
          className={mode === 'sync' ? 'btn btn-sm btn-primary' : 'btn btn-sm btn-outline'}
          onClick={() => handleModeChange('sync')}
        >
          Corriger l'existant (comparaison)
        </button>
      </div>
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
        {extracting ? 'Extraction en cours...' : 'Scanner'}
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

      {scanResults.length > 0 && mode === 'create' && (
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

      {scanResults.length > 0 && mode === 'sync' && (
        <div style={{ marginTop: 16 }}>
          {scanResults.map((r, idx) => {
            const found = !!r.found;
            const cDiff = cinDiffers(r);
            const applicable = found && !cDiff && !!r.existing_data;
            return (
              <div key={idx} style={{ border: '1px solid #e5e7eb', borderRadius: 8, marginBottom: 12, overflow: 'hidden' }}>
                <div style={{ padding: '10px 14px', background: !found ? '#fee2e2' : (cDiff ? '#fef3c7' : '#f9fafb'), display: 'flex', alignItems: 'center', justifyContent: 'space-between', gap: 12, flexWrap: 'wrap' }}>
                  <div style={{ display: 'flex', alignItems: 'center', gap: 10, flexWrap: 'wrap' }}>
                    <input
                      type="checkbox"
                      checked={!!r.apply}
                      disabled={!applicable}
                      onChange={e => onUpdateResult(idx, 'apply', e.target.checked)}
                      style={{ width: 16, height: 16 }}
                    />
                    <strong style={{ fontSize: 14 }}>{r.nom_prenom || r.existing_data?.nom_prenom || '—'}</strong>
                    <span style={{ fontSize: 13, color: '#6b7280' }}>• CIN {r.cin || r.existing_data?.cin || '—'}</span>
                  </div>
                  <div style={{ display: 'flex', gap: 6, flexWrap: 'wrap', alignItems: 'center' }}>
                    {!found && <Badge label="CIN introuvable dans la base" color="red" />}
                    {found && cDiff && <Badge label="CIN différent — non appliqué" color="orange" />}
                    {found && !cDiff && r.existing_data && <Badge label={r.apply ? 'À appliquer' : 'Non appliqué'} color={r.apply ? 'green' : 'gray'} />}
                    <button className="btn btn-sm btn-outline" onClick={() => onRemoveResult(idx)} style={{ fontSize: 12 }}>Retirer</button>
                  </div>
                </div>
                {found && r.existing_data ? (
                  <div style={{ overflowX: 'auto' }}>
                    <table style={{ fontSize: '13px', width: '100%', minWidth: 640, borderCollapse: 'collapse' }}>
                      <thead>
                        <tr style={{ backgroundColor: '#f9fafb' }}>
                          <th style={{ padding: '8px 12px', textAlign: 'left', fontWeight: 600, width: 170 }}>Champ</th>
                          <th style={{ padding: '8px 12px', textAlign: 'left', fontWeight: 600 }}>Base (existant)</th>
                          <th style={{ padding: '8px 12px', textAlign: 'left', fontWeight: 600 }}>CIN scanné</th>
                          <th style={{ padding: '8px 12px', textAlign: 'left', fontWeight: 600, width: 150 }}>Statut</th>
                        </tr>
                      </thead>
                      <tbody>
                        {DIFF_FIELDS.map(f => {
                          const st = fieldStatus(r, f.key);
                          const oldS = r.existing_data && r.existing_data[f.key] !== null && r.existing_data[f.key] !== undefined ? String(r.existing_data[f.key]) : '';
                          const newS = r[f.key] !== null && r[f.key] !== undefined ? String(r[f.key]) : '';
                          const stSty = STATUS_STYLE[st];
                          return (
                            <tr key={f.key} style={{ borderTop: '1px solid #f3f4f6' }}>
                              <td style={{ padding: '8px 12px', fontWeight: 500 }}>{f.label}</td>
                              <td style={{ padding: '8px 12px', background: stSty.bg, color: stSty.color }}>
                                {oldS || '—'}
                              </td>
                              <td style={{ padding: '8px 12px', background: stSty.bg }}>
                                {st === 'unchanged' ? (
                                  <span style={{ color: stSty.color }}>{oldS ? 'Conservé (valeur existante)' : '—'}</span>
                                ) : (
                                  <input
                                    className="form-input"
                                    value={newS}
                                    onChange={e => onUpdateResult(idx, f.key, e.target.value)}
                                    style={{ minWidth: 160, padding: '5px 8px', fontSize: 13, direction: f.key.endsWith('_ar') ? 'rtl' : 'ltr', textAlign: f.key.endsWith('_ar') ? 'right' : 'left' }}
                                  />
                                )}
                              </td>
                              <td style={{ padding: '8px 12px', color: stSty.color, fontWeight: 500, whiteSpace: 'nowrap' }}>
                                {stSty.label}
                              </td>
                            </tr>
                          );
                        })}
                      </tbody>
                    </table>
                  </div>
                ) : (
                  <div style={{ padding: '12px 14px', fontSize: 13, color: '#6b7280' }}>
                    {!found
                      ? "Aucun étudiant ne possède ce CIN dans la base. Cet étudiant ne sera pas modifié — utilisez le mode « Créer de nouveaux étudiants » pour l'inscrire."
                      : 'CIN différent de celui en base — aucune correction appliquée.'}
                  </div>
                )}
              </div>
            );
          })}
        </div>
      )}

      <div className="modal-footer" style={{ marginTop: 16 }}>
        <button className="btn btn-outline" onClick={onClose}>Annuler</button>
        {mode === 'create' && scanResults.some(r => r.existing) && (
          <span style={{ color: 'var(--danger)', fontSize: 13, marginRight: 12 }}>Retirez les étudiants en double pour confirmer</span>
        )}
        {mode === 'sync' && (
          <span style={{ color: '#6b7280', fontSize: 13, marginRight: 12 }}>
            Seules les cases cochées (CIN identiques) sont appliquées.
          </span>
        )}
        {mode === 'sync' ? (
          <button
            className="btn btn-primary"
            onClick={handleConfirm}
            disabled={scanSaving || !scanResults.some(r => r.found && r.apply)}
          >
            {scanSaving ? (
              <><Spinner /> Application...</>
            ) : `Appliquer les corrections (${scanResults.filter(r => r.found && r.apply).length})`}
          </button>
        ) : (
          <button
            className="btn btn-primary"
            onClick={handleConfirm}
            disabled={scanSaving || scanResults.length === 0 || scanResults.some(r => r.existing)}
          >
            {scanSaving ? (
              <><Spinner /> Enregistrement...</>
            ) : `Confirmer ${scanResults.length} étudiant(s)`}
          </button>
        )}
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
                onChange={e => {
                  const next = e.target.value;
                  const prevAuto = isoToArabicDate(editData.date_naissance);
                  const ar = editData.date_naissance_ar || '';
                  onUpdate({
                    ...editData,
                    date_naissance: next,
                    // Keep manual Arabic edits; regenerate only the auto-filled value.
                    date_naissance_ar: (!ar || ar === prevAuto) ? (isoToArabicDate(next) || ar) : ar,
                  });
                }}
              />
            </div>

            <div className="form-group">
              <label className="form-label">Lieu de naissance</label>
              <input
                className="form-input"
                value={editData.lieu_naissance || ''}
                onChange={e => {
                  const next = e.target.value;
                  const prevAuto = lieuToArabic(editData.lieu_naissance);
                  const ar = editData.lieu_naissance_ar || '';
                  const auto = lieuToArabic(next);
                  onUpdate({
                    ...editData,
                    lieu_naissance: next,
                    // Translate to Arabic; unknown places clear the auto value
                    // so the admin can type the Arabic by hand (Gemini fills
                    // the gap right after).
                    lieu_naissance_ar: (!ar || ar === prevAuto) ? (auto || (next ? ar : '')) : ar,
                  });
                  if (!auto && String(next || '').trim()) {
                    translateLieu(next).then(res => {
                      const ar2 = res?.lieu_ar;
                      if (!ar2) return;
                      onUpdate(prev => {
                        if (prev.lieu_naissance !== next) return prev;
                        const pa = lieuToArabic(prev.lieu_naissance);
                        const cur = prev.lieu_naissance_ar || '';
                        return (!cur || cur === pa) ? { ...prev, lieu_naissance_ar: ar2 } : prev;
                      });
                    }).catch(() => {});
                  }
                }}
              />
            </div>
          </div>

          <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 12 }}>
            <div className="form-group">
              <label className="form-label">Nationalité</label>
              <input
                className="form-input"
                value={editData.nationalite || 'Marocaine'}
                onChange={e => {
                  const next = normalizeNationality(e.target.value);
                  onUpdate({
                    ...editData,
                    nationalite: next,
                    // مغربية when Moroccan; a non-Moroccan value clears the auto
                    // translation so the admin can type the exception by hand.
                    nationalite_ar: autoNationaliteAr(editData.nationalite || '', next, editData.nationalite_ar || ''),
                  });
                }}
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

          <div className="form-group">
            <label className="form-label">N° inscription</label>
            <div style={{ padding: '8px 12px', background: 'var(--gray-50)', borderRadius: 6, fontSize: 14, color: 'var(--gray-700)', fontWeight: 500 }}>
              {editData.numero_inscription || '—'}
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