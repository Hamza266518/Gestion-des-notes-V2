import { useEffect, useState, useCallback } from 'react';
import { adminApi } from '../../api/admin';
import { unitesApi } from '../../api/unites';
import { sequencesApi } from '../../api/sequences';
import { formateursApi } from '../../api/formateurs';
import { controlesApi } from '../../api/controles';
import { scanApi } from '../../api/scan';
import { useToast } from '../../context/ToastContext';
import { useAnneeAcademique } from '../../context/AnneeAcademiqueContext';
import Modal from '../../components/common/Modal';
import Spinner from '../../components/common/Spinner';
import Badge from '../../components/common/Badge';
import { handleApiError, showSuccess, getFieldErrors } from '../../utils/errorHandler';
import { formatNiveau } from '../../utils/helpers';
import '../../css/components.css';
import '../../css/layout.css';

export default function Unites() {
  const [unites, setUnites] = useState([]);
  const [filieres, setFilieres] = useState([]);
  const [niveaux, setNiveaux] = useState([]);
  const [groupes, setGroupes] = useState([]);
  const [formateurs, setFormateurs] = useState([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);
  const [expanded, setExpanded] = useState({});
  const [filters, setFilters] = useState({ groupe_id: '', filiere_id: '', niveau_id: '' });
  const [selFiliere, setSelFiliere] = useState('');
  const [selNiveau, setSelNiveau] = useState('');
  const [openUnite, setOpenUnite] = useState(false);
  const [openSeq, setOpenSeq] = useState(false);
  const [openCtrl, setOpenCtrl] = useState(false);
  const [selectedUnite, setSelectedUnite] = useState(null);
  const [selectedSeq, setSelectedSeq] = useState(null);
  const [saving, setSaving] = useState(false);
  const [scanOpen, setScanOpen] = useState(false);
  const [scanImage, setScanImage] = useState(null);
  const [scanFiliere, setScanFiliere] = useState('');
  const [scanResults, setScanResults] = useState([]);
  const [scanSaving, setScanSaving] = useState(false);
  const [extracting, setExtracting] = useState(false);
  const [scanSemestre, setScanSemestre] = useState('');
  const [deletingUnite, setDeletingUnite] = useState(null);
  const [deletingSeq, setDeletingSeq] = useState(null);
  const [deletingCtrl, setDeletingCtrl] = useState(null);
  const [deleteConfirm, setDeleteConfirm] = useState({ type: null, id: null, nom: '' });
  const [formU, setFormU] = useState({ filiere_id: '', nom: '', numero_annee: '', semestre: '' });
  const [formS, setFormS] = useState({ nom: '', coefficient: '', nombre_controles: 2, formateur_id: '' });
  const [formC, setFormC] = useState({ nom: '', sequence_id: '' });
  const [formErrors, setFormErrors] = useState({});
  const toast = useToast();
  const { currentAnnee } = useAnneeAcademique();

  const load = useCallback(() => {
    setLoading(true);
    setError(null);
    unitesApi.getUnites(filters)
      .then(res => setUnites(res.data.data))
      .catch((error) => {
        const errorInfo = handleApiError(error, toast, { showToast: false });
        setError(errorInfo.message);
      })
      .finally(() => setLoading(false));
  }, [filters]);

  useEffect(() => { load(); }, [load]);

  useEffect(() => {
    Promise.all([
      adminApi.getFilieres(),
      formateursApi.getFormateurs()
    ])
      .then(([f, form]) => {
        setFilieres(f.data.data);
        setFormateurs(form.data.data);
      })
      .catch((error) => handleApiError(error, toast));
  }, []);

  useEffect(() => {
    if (selFiliere) {
      adminApi.getNiveaux(selFiliere)
        .then(res => setNiveaux(res.data.data))
        .catch((error) => handleApiError(error, toast));
    } else {
      setNiveaux([]);
    }
  }, [selFiliere]);

  useEffect(() => {
    if (selNiveau && currentAnnee?.id) {
      adminApi.getGroupes({ niveau_id: selNiveau, annee_academique_id: currentAnnee.id })
        .then(res => setGroupes(res.data.data))
        .catch((error) => handleApiError(error, toast));
    } else {
      setGroupes([]);
    }
  }, [selNiveau, currentAnnee]);

  const toggleExpand = (id) => setExpanded(p => ({ ...p, [id]: !p[id] }));

  const handleCreateUnite = async () => {
    const errors = {};
    if (!formU.filiere_id) errors.filiere_id = 'La filière est requise';
    if (!formU.nom) errors.nom = 'Le nom est requis';
    if (!formU.numero_annee) errors.numero_annee = "L'année est requise";
    if (!formU.semestre) errors.semestre = 'Le semestre est requis';

    if (Object.keys(errors).length > 0) {
      setFormErrors(errors);
      toast.error('Veuillez remplir tous les champs obligatoires');
      return;
    }

    setSaving(true);
    setFormErrors({});

    try {
      await unitesApi.createUnite(formU);
      showSuccess(toast, 'Unité créée');
      setOpenUnite(false);
      setFormU({ filiere_id: '', nom: '', numero_annee: '', semestre: '' });
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

  const handleCreateSequence = async () => {
    if (!selectedUnite) return;

    const errors = {};
    if (!formS.nom) errors.nom = 'Le nom est requis';
    if (!formS.coefficient) errors.coefficient = 'Le coefficient est requis';
    if (!formS.formateur_id) errors.formateur_id = 'Le formateur est requis';

    if (Object.keys(errors).length > 0) {
      setFormErrors(errors);
      toast.error('Veuillez remplir tous les champs obligatoires');
      return;
    }

    setSaving(true);
    setFormErrors({});

    try {
      await sequencesApi.createSequence({ ...formS, unite_id: selectedUnite.id });
      showSuccess(toast, 'Séquence créée');
      setOpenSeq(false);
      setFormS({ nom: '', coefficient: '', nombre_controles: 2, formateur_id: '' });
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

  const handleCreateControle = async () => {
    if (!selectedSeq) return;

    const errors = {};
    if (!formC.nom) errors.nom = 'Le nom est requis';

    if (Object.keys(errors).length > 0) {
      setFormErrors(errors);
      toast.error('Veuillez remplir tous les champs obligatoires');
      return;
    }

    setSaving(true);
    setFormErrors({});

    try {
      await controlesApi.createControle({ ...formC, sequence_id: selectedSeq.id });
      showSuccess(toast, 'Contrôle créé');
      setOpenCtrl(false);
      setFormC({ nom: '', sequence_id: '' });
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

  const handleDeleteUnite = (id, nomUnite) => {
    setDeleteConfirm({ type: 'unite', id, nom: nomUnite });
  };

  const handleDeleteSeq = (id, nomSeq) => {
    setDeleteConfirm({ type: 'sequence', id, nom: nomSeq });
  };

  const handleDeleteCtrl = (id, nomCtrl) => {
    setDeleteConfirm({ type: 'controle', id, nom: nomCtrl });
  };

  const confirmDelete = async () => {
    const { type, id, nom } = deleteConfirm;
    try {
      if (type === 'unite') {
        await unitesApi.deleteUnite(id);
        showSuccess(toast, 'Unité supprimée');
      } else if (type === 'sequence') {
        await sequencesApi.deleteSequence(id);
        showSuccess(toast, 'Séquence supprimée');
      } else if (type === 'controle') {
        await controlesApi.deleteControle(id);
        showSuccess(toast, 'Contrôle supprimé');
      }
      setDeleteConfirm({ type: null, id: null, nom: '' });
      load();
    } catch (error) {
      console.error('Failed to delete:', error);
      if (type === 'unite' && error.response?.data?.message?.includes('constraint')) {
        toast.error('Impossible de supprimer. Cette unité contient peut-être des séquences ou des notes.');
      } else {
        handleApiError(error, toast);
      }
    }
  };

  const handleToggleUnite = async (id) => {
    try {
      await unitesApi.toggleUnite(id);
      load();
    } catch (error) {
      handleApiError(error, toast);
    }
  };

  const handleScanUnites = async () => {
    if (!scanImage || !scanFiliere || !scanSemestre) {
      toast.error('Sélectionner une filière, un semestre et un document');
      return;
    }
    setExtracting(true);
    setScanResults([]);

    try {
      const res = await scanApi.scanUnitesDocument(scanImage, scanFiliere, scanSemestre);

      if (!res.data.success) {
        throw new Error(res.data.message || 'Erreur inconnue');
      }

      const data = res.data.data.resultats || [];

      if (data.length === 0) {
        toast.warning('Aucune unité trouvée. Vérifiez l\'image.');
      } else {
        showSuccess(toast, `${data.length} unité(s) trouvée(s)`);
      }
      setScanResults(data);
    } catch (error) {
      handleApiError(error, toast);
      setScanResults([]);
    } finally {
      setExtracting(false);
    }
  };

  const handleConfirmScan = async () => {
    if (scanResults.length === 0) return;

    setScanSaving(true);
    try {
      const res = await scanApi.confirmScanUnites({
        unites: scanResults,
        filiere_code: filieres.find(f => f.id == scanFiliere)?.code ?? '',
      });

      showSuccess(toast, `${res.data.data.crees} unités créées, ${res.data.data.mis_a_jour} mises à jour`);
      setScanOpen(false);
      setScanResults([]);
      load();
    } catch (error) {
      handleApiError(error, toast);
    } finally {
      setScanSaving(false);
    }
  };

  if (loading) return (
    <div className="text-center mt-5">
      <Spinner />
      <p className="mt-2 text-muted">Chargement des unités...</p>
    </div>
  );

  return (
    <div className="page">
      <div className="page-header">
        <h2 className="page-title">Unités & Séquences</h2>
        <div style={{ display: 'flex', gap: 8 }}>
          <button
            className="btn btn-outline"
            onClick={() => { setScanOpen(true); setScanImage(null); setScanResults([]); setScanSemestre(''); }}
            disabled={filieres.length === 0}
            title={filieres.length === 0 ? "Veuillez d'abord ajouter une filière" : ''}
          >
            Scanner Document
          </button>
          <button
            className="btn btn-primary"
            onClick={() => { setOpenUnite(true); setFormErrors({}); }}
            disabled={filieres.length === 0}
            title={filieres.length === 0 ? "Veuillez d'abord ajouter une filière" : ''}
          >
            + Nouvelle unité
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

      <div className="filter-bar">
        <select
          className="form-select"
          value={filters.filiere_id}
          onChange={e => {
            setFilters(p => ({ ...p, filiere_id: e.target.value, niveau_id: '', groupe_id: '' }));
            setSelFiliere(e.target.value);
            setSelNiveau('');
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
          }}
          disabled={!filters.filiere_id}
        >
          <option value="">Tous les niveaux</option>
          {niveaux.map(n => <option key={n.id} value={n.id}>{formatNiveau(n.numero)}</option>)}
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
      </div>

      {unites.length === 0 && !error ? (
        <div className="text-center mt-5" style={{ padding: 40 }}>
          <h4 style={{ color: '#666', marginBottom: 12 }}>Aucune unite trouvee</h4>
          <p style={{ color: '#999', marginBottom: 24 }}>
            {filters.filiere_id || filters.niveau_id || filters.groupe_id
              ? 'Aucun resultat pour ces filtres. Essayez de modifier vos criteres.'
              : "Commencez par creer une unite d'enseignement."}
          </p>
          <button
            className="btn btn-primary"
            onClick={() => { setOpenUnite(true); setFormErrors({}); }}
          >
            + Creer une unite
          </button>
        </div>
      ) : (
        unites.map(u => (
          <div className="accordion-item" key={u.id}>
            <div className="accordion-header" onClick={() => toggleExpand(u.id)}>
              <div style={{ display: 'flex', alignItems: 'center', gap: 12 }}>
                <span style={{ fontWeight: 500 }}>{u.nom}</span>
                <Badge label={`S${u.semestre}`} color="teal" />
                <Badge label={formatNiveau(u.numero_annee)} color="gray" />
                {!u.is_active && <Badge label="Inactif" color="red" />}
              </div>
              <div style={{ display: 'flex', gap: 8 }} onClick={e => e.stopPropagation()}>
                <button className="btn btn-sm btn-outline" onClick={() => { setSelectedUnite(u); setOpenSeq(true); setFormErrors({}); }}>
                  + Séquence
                </button>
                <button className="btn btn-sm btn-outline" onClick={() => handleToggleUnite(u.id)}>
                  {u.is_active ? 'Désactiver' : 'Activer'}
                </button>
                <button
                  className="btn btn-sm btn-danger"
                  onClick={() => handleDeleteUnite(u.id, u.nom)}
                  disabled={deletingUnite === u.id}
                >
                  {deletingUnite === u.id ? 'Suppression...' : 'Supprimer'}
                </button>
                <span>{expanded[u.id] ? '▲' : '▼'}</span>
              </div>
            </div>

            {expanded[u.id] && (
              <div className="accordion-body">
                {u.sequences?.length === 0 ? (
                  <div style={{ fontSize: 13, color: 'var(--gray-400)' }}>Aucune séquence</div>
                ) : u.sequences?.map(s => (
                  <div key={s.id} style={{ marginBottom: 12, padding: 12, border: '1px solid var(--gray-200)', borderRadius: 4 }}>
                    <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 8 }}>
                      <div style={{ display: 'flex', alignItems: 'center', gap: 10 }}>
                        <span style={{ fontWeight: 500 }}>{s.nom}</span>
                        <Badge label={`Coeff ${s.coefficient}`} color="teal" />
                        <Badge label={`${s.nombre_controles} contrôle(s)`} color="gray" />
                      </div>
                      <div style={{ display: 'flex', gap: 8 }}>
                        <button className="btn btn-sm btn-outline" onClick={() => { setSelectedSeq(s); setOpenCtrl(true); setFormErrors({}); }}>
                          + Contrôle
                        </button>
                        <button
                          className="btn btn-sm btn-danger"
                          onClick={() => handleDeleteSeq(s.id, s.nom)}
                          disabled={deletingSeq === s.id}
                        >
                          {deletingSeq === s.id ? 'Suppression...' : 'Supprimer'}
                        </button>
                      </div>
                    </div>

                    {s.controles?.length > 0 && (
                      <table style={{ width: '100%', fontSize: 13 }}>
                        <thead>
                          <tr>
                            <th>Contrôle</th>
                            <th>Formateur</th>
                            <th>Actions</th>
                          </tr>
                        </thead>
                        <tbody>
                          {s.controles.map(c => (
                            <tr key={c.id}>
                              <td>{c.nom}</td>
                              <td>{c.formateur?.name ?? '—'}</td>
                              <td>
                                <button
                                  className="btn btn-sm btn-danger"
                                  onClick={() => handleDeleteCtrl(c.id, c.nom)}
                                  disabled={deletingCtrl === c.id}
                                >
                                  {deletingCtrl === c.id ? 'Suppression...' : 'Supprimer'}
                                </button>
                              </td>
                            </tr>
                          ))}
                        </tbody>
                      </table>
                    )}
                  </div>
                ))}
              </div>
            )}
          </div>
        ))
      )}

      {/* Create unite modal */}
      <Modal open={openUnite} onClose={() => { setOpenUnite(false); setFormErrors({}); }} title="Nouvelle Unité">
        <div className="form-group">
          <label className="form-label">Filière *</label>
          <select
            className={`form-select ${formErrors.filiere_id ? 'border-red-500' : ''}`}
            value={formU.filiere_id ?? ''}
            onChange={e => setFormU(p => ({ ...p, filiere_id: e.target.value }))}
            style={formErrors.filiere_id ? { borderColor: '#ef4444' } : {}}
          >
            <option value="">Sélectionner</option>
            {filieres.map(f => <option key={f.id} value={f.id}>{f.nom}</option>)}
          </select>
          {formErrors.filiere_id && (
            <div style={{ color: '#ef4444', fontSize: 12, marginTop: 4 }}>{formErrors.filiere_id}</div>
          )}
        </div>
        <div className="form-group">
          <label className="form-label">Nom de l'unité *</label>
          <input
            className={`form-input ${formErrors.nom ? 'border-red-500' : ''}`}
            value={formU.nom ?? ''}
            onChange={e => setFormU(p => ({ ...p, nom: e.target.value }))}
            style={formErrors.nom ? { borderColor: '#ef4444' } : {}}
          />
          {formErrors.nom && (
            <div style={{ color: '#ef4444', fontSize: 12, marginTop: 4 }}>{formErrors.nom}</div>
          )}
        </div>
        <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 12 }}>
          <div className="form-group">
            <label className="form-label">Année *</label>
            <select
              className={`form-select ${formErrors.numero_annee ? 'border-red-500' : ''}`}
              value={formU.numero_annee ?? ''}
              onChange={e => setFormU(p => ({ ...p, numero_annee: e.target.value }))}
              style={formErrors.numero_annee ? { borderColor: '#ef4444' } : {}}
            >
              <option value="">—</option>
              <option value="1">1ère année</option>
              <option value="2">2ème année</option>
              <option value="3">3ème année</option>
            </select>
            {formErrors.numero_annee && (
              <div style={{ color: '#ef4444', fontSize: 12, marginTop: 4 }}>{formErrors.numero_annee}</div>
            )}
          </div>
          <div className="form-group">
            <label className="form-label">Semestre *</label>
            <select
              className={`form-select ${formErrors.semestre ? 'border-red-500' : ''}`}
              value={formU.semestre ?? ''}
              onChange={e => setFormU(p => ({ ...p, semestre: e.target.value }))}
              style={formErrors.semestre ? { borderColor: '#ef4444' } : {}}
            >
              <option value="">—</option>
              <option value="1">S1</option>
              <option value="2">S2</option>
            </select>
            {formErrors.semestre && (
              <div style={{ color: '#ef4444', fontSize: 12, marginTop: 4 }}>{formErrors.semestre}</div>
            )}
          </div>
        </div>
        <div className="modal-footer">
          <button className="btn btn-outline" onClick={() => { setOpenUnite(false); setFormErrors({}); }}>Annuler</button>
          <button className="btn btn-primary" onClick={handleCreateUnite} disabled={saving}>
            {saving ? 'Création...' : 'Créer'}
          </button>
        </div>
      </Modal>

      {/* Create sequence modal */}
      <Modal open={openSeq} onClose={() => { setOpenSeq(false); setFormErrors({}); }} title={`Nouvelle Séquence — ${selectedUnite?.nom}`}>
        <div className="form-group">
          <label className="form-label">Nom de la séquence *</label>
          <input
            className={`form-input ${formErrors.nom ? 'border-red-500' : ''}`}
            value={formS.nom ?? ''}
            onChange={e => setFormS(p => ({ ...p, nom: e.target.value }))}
            style={formErrors.nom ? { borderColor: '#ef4444' } : {}}
          />
          {formErrors.nom && (
            <div style={{ color: '#ef4444', fontSize: 12, marginTop: 4 }}>{formErrors.nom}</div>
          )}
        </div>
        <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr 1fr', gap: 12 }}>
          <div className="form-group">
            <label className="form-label">Coefficient *</label>
            <input
              type="number"
              className={`form-input ${formErrors.coefficient ? 'border-red-500' : ''}`}
              value={formS.coefficient ?? ''}
              onChange={e => setFormS(p => ({ ...p, coefficient: e.target.value }))}
              style={formErrors.coefficient ? { borderColor: '#ef4444' } : {}}
            />
            {formErrors.coefficient && (
              <div style={{ color: '#ef4444', fontSize: 12, marginTop: 4 }}>{formErrors.coefficient}</div>
            )}
          </div>
          <div className="form-group">
            <label className="form-label">Nombre de contrôles</label>
            <select className="form-select" value={formS.nombre_controles ?? 2} onChange={e => setFormS(p => ({ ...p, nombre_controles: e.target.value }))}>
              <option value="1">1</option>
              <option value="2">2</option>
              <option value="3">3</option>
            </select>
          </div>
          <div className="form-group">
            <label className="form-label">Formateur *</label>
            <select
              className={`form-select ${formErrors.formateur_id ? 'border-red-500' : ''}`}
              value={formS.formateur_id ?? ''}
              onChange={e => setFormS(p => ({ ...p, formateur_id: e.target.value }))}
              style={formErrors.formateur_id ? { borderColor: '#ef4444' } : {}}
            >
              <option value="">Sélectionner</option>
              {formateurs.map(f => <option key={f.id} value={f.id}>{f.user?.name}</option>)}
            </select>
            {formErrors.formateur_id && (
              <div style={{ color: '#ef4444', fontSize: 12, marginTop: 4 }}>{formErrors.formateur_id}</div>
            )}
          </div>
        </div>
        <div className="modal-footer">
          <button className="btn btn-outline" onClick={() => { setOpenSeq(false); setFormErrors({}); }}>Annuler</button>
          <button className="btn btn-primary" onClick={handleCreateSequence} disabled={saving}>
            {saving ? 'Création...' : 'Créer'}
          </button>
        </div>
      </Modal>

      {/* Create controle modal */}
      <Modal open={openCtrl} onClose={() => { setOpenCtrl(false); setFormErrors({}); }} title={`Nouveau Contrôle — ${selectedSeq?.nom}`}>
        <div className="form-group">
          <label className="form-label">Nom du contrôle *</label>
          <input
            className={`form-input ${formErrors.nom ? 'border-red-500' : ''}`}
            value={formC.nom ?? ''}
            onChange={e => setFormC(p => ({ ...p, nom: e.target.value }))}
            style={formErrors.nom ? { borderColor: '#ef4444' } : {}}
          />
          {formErrors.nom && (
            <div style={{ color: '#ef4444', fontSize: 12, marginTop: 4 }}>{formErrors.nom}</div>
          )}
        </div>
        <div className="modal-footer">
          <button className="btn btn-outline" onClick={() => { setOpenCtrl(false); setFormErrors({}); }}>Annuler</button>
          <button className="btn btn-primary" onClick={handleCreateControle} disabled={saving}>
            {saving ? 'Création...' : 'Créer'}
          </button>
        </div>
      </Modal>

      {/* Scan unites document modal */}
      <Modal open={scanOpen} onClose={() => { setScanOpen(false); setScanImage(null); setScanResults([]); setScanSemestre(''); }} title="Scanner Document Unités & Séquences" style={{ maxWidth: 1200, width: '90vw', maxHeight: '80vh', overflowY: 'auto' }}>
        <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr 1fr', gap: 16, marginBottom: 16 }}>
          <div className="form-group">
            <label className="form-label">Filière *</label>
            <select className="form-select" value={scanFiliere} onChange={e => setScanFiliere(e.target.value)}>
              <option value="">Sélectionner</option>
              {filieres.map(f => <option key={f.id} value={f.id}>{f.nom}</option>)}
            </select>
          </div>
          <div className="form-group">
            <label className="form-label">Semestre *</label>
            <select className="form-select" value={scanSemestre} onChange={e => setScanSemestre(e.target.value)}>
              <option value="">Sélectionner</option>
              <option value="1">S1</option>
              <option value="2">S2</option>
            </select>
          </div>
          <div className="form-group">
            <label className="form-label">Document photo *</label>
            <input type="file" className="form-input" accept="image/jpeg,image/png,image/jpg,image/webp" onChange={e => setScanImage(e.target.files[0])} />
          </div>
        </div>

        <div style={{ display: 'flex', gap: 8, marginBottom: 16 }}>
          <button
            className="btn btn-primary"
            onClick={handleScanUnites}
            disabled={!scanImage || !scanFiliere || !scanSemestre || extracting}
          >
            {extracting ? 'Extraction en cours...' : 'Scanner le document'}
          </button>
        </div>

        {extracting && (
          <div style={{ textAlign: 'center', padding: 20 }}>
            <Spinner />
            <p style={{ marginTop: 8, color: '#666' }}>Analyse du document en cours... Veuillez patienter.</p>
          </div>
        )}

        {!extracting && scanResults.length === 0 && scanImage && scanFiliere && scanSemestre && (
          <div style={{ textAlign: 'center', padding: 32, border: '1px solid #e5e7eb', borderRadius: 8 }}>
            <h4 style={{ color: '#666', marginBottom: 8 }}>Aucune unite detectee</h4>
            <p style={{ color: '#999', marginBottom: 16, fontSize: 14 }}>
              Verifiez l'image ou ressayer le scan.
            </p>
            <button className="btn btn-primary" onClick={handleScanUnites}>
              Ressayer le scan
            </button>
          </div>
        )}

        {scanResults.length > 0 && (
          <div style={{ overflowX: 'auto', marginTop: 16, border: '1px solid #e5e7eb', borderRadius: 8 }}>
            <table style={{ fontSize: '15px', minWidth: '600px', width: '100%' }}>
              <thead>
                <tr style={{ backgroundColor: '#f9fafb' }}>
                  <th style={{ padding: '12px 16px', textAlign: 'left', fontWeight: 600, minWidth: '200px' }}>Unité</th>
                  <th style={{ padding: '12px 16px', textAlign: 'left', fontWeight: 600, minWidth: '120px' }}>Année</th>
                  <th style={{ padding: '12px 16px', textAlign: 'left', fontWeight: 600, minWidth: '100px' }}>Semestre</th>
                  <th style={{ padding: '12px 16px', textAlign: 'left', fontWeight: 600, minWidth: '250px' }}>Séquences</th>
                </tr>
              </thead>
              <tbody>
                {scanResults.map((u, idx) => (
                  <tr key={idx} style={{ borderBottom: '1px solid #e5e7eb' }}>
                    <td style={{ padding: '12px 16px' }}>
                      <strong>{u.nom}</strong>
                    </td>
                    <td style={{ padding: '12px 16px' }}>{u.numero_annee == 1 ? '1ère' : u.numero_annee + 'ème'} année</td>
                    <td style={{ padding: '12px 16px' }}>S{u.semestre}</td>
                    <td style={{ padding: '12px 16px' }}>
                      {u.sequences?.map((s, i) => (
                        <div key={i} style={{ fontSize: 14, marginBottom: 6, padding: '4px 0' }}>
                          <strong>{s.nom}</strong> (Coeff {s.coefficient}, {s.nombre_controles} contrôle(s))
                        </div>
                      ))}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}

      <div className="modal-footer" style={{ marginTop: 16 }}>
        <button className="btn btn-outline" onClick={() => { setScanOpen(false); setScanImage(null); setScanResults([]); setScanSemestre(''); }}>Annuler</button>
        <button className="btn btn-primary" onClick={handleConfirmScan} disabled={scanSaving || scanResults.length === 0}>
          {scanSaving ? (
            <>
              <Spinner /> Enregistrement...
            </>
          ) : `Confirmer ${scanResults.length} unité(s)`}
        </button>
      </div>
      </Modal>

      <Modal open={deleteConfirm.type !== null} onClose={() => setDeleteConfirm({ type: null, id: null, nom: '' })} title="Confirmer la suppression">
        <p>
          {deleteConfirm.type === 'unite' && `Supprimer l'unité "${deleteConfirm.nom}" ? Cette action est irréversible.`}
          {deleteConfirm.type === 'sequence' && `Supprimer la séquence "${deleteConfirm.nom}" ? Cette action est irréversible.`}
          {deleteConfirm.type === 'controle' && `Supprimer le contrôle "${deleteConfirm.nom}" ? Cette action est irréversible.`}
        </p>
        <div className="modal-footer">
          <button className="btn btn-outline" onClick={() => setDeleteConfirm({ type: null, id: null, nom: '' })}>Annuler</button>
          <button className="btn btn-danger" onClick={confirmDelete}>Supprimer</button>
        </div>
      </Modal>
    </div>
  );
}

