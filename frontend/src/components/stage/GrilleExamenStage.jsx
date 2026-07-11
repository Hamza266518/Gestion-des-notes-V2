import { useState, useEffect, useMemo } from 'react';
import { FiPrinter, FiSave } from 'react-icons/fi';
import { useAnneeAcademique } from '../../context/AnneeAcademiqueContext';
import { stagesApi } from '../../api/stages';
import { handleApiError, showSuccess } from '../../utils/errorHandler';
import { useToast } from '../../context/ToastContext';

const LIGNES_EXAMEN = [
  { element: 'Respect des règles d\'hygiène', bareme: 1 },
  { element: 'Préparation du matériel et du patient', bareme: 2 },
  { element: 'Qualité de la communication', bareme: 2 },
  { element: 'Exécution du soin / technique', bareme: 10 },
  { element: 'Capacité d\'analyse et d\'adaptation', bareme: 1 },
  { element: 'Respect des délais et organisation', bareme: 1 },
  { element: 'Aspects relationnels et éthiques', bareme: 3 },
];

const OBSERVATIONS_OPTIONS = ['', 'Très bien', 'Bien', 'Moyen', 'Insuffisant'];

export default function GrilleExamenStage({ etudiant, onDataChange }) {
  const toast = useToast();
  const { currentAnnee } = useAnneeAcademique();
  const [form, setForm] = useState({
    lieu_service: '', pratique_executee: '',
    lignes: LIGNES_EXAMEN.map(l => ({ note: '', observation: '' })),

  });
  const [saving, setSaving] = useState(false);
  const [loaded, setLoaded] = useState(false);

  useEffect(() => {
    if (!etudiant?.id || !currentAnnee?.id || loaded) return;
    stagesApi.load({ etudiant_id: etudiant.id, annee_academique_id: currentAnnee.id, type: 'grille_examen' })
      .then(res => {
        if (res.data.data) setForm(prev => ({ ...prev, ...res.data.data }));
        setLoaded(true);
      })
      .catch(() => setLoaded(true));
  }, [etudiant?.id, currentAnnee?.id]);

  const set = (key, value) => {
    const next = { ...form, [key]: value };
    setForm(next);
    onDataChange?.(next);
  };

  const updateLigne = (idx, key, value) => {
    const lignes = form.lignes.map((l, i) => i === idx ? { ...l, [key]: value } : l);
    set('lignes', lignes);
  };

  const totalNote = useMemo(() =>
    form.lignes.reduce((sum, l) => sum + (parseFloat(l.note) || 0), 0),
    [form.lignes]
  );

  const handleSave = async () => {
    if (!etudiant?.id || !currentAnnee?.id) return;
    setSaving(true);
    try {
      await stagesApi.save({
        etudiant_id: etudiant.id,
        annee_academique_id: currentAnnee.id,
        type: 'grille_examen',
        data: form,
      });
      showSuccess(toast, 'Grille d\'examen enregistrée');
    } catch (err) {
      handleApiError(err, toast);
    } finally {
      setSaving(false);
    }
  };

  const printDocument = () => {
    const examRows = LIGNES_EXAMEN.map((l, i) => {
      const n = form.lignes[i]?.note || '—';
      const obs = form.lignes[i]?.observation || '';
      return `<tr><td>${l.element}</td><td style="text-align:center">${l.bareme}</td><td style="text-align:center">${n}</td><td>${obs}</td></tr>`;
    }).join('');

    const w = window.open('', '_blank');
    w.document.write(`
      <html><head><title>Grille d'Examen du Stage</title>
      <style>
        @page { margin: 15mm; size: A4; }
        body { font-family: 'Arial', sans-serif; font-size: 12px; }
        h2 { text-align: center; margin-bottom: 4px; }
        table { width: 100%; border-collapse: collapse; margin: 8px 0; }
        th, td { border: 1px solid #000; padding: 3px 5px; text-align: left; font-size: 11px; }
        th { background: #f0f0f0; font-weight: 600; text-align: center; }
        .total-row td { font-weight: 700; }
        .examinators { display: flex; gap: 12px; margin-top: 20px; }
        .examinator { flex: 1; border: 1px solid #000; padding: 8px; }
        .cachets { display: flex; gap: 20px; margin-top: 16px; }
        .cachet-box { flex: 1; }
        .signature-line { border-top: 1px solid #000; margin-top: 24px; padding-top: 4px; font-size: 11px; }
        .print-btn { display: block; margin: 20px auto; padding: 10px 24px; font-size: 14px; }
        @media print { .print-btn { display: none; } }
      </style></head><body>
      <button class="print-btn" onclick="window.print()">Imprimer</button>
      <h2>Grille d'Examen du Stage</h2>
      <table>
        <tr><td><strong>Nom et Prénom</strong></td><td>${etudiant?.nom_prenom || ''}</td>
            <td><strong>N° Inscription</strong></td><td>${etudiant?.numero_inscription || ''}</td></tr>
        <tr><td><strong>Lieu et Service</strong></td><td>${form.lieu_service}</td>
            <td><strong>Pratique exécutée</strong></td><td>${form.pratique_executee}</td></tr>
      </table>
      <table>
        <tr><td><strong>Nom et Prénom</strong></td><td>${etudiant?.nom_prenom || ''}</td>
            <td><strong>Section</strong></td><td>${etudiant?.section || ''}</td></tr>
        <tr><td><strong>N° Inscription</strong></td><td>${etudiant?.numero_inscription || ''}</td>
            <td><strong>Filière</strong></td><td>${etudiant?.filiere || ''}</td></tr>
        <tr><td><strong>Années de Formation</strong></td><td>${etudiant?.niveau_numero || ''}</td>
            <td><strong>Année Scolaire</strong></td><td>${currentAnnee?.label || ''}</td></tr>
        <tr><td><strong>Lieu et Service</strong></td><td>${form.lieu_service}</td>
            <td><strong>Pratique exécutée</strong></td><td>${form.pratique_executee}</td></tr>
      </table>

      <table>
        <thead><tr><th>Élément à évaluer</th><th style="width:60px">Barème</th><th style="width:60px">Note</th><th>Observations</th></tr></thead>
        <tbody>${examRows}
        <tr class="total-row"><td colspan="2" style="text-align:right">Total</td><td style="text-align:center">${totalNote}/20</td><td></td></tr>
      </tbody></table>

      <div class="examinators">
        <div class="examinator"><p><strong>1er Examinateur</strong></p><p>Nom :</p><p>Note attribuée :</p><p>Signature :</p></div>
        <div class="examinator"><p><strong>2ème Examinateur</strong></p><p>Nom :</p><p>Note attribuée :</p><p>Signature :</p></div>
      </div>

      <div class="cachets">
        <div class="cachet-box"><div class="signature-line">Cachet de l'établissement</div></div>
        <div class="cachet-box"><div class="signature-line">Cachet du service</div></div>
      </div>
    `);
    w.document.close();
  };

  return (
    <div className="card">
      <div className="card-header"><h5 className="mb-0">Grille d'Examen du Stage</h5></div>
      <div className="card-body">
        <div className="data-grid">
          <div><label className="form-label">Lieu et Service</label><input className="form-input" value={form.lieu_service} onChange={e => set('lieu_service', e.target.value)} /></div>
          <div><label className="form-label">Pratique exécutée</label><input className="form-input" value={form.pratique_executee} onChange={e => set('pratique_executee', e.target.value)} /></div>
        </div>

        <h5 style={{margin:'16px 0 8px'}}>Grille d'Évaluation</h5>
        <div className="table-wrap">
          <table className="table mb-0">
            <thead><tr><th>Élément à évaluer</th><th style={{width:60}}>Barème</th><th style={{width:80}}>Note</th><th>Observations</th></tr></thead>
            <tbody>
              {LIGNES_EXAMEN.map((l, i) => (
                <tr key={i}>
                  <td>{l.element}</td>
                  <td style={{textAlign:'center'}}>{l.bareme}</td>
                  <td>
                    <input type="number" className="form-input form-input-sm" min="0" max={l.bareme} step="0.5"
                      value={form.lignes[i]?.note ?? ''}
                      onChange={e => updateLigne(i, 'note', Math.min(parseFloat(e.target.value) || 0, l.bareme).toString())}
                      style={{width:60}} />
                  </td>
                  <td>
                    <select className="form-select form-select-sm" value={form.lignes[i]?.observation ?? ''} onChange={e => updateLigne(i, 'observation', e.target.value)}>
                      {OBSERVATIONS_OPTIONS.map(o => <option key={o} value={o}>{o || '—'}</option>)}
                    </select>
                  </td>
                </tr>
              ))}
              <tr className="table-total">
                <td colSpan={2} style={{textAlign:'right'}}><strong>Total</strong></td>
                <td style={{textAlign:'center'}}><strong>{totalNote}/20</strong></td>
                <td></td>
              </tr>
            </tbody>
          </table>
        </div>

        <div style={{display:'flex', gap:8, marginTop:16}}>
          <button className="btn btn-primary" onClick={handleSave} disabled={saving}><FiSave style={{marginRight:4}}/>{saving ? 'Enregistrement...' : 'Enregistrer'}</button>
          <button className="btn btn-accent" onClick={printDocument}><FiPrinter style={{marginRight:4}}/>Imprimer / PDF</button>
        </div>
      </div>
    </div>
  );
}
