import { useState, useEffect, useMemo } from 'react';
import { FiPrinter, FiSave } from 'react-icons/fi';
import { useAnneeAcademique } from '../../context/AnneeAcademiqueContext';
import { stagesApi } from '../../api/stages';
import { handleApiError, showSuccess } from '../../utils/errorHandler';
import { useToast } from '../../context/ToastContext';

const CRITERES_COMPORTEMENT = [
  'Assiduité et ponctualité',
  'Tenue et présentation',
  'Respect des règles',
  'Relation avec l\'équipe',
  'Relation avec les patients',
];

const CRITERES_APTITUDES = [
  'Capacité d\'observation',
  'Capacité d\'écoute',
  'Organisation du travail',
  'Application des règles d\'hygiène',
  'Maîtrise des gestes techniques',
  'Rigueur dans les soins',
  'Capacité d\'adaptation',
  'Esprit d\'initiative',
  'Communication professionnelle',
  'Respect des procédures',
];

export default function GrilleEvaluationStage({ etudiant, onDataChange }) {
  const toast = useToast();
  const { currentAnnee } = useAnneeAcademique();
  const [form, setForm] = useState({
    duree: '', lieu_stage: '',
    comportement: Array(CRITERES_COMPORTEMENT.length).fill(0),
    aptitudes: Array(CRITERES_APTITUDES.length).fill(0),
    eval_pratique_1: '', eval_pratique_2: '',
  });
  const [saving, setSaving] = useState(false);
  const [loaded, setLoaded] = useState(false);

  useEffect(() => {
    if (!etudiant?.id || !currentAnnee?.id || loaded) return;
    stagesApi.load({ etudiant_id: etudiant.id, annee_academique_id: currentAnnee.id, type: 'grille_evaluation' })
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

  const scoreComportement = useMemo(() => form.comportement.reduce((a, b) => a + Number(b), 0), [form.comportement]);
  const scoreAptitudes = useMemo(() => form.aptitudes.reduce((a, b) => a + Number(b), 0), [form.aptitudes]);
  const score1 = scoreComportement + scoreAptitudes;
  const eval1 = parseFloat(form.eval_pratique_1) || 0;
  const eval2 = parseFloat(form.eval_pratique_2) || 0;
  const score2 = eval1 + eval2;
  const noteGlobale = score2 > 0 ? ((score1 + score2) / 5).toFixed(2) : (score1 / 5).toFixed(2);

  const handleSave = async () => {
    if (!etudiant?.id || !currentAnnee?.id) return;
    setSaving(true);
    try {
      await stagesApi.save({
        etudiant_id: etudiant.id,
        annee_academique_id: currentAnnee.id,
        type: 'grille_evaluation',
        data: form,
      });
      showSuccess(toast, 'Grille d\'évaluation enregistrée');
    } catch (err) {
      handleApiError(err, toast);
    } finally {
      setSaving(false);
    }
  };

  const printDocument = () => {
    const w = window.open('', '_blank');
    w.document.write(`
      <html><head><title>Grille d'Évaluation du Stage</title>
      <style>
        @page { margin: 15mm; size: A4; }
        body { font-family: 'Arial', sans-serif; font-size: 12px; }
        h2 { text-align: center; margin-bottom: 4px; }
        table { width: 100%; border-collapse: collapse; margin: 8px 0; }
        th, td { border: 1px solid #000; padding: 3px 5px; text-align: left; font-size: 11px; }
        th { background: #f0f0f0; font-weight: 600; text-align: center; }
        .score-cell { text-align: center; }
        .total-row td { font-weight: 600; }
        .signature-section { margin-top: 20px; }
        .signature-line { border-top: 1px solid #000; margin-top: 30px; width: 250px; padding-top: 4px; font-size: 11px; }
        .note-globale { font-size: 16px; font-weight: 700; text-align: center; margin: 12px 0; }
        .print-btn { display: block; margin: 20px auto; padding: 10px 24px; font-size: 14px; }
        @media print { .print-btn { display: none; } }
      </style></head><body>
      <button class="print-btn" onclick="window.print()">Imprimer</button>
      <h2>Grille d'Évaluation du Stage</h2>
      <table>
        <tr><td><strong>Nom et Prénom</strong></td><td>${etudiant?.nom_prenom || ''}</td>
            <td><strong>N° Inscription</strong></td><td>${etudiant?.numero_inscription || ''}</td></tr>
        <tr><td><strong>Filière</strong></td><td>${etudiant?.filiere || ''}</td>
            <td><strong>Section</strong></td><td>${etudiant?.section || ''}</td></tr>
        <tr><td><strong>Année Scolaire</strong></td><td>${currentAnnee?.label || ''}</td>
            <td><strong>Année de Formation</strong></td><td>${etudiant?.niveau_numero || ''}</td></tr>
        <tr><td><strong>Durée</strong></td><td>${form.duree}</td>
            <td><strong>Lieu de Stage</strong></td><td>${form.lieu_stage}</td></tr>
      </table>

      <h4>Grille I — Comportement (Score /20)</h4>
      <table><thead><tr><th>Critères</th><th>Note (0-4)</th></tr></thead>
        <tbody>${CRITERES_COMPORTEMENT.map((c, i) =>
          `<tr><td>${c}</td><td class="score-cell">${form.comportement[i]}/4</td></tr>`
        ).join('')}
        <tr class="total-row"><td>Total</td><td class="score-cell">${scoreComportement}/20</td></tr>
      </tbody></table>

      <h4>Grille II — Aptitudes Professionnelles (Score /40)</h4>
      <table><thead><tr><th>Critères</th><th>Note (0-4)</th></tr></thead>
        <tbody>${CRITERES_APTITUDES.map((c, i) =>
          `<tr><td>${c}</td><td class="score-cell">${form.aptitudes[i]}/4</td></tr>`
        ).join('')}
        <tr class="total-row"><td>Total</td><td class="score-cell">${scoreAptitudes}/40</td></tr>
      </tbody></table>

      <p><strong>Score (1) = I + II = ${scoreComportement} + ${scoreAptitudes} = ${score1}/60</strong></p>

      <h4>Évaluation Pratique</h4>
      <table><thead><tr><th></th><th>Note /20</th></tr></thead>
        <tbody>
          <tr><td>1ère évaluation</td><td class="score-cell">${form.eval_pratique_1 || '—'}/20</td></tr>
          <tr><td>2ème évaluation</td><td class="score-cell">${form.eval_pratique_2 || '—'}/20</td></tr>
          <tr class="total-row"><td>Score (2)</td><td class="score-cell">${score2}/40</td></tr>
      </tbody></table>

      <div class="note-globale">Note Globale = (${score1} + ${score2}) / 5 = ${noteGlobale}/20</div>

      <p><strong>Décision :</strong> Stage valide ☐ / Stage invalide ☐</p>
      <p><strong>Observation du responsable :</strong></p>
      <div class="signature-section">
        <div class="signature-line">Signature et cachet</div>
      </div>
    `);
    w.document.close();
  };

  const NoteSelect = ({ value, onChange }) => (
    <select className="form-select" value={value} onChange={e => onChange(Number(e.target.value))}>
      {[0, 1, 2, 3, 4].map(n => <option key={n} value={n}>{n}</option>)}
    </select>
  );

  return (
    <div className="card">
      <div className="card-header"><h5 className="mb-0">Grille d'Évaluation du Stage</h5></div>
      <div className="card-body">
        <div className="data-grid">
          <div><label className="form-label">Durée</label><input className="form-input" value={form.duree} onChange={e => set('duree', e.target.value)} /></div>
          <div><label className="form-label">Lieu du Stage</label><input className="form-input" value={form.lieu_stage} onChange={e => set('lieu_stage', e.target.value)} /></div>
        </div>

        <h5 style={{margin:'16px 0 8px'}}>Grille I — Comportement (Score /20)</h5>
        <div className="table-wrap">
          <table className="table mb-0">
            <thead><tr><th>Critères</th><th style={{width:80}}>Note (0-4)</th></tr></thead>
            <tbody>
              {CRITERES_COMPORTEMENT.map((c, i) => (
                <tr key={i}><td>{c}</td><td><NoteSelect value={form.comportement[i]} onChange={v => { const n = [...form.comportement]; n[i] = v; set('comportement', n); }} /></td></tr>
              ))}
              <tr className="table-total"><td><strong>Total</strong></td><td><strong>{scoreComportement}/20</strong></td></tr>
            </tbody>
          </table>
        </div>

        <h5 style={{margin:'16px 0 8px'}}>Grille II — Aptitudes Professionnelles (Score /40)</h5>
        <div className="table-wrap">
          <table className="table mb-0">
            <thead><tr><th>Critères</th><th style={{width:80}}>Note (0-4)</th></tr></thead>
            <tbody>
              {CRITERES_APTITUDES.map((c, i) => (
                <tr key={i}><td>{c}</td><td><NoteSelect value={form.aptitudes[i]} onChange={v => { const n = [...form.aptitudes]; n[i] = v; set('aptitudes', n); }} /></td></tr>
              ))}
              <tr className="table-total"><td><strong>Total</strong></td><td><strong>{scoreAptitudes}/40</strong></td></tr>
            </tbody>
          </table>
        </div>

        <div style={{padding:'8px 12px', background:'#f3f4f6', borderRadius:6, marginTop:8}}>
          <strong>Score (1) = I + II = {scoreComportement} + {scoreAptitudes} = {score1}/60</strong>
        </div>

        <h5 style={{margin:'16px 0 8px'}}>Évaluation Pratique</h5>
        <div className="data-grid">
          <div><label className="form-label">1ère évaluation /20</label><input type="number" className="form-input" min="0" max="20" step="0.5" value={form.eval_pratique_1} onChange={e => set('eval_pratique_1', e.target.value)} /></div>
          <div><label className="form-label">2ème évaluation /20</label><input type="number" className="form-input" min="0" max="20" step="0.5" value={form.eval_pratique_2} onChange={e => set('eval_pratique_2', e.target.value)} /></div>
        </div>
        <div style={{marginTop:4}}><strong>Score (2) = {eval1} + {eval2} = {score2}/40</strong></div>

        <div style={{padding:'12px 16px', background:'#e0f2fe', borderRadius:6, marginTop:12, fontSize:16, fontWeight:700, textAlign:'center'}}>
          Note Globale = ({score1} + {score2}) / 5 = {noteGlobale}/20
        </div>

        <div style={{display:'flex', gap:8, marginTop:16}}>
          <button className="btn btn-primary" onClick={handleSave} disabled={saving}><FiSave style={{marginRight:4}}/>{saving ? 'Enregistrement...' : 'Enregistrer'}</button>
          <button className="btn btn-accent" onClick={printDocument}><FiPrinter style={{marginRight:4}}/>Imprimer / PDF</button>
        </div>
      </div>
    </div>
  );
}
