import { useEffect, useState, useCallback } from 'react';
import { adminApi } from '../../api/admin';
import { useToast } from '../../context/ToastContext';
import { useAnneeAcademique } from '../../context/AnneeAcademiqueContext';
import Modal from '../../components/common/Modal';
import Spinner from '../../components/common/Spinner';
import Badge from '../../components/common/Badge';
import { formatNiveau, toWesternDigits } from '../../utils/helpers';
import '../../css/components.css';
import '../../css/layout.css';

const SECTIONS = ['Technicien Spécialisé', 'Qualification', 'Technicien'];

export default function FilieresGroupes() {
  const [filieres, setFilieres]   = useState([]);
  const [groupes, setGroupes]     = useState([]);
  const [niveaux, setNiveaux]     = useState([]);
  const [loading, setLoading]     = useState(true);
  const [selFiliere, setSelFiliere] = useState('');
  const { currentAnnee, annees } = useAnneeAcademique();

  const [openF, setOpenF]         = useState(false);
  const [editingFiliere, setEditingFiliere] = useState(null);
  const [openN, setOpenN]         = useState(false);
  const [selectedFiliere, setSelectedFiliere] = useState(null);
  const [expanded, setExpanded]   = useState({});

  const [openG, setOpenG]         = useState(false);

  const [saving, setSaving]       = useState(false);
  const [errors, setErrors]       = useState({});

  const [formF, setFormF] = useState({ nom: '', nom_ar: '', code: '', section: '', type_formation: '', nombre_annees: 1 });
  const [formN, setFormN] = useState({ numero: 1 });
  const [formG, setFormG] = useState({ niveau_id: '', annee_academique_id: '', nom: '', promotion: '' });

  const [deleteConfirm, setDeleteConfirm] = useState({ type: null, id: null });
  const toast = useToast();

  const load = useCallback(() => {
    setLoading(true);
    Promise.all([
      adminApi.getFilieres(),
      adminApi.getGroupes({})
    ])
      .then(([fRes, gRes]) => {
        setFilieres(fRes.data.data);
        setGroupes(gRes.data.data);
      })
      .catch((err) => {
        console.error('Failed to load filieres/groupes:', err);
        toast.error('Erreur de chargement');
      })
      .finally(() => setLoading(false));
  }, []);

  useEffect(() => {
    load();
  }, [load]);

  useEffect(() => {
    if (selFiliere) {
      adminApi.getNiveaux(selFiliere)
        .then(res => setNiveaux(res.data.data || []))
        .catch((err) => console.error('Failed to load niveaux:', err));
    }
  }, [selFiliere]);

  const toggleExpand = (id) =>
    setExpanded(p => ({ ...p, [id]: !p[id] }));

  // Auto-generate group name
  const generateGroupName = async () => {
    if (!formG.niveau_id || !formG.annee_academique_id) return '';

    try {
      const res = await adminApi.getGroupes({
        niveau_id: formG.niveau_id,
        annee_academique_id: formG.annee_academique_id
      });
      const existingNames = (res.data.data || [])
        .map(g => g.nom);
      
      const letters = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';
      for (let i = 0; i < letters.length; i++) {
        const name = `Groupe ${letters[i]}`;
        if (!existingNames.includes(name)) {
          return name;
        }
      }
    } catch (error) {
      console.error('Failed to load groups for name generation:', error);
    }
    return '';
  };

  const validateFiliere = () => {
    const newErrors = {};
    if (!formF.nom.trim()) newErrors.nom = 'Le nom est requis';
    if (!formF.code.trim()) newErrors.code = 'Le code est requis';
    if (!formF.section) newErrors.section = 'La section est requise';
    if (!formF.type_formation) newErrors.type_formation = 'Le type est requis';
    if (!formF.nombre_annees || formF.nombre_annees < 1) newErrors.nombre_annees = 'Nombre d\'années invalide';
    setErrors(newErrors);
    return Object.keys(newErrors).length === 0;
  };

  const validateGroupe = () => {
    const newErrors = {};
    if (!formG.niveau_id) newErrors.niveau_id = 'Le niveau est requis';
    if (!formG.annee_academique_id) newErrors.annee_academique_id = 'L\'année académique est requise';
    if (!formG.nom.trim()) newErrors.nom = 'Le nom du groupe est requis';

    const exists = groupes.some(g =>
      g.niveau_id == formG.niveau_id &&
      g.annee_academique_id == formG.annee_academique_id &&
      g.nom.toLowerCase() === formG.nom.trim().toLowerCase()
    );
    if (exists) newErrors.nom = 'Ce groupe existe déjà pour ce niveau et cette année';

    if (!formG.promotion.trim()) newErrors.promotion = 'La promotion est requise';
    setErrors(newErrors);
    return Object.keys(newErrors).length === 0;
  };

  // Filiere handlers
  const openCreateFiliere = () => {
    setEditingFiliere(null);
    setFormF({ nom: '', nom_ar: '', code: '', section: '', type_formation: '', nombre_annees: 1 });
    setErrors({});
    setOpenF(true);
  };

  const openEditFiliere = (f) => {
    setEditingFiliere(f);
    setFormF({ nom: f.nom, nom_ar: f.nom_ar || '', code: f.code, section: f.section, type_formation: f.type_formation || '', nombre_annees: f.nombre_annees });
    setErrors({});
    setOpenF(true);
  };

  const handleSaveFiliere = async () => {
    if (!validateFiliere()) return;
    setSaving(true);
    try {
      if (editingFiliere) {
        await adminApi.updateFiliere(editingFiliere.id, formF);
        toast.success('Filière modifiée');
      } else {
        await adminApi.createFiliere(formF);
        toast.success('Filière créée');
      }
      setOpenF(false);
      setEditingFiliere(null);
      setFormF({ nom: '', nom_ar: '', code: '', section: '', type_formation: '', nombre_annees: 1 });
      setErrors({});
      load();
    } catch (e) {
      console.error('Failed to save filiere:', e);
      toast.error(e.response?.data?.message ?? 'Erreur');
    } finally {
      setSaving(false);
    }
  };

  const handleCreateNiveau = async () => {
    setSaving(true);
    try {
      await adminApi.createNiveau({
        filiere_id: selectedFiliere,
        numero: Number(formN.numero)
      });
      toast.success('Niveau créé');
      setOpenN(false);
      load();
    } catch (e) {
      console.error('Failed to create niveau:', e);
      toast.error(e.response?.data?.message ?? 'Erreur');
    } finally {
      setSaving(false);
    }
  };

  const handleDeleteNiveau = (id) => {
    setDeleteConfirm({ type: 'niveau', id });
  };

  const handleDeleteFiliere = (id) => {
    setDeleteConfirm({ type: 'filiere', id });
  };

  const confirmDelete = async () => {
    const { type, id } = deleteConfirm;
    try {
      if (type === 'niveau') {
        await adminApi.deleteNiveau(id);
        toast.success('Niveau supprimé');
      } else if (type === 'filiere') {
        await adminApi.deleteFiliere(id);
        toast.success('Filière supprimée');
      } else if (type === 'groupe') {
        await adminApi.deleteGroupe(id);
        toast.success('Groupe supprimé');
      }
      setDeleteConfirm({ type: null, id: null });
      load();
    } catch (e) {
      console.error('Failed to delete:', e);
      toast.error(e.response?.data?.message ?? 'Erreur');
    }
  };

  // Groupe handlers
  const handleOpenGroupeModal = async () => {
    const autoName = await generateGroupName();
    setFormG({ ...formG, nom: autoName, annee_academique_id: currentAnnee?.id || formG.annee_academique_id });
    setErrors({});
    setOpenG(true);
  };

  const handleCreateGroupe = async () => {
    if (!validateGroupe()) return;
    setSaving(true);
    try {
      await adminApi.createGroupe(formG);
      toast.success('Groupe créé');
      setOpenG(false);
      setFormG({ niveau_id: '', annee_academique_id: '', nom: '', promotion: '' });
      setSelFiliere('');
      setErrors({});
      load();
    } catch (e) {
      console.error('Failed to create groupe:', e);
      toast.error(e.response?.data?.message ?? 'Erreur');
    } finally {
      setSaving(false);
    }
  };

  const handleDeleteGroupe = (id) => {
    setDeleteConfirm({ type: 'groupe', id });
  };

  if (loading) return <div className="text-center mt-5"><Spinner /></div>;

  return (
    <div className="page">
      {/* FILIERES SECTION */}
      <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', margin: '24px 0 12px' }}>
        <h3 style={{ fontSize: 18, margin: 0 }}>Filières</h3>
        <button className="btn btn-accent" onClick={openCreateFiliere}>
          + Nouvelle filière
        </button>
      </div>
      {filieres.length === 0 ? (
        <div className="card card-body" style={{ textAlign: 'center', color: 'var(--gray-400)' }}>
          Aucune filière
        </div>
      ) : (
        <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fill, minmax(300px, 1fr))', gap: 16 }}>
          {filieres.map(f => (
            <div className="card" key={f.id}>
              <div className="card-body">
                <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'flex-start', marginBottom: 12 }}>
                  <div>
                    <div style={{ fontWeight: 600, fontSize: 15, marginBottom: 4 }}>{f.nom}</div>
                    {f.nom_ar && <div style={{ fontSize: 13, color: 'var(--gray-500)', direction: 'rtl', marginBottom: 2 }}>{toWesternDigits(f.nom_ar)}</div>}
                    <div style={{ fontSize: 12, color: 'var(--gray-500)' }}>{f.type_formation_fr ?? f.type_formation}</div>
                    {f.type_formation_ar && <div style={{ fontSize: 12, color: 'var(--gray-500)', direction: 'rtl' }}>{toWesternDigits(f.type_formation_ar)}</div>}
                  </div>
                  <Badge label={f.code} color="blue" />
                </div>
                <div style={{ fontSize: 12, color: 'var(--gray-500)', marginBottom: 12 }}>
                  {f.nombre_annees} année{f.nombre_annees > 1 ? 's' : ''}
                </div>
                <div style={{ borderTop: '1px solid var(--gray-100)', paddingTop: 12 }}>
                  <div
                    style={{ fontSize: 12, fontWeight: 600, color: 'var(--gray-600)', marginBottom: 8, cursor: 'pointer', userSelect: 'none' }}
                    onClick={() => toggleExpand(f.id)}
                  >
                    Niveaux {expanded[f.id] ? '▲' : '▼'}
                  </div>
                  {expanded[f.id] && (
                    <>
                      {f.niveaux?.length === 0 ? (
                        <div style={{ fontSize: 12, color: 'var(--gray-400)', marginBottom: 8 }}>Aucun niveau</div>
                      ) : f.niveaux?.map(n => (
                        <div key={n.id} style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', padding: '4px 0', fontSize: 13 }}>
                          <span>{formatNiveau(n.numero)}</span>
                          <button className="btn btn-sm btn-danger" onClick={() => handleDeleteNiveau(n.id)}>Supprimer</button>
                        </div>
                      ))}
                      <button className="btn btn-sm btn-accent" style={{ marginTop: 8 }} onClick={() => { setSelectedFiliere(f.id); setOpenN(true); }}>
                        + Ajouter niveau
                      </button>
                    </>
                  )}
                </div>
                <div style={{ borderTop: '1px solid var(--gray-100)', paddingTop: 10, marginTop: 10, display: 'flex', gap: 8 }}>
                  <button className="btn btn-sm btn-primary" onClick={() => openEditFiliere(f)}>Modifier</button>
                  <button className="btn btn-sm btn-danger" onClick={() => handleDeleteFiliere(f.id)}>Supprimer</button>
                </div>
              </div>
            </div>
          ))}
        </div>
      )}

      {/* GROUPES SECTION */}
      <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', margin: '32px 0 12px' }}>
        <h3 style={{ fontSize: 18, margin: 0 }}>Groupes</h3>
        <button className="btn btn-primary" onClick={handleOpenGroupeModal}>
          + Nouveau groupe
        </button>
      </div>
      <div className="table-wrap">
        <table>
          <thead>
            <tr>
              <th>Nom</th>
              <th>Filière</th>
              <th>Niveau</th>
              <th>Promotion</th>
              <th>Nbr étudiants</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody>
            {(() => {
              const filtered = currentAnnee ? groupes.filter(g => g.annee_academique_id === currentAnnee.id) : groupes;
              return filtered.length === 0 ? (
              <tr><td colSpan={6} className="table-empty">Aucun groupe</td></tr>
              ) : filtered.map(g => (
              <tr key={g.id}>
                <td><strong>{g.nom}</strong></td>
                <td>{g.niveau?.filiere?.nom ?? '—'}</td>
                <td>{formatNiveau(g.niveau?.numero)}</td>
                <td>{g.promotion}</td>
                <td>{g.etudiants_count ?? '0'}</td>
                <td>
                  <button className="btn btn-sm btn-danger" onClick={() => handleDeleteGroupe(g.id)}>Supprimer</button>
                </td>
              </tr>
              ));
            })()}
          </tbody>
        </table>
      </div>

      {/* Create/Edit Filiere Modal */}
      <Modal open={openF} onClose={() => { setOpenF(false); setEditingFiliere(null); setErrors({}); }} title={editingFiliere ? 'Modifier la Filière' : 'Nouvelle Filière'}>
        <div className="form-group">
          <label className="form-label">Nom</label>
          <input className="form-input" placeholder="Aide-Soignant" value={formF.nom} onChange={e => { setFormF(p => ({ ...p, nom: e.target.value })); setErrors(p => ({ ...p, nom: undefined })); }} />
          {errors.nom && <div className="form-error">{errors.nom}</div>}
        </div>
        <div className="form-group">
          <label className="form-label">Nom (عربي)</label>
          <input className="form-input" placeholder="مساعد معالج" value={formF.nom_ar} onChange={e => setFormF(p => ({ ...p, nom_ar: e.target.value }))} style={{ direction: 'rtl' }} />
        </div>
        <div className="form-group">
          <label className="form-label">Code</label>
          <input className="form-input" placeholder="AS" value={formF.code} onChange={e => { setFormF(p => ({ ...p, code: e.target.value })); setErrors(p => ({ ...p, code: undefined })); }} />
          {errors.code && <div className="form-error">{errors.code}</div>}
        </div>
        <div className="form-group">
          <label className="form-label">Section</label>
          <select className="form-select" value={formF.section} onChange={e => { setFormF(p => ({ ...p, section: e.target.value })); setErrors(p => ({ ...p, section: undefined })); }}>
            <option value="">Sélectionner</option>
            {SECTIONS.map(s => <option key={s} value={s}>{s}</option>)}
          </select>
          {errors.section && <div className="form-error">{errors.section}</div>}
        </div>
        <div className="form-group">
          <label className="form-label">Type de formation</label>
          <select className="form-select" value={formF.type_formation} onChange={e => { setFormF(p => ({ ...p, type_formation: e.target.value })); setErrors(p => ({ ...p, type_formation: undefined })); }}>
            <option value="">Sélectionner</option>
            <option value="Qualification">Qualification</option>
            <option value="Technicien">Technicien</option>
            <option value="Specialisation">Spécialisation</option>
          </select>
          {errors.type_formation && <div className="form-error">{errors.type_formation}</div>}
        </div>
        <div className="form-group">
          <label className="form-label">Nombre d'années</label>
          <input type="number" min={1} max={5} className="form-input" value={formF.nombre_annees} onChange={e => { setFormF(p => ({ ...p, nombre_annees: e.target.value })); setErrors(p => ({ ...p, nombre_annees: undefined })); }} />
          {errors.nombre_annees && <div className="form-error">{errors.nombre_annees}</div>}
        </div>
        <div className="modal-footer">
          <button className="btn btn-outline" onClick={() => { setOpenF(false); setEditingFiliere(null); setErrors({}); }}>Annuler</button>
          <button className="btn btn-primary" onClick={handleSaveFiliere} disabled={saving}>{saving ? 'Enregistrement...' : (editingFiliere ? 'Enregistrer' : 'Créer')}</button>
        </div>
      </Modal>

      {/* Add Niveau Modal */}
      <Modal open={openN} onClose={() => setOpenN(false)} title="Ajouter un Niveau">
        <div className="form-group">
          <label className="form-label">Numéro d'année</label>
          <input type="number" min={1} max={5} className="form-input" value={formN.numero} onChange={e => setFormN({ numero: e.target.value })} />
        </div>
        <div className="modal-footer">
          <button className="btn btn-outline" onClick={() => setOpenN(false)}>Annuler</button>
          <button className="btn btn-primary" onClick={handleCreateNiveau} disabled={saving}>{saving ? 'Ajout...' : 'Ajouter'}</button>
        </div>
      </Modal>

      {/* Create Groupe Modal */}
      <Modal open={openG} onClose={() => { setOpenG(false); setErrors({}); }} title="Nouveau Groupe">
        <div className="form-group">
          <label className="form-label">Filière</label>
          <select className="form-select" value={selFiliere} onChange={e => { setSelFiliere(e.target.value); setFormG(p => ({ ...p, niveau_id: '' })); setErrors(p => ({ ...p, niveau_id: undefined })); }}>
            <option value="">Sélectionner</option>
            {filieres.map(f => <option key={f.id} value={f.id}>{f.nom}</option>)}
          </select>
          {errors.niveau_id && <div className="form-error">{errors.niveau_id}</div>}
        </div>
        <div className="form-group">
          <label className="form-label">Niveau</label>
          <select className="form-select" value={formG.niveau_id} onChange={e => { setFormG(p => ({ ...p, niveau_id: e.target.value })); setErrors(p => ({ ...p, niveau_id: undefined })); }} disabled={!selFiliere}>
            <option value="">Sélectionner</option>
            {niveaux.map(n => <option key={n.id} value={n.id}>{formatNiveau(n.numero)}</option>)}
          </select>
        </div>
        <div className="form-group">
          <label className="form-label">Année académique</label>
          <select className="form-select" value={formG.annee_academique_id} onChange={e => { setFormG(p => ({ ...p, annee_academique_id: e.target.value })); setErrors(p => ({ ...p, annee_academique_id: undefined })); }}>
            <option value="">Sélectionner</option>
            {annees.map(a => <option key={a.id} value={a.id}>{a.label}</option>)}
          </select>
          {errors.annee_academique_id && <div className="form-error">{errors.annee_academique_id}</div>}
        </div>
        <div className="form-group">
          <label className="form-label">Nom du groupe</label>
          <input className="form-input" placeholder="Groupe A" value={formG.nom} onChange={e => { setFormG(p => ({ ...p, nom: e.target.value })); setErrors(p => ({ ...p, nom: undefined })); }} />
          {errors.nom && <div className="form-error">{errors.nom}</div>}
        </div>
        <div className="form-group">
          <label className="form-label">Promotion</label>
          <input className="form-input" placeholder="2025/2026" value={formG.promotion} onChange={e => { setFormG(p => ({ ...p, promotion: e.target.value })); setErrors(p => ({ ...p, promotion: undefined })); }} />
          {errors.promotion && <div className="form-error">{errors.promotion}</div>}
        </div>
        <div className="modal-footer">
          <button className="btn btn-outline" onClick={() => { setOpenG(false); setErrors({}); }}>Annuler</button>
          <button className="btn btn-primary" onClick={handleCreateGroupe} disabled={saving}>{saving ? 'Création...' : 'Créer'}</button>
        </div>
      </Modal>

      <Modal open={deleteConfirm.type !== null} onClose={() => setDeleteConfirm({ type: null, id: null })} title="Confirmer la suppression">
        <p>
          {deleteConfirm.type === 'niveau' && 'Supprimer ce niveau ?'}
          {deleteConfirm.type === 'filiere' && 'Supprimer cette filière et toutes ses données ? Cette action est irréversible.'}
          {deleteConfirm.type === 'groupe' && 'Supprimer ce groupe ?'}
        </p>
        <div className="modal-footer">
          <button className="btn btn-outline" onClick={() => setDeleteConfirm({ type: null, id: null })}>Annuler</button>
          <button className="btn btn-danger" onClick={confirmDelete}>Supprimer</button>
        </div>
      </Modal>
    </div>
  );
}
