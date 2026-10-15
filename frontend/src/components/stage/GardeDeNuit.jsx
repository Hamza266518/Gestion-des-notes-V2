import { useState, useEffect } from 'react';
import { FiSave } from 'react-icons/fi';
import { useAnneeAcademique } from '../../context/AnneeAcademiqueContext';
import { stagesApi } from '../../api/stages';
import { handleApiError, showSuccess } from '../../utils/errorHandler';
import { useToast } from '../../context/ToastContext';

export default function GardeDeNuit({ etudiant, onDataChange, onSaved }) {
  const toast = useToast();
  const { currentAnnee } = useAnneeAcademique();
  const [form, setForm] = useState({ clinique: '' });
  const [saving, setSaving] = useState(false);
  const [loaded, setLoaded] = useState(false);

  useEffect(() => {
    if (!etudiant?.id || !currentAnnee?.id || loaded) return;
    stagesApi.load({ etudiant_id: etudiant.id, annee_academique_id: currentAnnee.id, type: 'garde_nuit' })
      .then(res => {
        if (res.data.data) setForm({ clinique: res.data.data.clinique || '' });
        setLoaded(true);
      })
      .catch(() => setLoaded(true));
  }, [etudiant?.id, currentAnnee?.id]);

  const setClinique = (value) => {
    const next = { clinique: value };
    setForm(next);
    onDataChange?.(next);
  };

  const handleSave = async () => {
    if (!etudiant?.id || !currentAnnee?.id) return;
    setSaving(true);
    try {
      await stagesApi.save({ etudiant_id: etudiant.id, annee_academique_id: currentAnnee.id, type: 'garde_nuit', data: form });
      showSuccess(toast, 'Garde de nuit enregistrée');
      onSaved?.();
    } catch (err) {
      handleApiError(err, toast);
    } finally {
      setSaving(false);
    }
  };

  return (
    <div className="card">
      <div className="card-header"><h5 className="mb-0">Garde de Nuit</h5></div>
      <div className="card-body">
        <div className="form-group">
          <label className="form-label">Clinique/Hôpital</label>
          <input className="form-input" value={form.clinique} onChange={e => setClinique(e.target.value)} />
        </div>
        <div style={{display:'flex', gap:8, marginTop:16}}>
          <button className="btn btn-primary" onClick={handleSave} disabled={saving}><FiSave style={{marginRight:4}}/>{saving ? 'Enregistrement...' : 'Enregistrer'}</button>
        </div>
      </div>
    </div>
  );
}
