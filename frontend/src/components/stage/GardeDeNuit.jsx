import { useState, useEffect } from 'react';
import { FiPrinter, FiSave } from 'react-icons/fi';
import { useAnneeAcademique } from '../../context/AnneeAcademiqueContext';
import { stagesApi } from '../../api/stages';
import { handleApiError, showSuccess } from '../../utils/errorHandler';
import { useToast } from '../../context/ToastContext';

export default function GardeDeNuit({ etudiant, onDataChange }) {
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
    } catch (err) {
      handleApiError(err, toast);
    } finally {
      setSaving(false);
    }
  };

  const printDocument = () => {
    const w = window.open('', '_blank');
    w.document.write(`
      <html><head><title>Garde de Nuit</title>
      <style>
        @page { margin: 15mm; size: A4; }
        body { font-family: 'Arial', sans-serif; font-size: 12px; color: #000; }
        h2 { text-align: center; margin-bottom: 4px; }
        .header-info { margin-bottom: 12px; }
        .header-info td { padding: 2px 8px; vertical-align: top; }
        .header-info td:first-child { font-weight: 600; width: 140px; }
        table { width: 100%; border-collapse: collapse; margin: 8px 0; }
        th, td { border: 1px solid #000; padding: 4px 6px; text-align: left; font-size: 11px; }
        th { background: #f0f0f0; font-weight: 600; }
        .signature-section { display: flex; justify-content: space-between; margin-top: 20px; gap: 20px; }
        .signature-box { flex: 1; }
        .signature-box p { margin: 2px 0; }
        .signature-line { border-top: 1px solid #000; margin-top: 20px; padding-top: 4px; font-size: 11px; }
        .observation { margin-top: 12px; padding: 8px; border: 1px solid #000; min-height: 40px; }
        .label { font-weight: 600; }
        .print-btn { display: block; margin: 20px auto; padding: 10px 24px; font-size: 14px; }
        @media print { .print-btn { display: none; } }
      </style></head><body>
      <button class="print-btn" onclick="window.print()">Imprimer</button>
      <h2>Garde de Nuit</h2>
      <table class="header-info">
        <tr><td>Nom et Prénom</td><td><strong>${etudiant?.nom_prenom || ''}</strong></td></tr>
        <tr><td>Clinique/Hôpital</td><td>${form.clinique}</td></tr>
        <tr><td>Service</td><td></td></tr>
        <tr><td>Date</td><td></td></tr>
      </table>
      <p><span class="label">Objectifs prévus :</span></p>
      <table>
        <thead><tr><th>Objectifs Réalisés</th><th>Heures</th><th>Participation</th></tr></thead>
        <tbody><tr><td></td><td></td><td></td></tr></tbody>
      </table>
      <div class="signature-section">
        <div class="signature-box">
          <p class="label">Surveillant Général</p>
          <p>Nom :</p>
          <p>Signature :</p>
        </div>
        <div class="signature-box">
          <p class="label">Infirmier du Garde</p>
          <div class="observation"></div>
        </div>
        <div class="signature-box">
          <p class="label">Stagiaire</p>
          <div class="signature-line"></div>
        </div>
      </div>
    `);
    w.document.close();
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
          <button className="btn btn-accent" onClick={printDocument}><FiPrinter style={{marginRight:4}}/>Imprimer / PDF</button>
        </div>
      </div>
    </div>
  );
}
