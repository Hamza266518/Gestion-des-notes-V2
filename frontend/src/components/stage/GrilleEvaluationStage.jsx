import { useState, useEffect, useMemo } from 'react';
import { FiSave } from 'react-icons/fi';
import { useAnneeAcademique } from '../../context/AnneeAcademiqueContext';
import { stagesApi } from '../../api/stages';
import { handleApiError, showSuccess } from '../../utils/errorHandler';
import { useToast } from '../../context/ToastContext';
import NoteStepper from './NoteStepper';
import FilterSelect from '../common/FilterSelect';

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

// One evaluation grid per service: the stored payload is a { service => grid }
// map, so each department keeps its own sheet.
const emptyForm = () => ({
  duree: '', lieu_stage: '', pratique_executee: '',
  comportement: Array(CRITERES_COMPORTEMENT.length).fill(0),
  aptitudes: Array(CRITERES_APTITUDES.length).fill(0),
  eval_pratique_1: '', eval_pratique_2: '',
});

// Older sheets stored a single grid (the scoring fields at the top level) with
// the service inside; normalize both shapes to the keyed map.
const normalizeMap = (raw) => {
  if (!raw || typeof raw !== 'object') return {};
  const isSingle = ['comportement', 'aptitudes', 'eval_pratique_1', 'eval_pratique_2', 'lignes', 'pratique_executee']
    .some(k => Object.prototype.hasOwnProperty.call(raw, k));
  if (isSingle) {
    const svc = typeof raw.service === 'string' ? raw.service.trim() : '';
    return svc ? { [svc]: raw } : {};
  }
  return raw;
};

export default function GrilleEvaluationStage({ etudiant, onDataChange, onSaved }) {
  const toast = useToast();
  const { currentAnnee } = useAnneeAcademique();

  const [all, setAll] = useState({});
  const [service, setService] = useState('');
  const [form, setForm] = useState(emptyForm);
  const [services, setServices] = useState([]);
  const [saving, setSaving] = useState(false);
  const [loaded, setLoaded] = useState(false);

  useEffect(() => {
    if (!etudiant?.id || !currentAnnee?.id) return;
    let cancelled = false;
    setLoaded(false);
    stagesApi.load({ etudiant_id: etudiant.id, annee_academique_id: currentAnnee.id, type: 'grille_evaluation' })
      .then(res => {
        if (cancelled) return;
        const map = normalizeMap(res.data.data);
        setAll(map);
        setService(cur => (map[cur] ? cur : (Object.keys(map)[0] ?? '')));
      })
      .catch(() => { if (!cancelled) setAll({}); })
      .finally(() => { if (!cancelled) setLoaded(true); });
    return () => { cancelled = true; };
  }, [etudiant?.id, currentAnnee?.id]);

  // "Services" are the Stage unit's own controls, named by the admin.
  useEffect(() => {
    if (!etudiant?.id) { setServices([]); return; }
    stagesApi.stageServices(etudiant.id)
      .then(res => setServices(res.data.data || []))
      .catch(() => setServices([]));
  }, [etudiant?.id]);

  // Show the selected service's saved sheet.
  useEffect(() => {
    if (!loaded) return;
    setForm({ ...emptyForm(), ...(all[service] ?? {}) });
  }, [service, all, loaded]);

  // Options for the "Service" combobox, mirroring the Filière select. A value
  // saved before the list changed is kept so nothing is silently dropped.
  const serviceOptions = useMemo(() => {
    const opts = services.map(s => ({ id: s.nom, label: s.nom }));
    if (service && !opts.some(o => o.id === service)) {
      opts.push({ id: service, label: service });
    }
    return opts;
  }, [services, service]);

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
    if (!etudiant?.id || !currentAnnee?.id || !service) return;
    setSaving(true);
    try {
      const map = { ...all, [service]: form };
      await stagesApi.save({
        etudiant_id: etudiant.id,
        annee_academique_id: currentAnnee.id,
        type: 'grille_evaluation',
        data: map,
      });
      setAll(map);
      showSuccess(toast, `Grille d'évaluation enregistrée — ${service}`);
      onSaved?.();
    } catch (err) {
      handleApiError(err, toast);
    } finally {
      setSaving(false);
    }
  };

  const NoteScale = ({ value, onChange }) => (
    <NoteStepper
      value={value}
      onChange={v => onChange(Number(v))}
      min={0}
      max={4}
      step={1}
      incStep={1}
    />
  );

  return (
    <div className="card">
      <div className="card-header"><h5 className="mb-0">Grille d'Évaluation du Stage</h5></div>
      <div className="card-body">
        <div className="data-grid">
          <div><label className="form-label">Durée</label><input className="form-input" value={form.duree} onChange={e => set('duree', e.target.value)} /></div>
          <div><label className="form-label">Lieu du Stage</label><input className="form-input" value={form.lieu_stage} onChange={e => set('lieu_stage', e.target.value)} /></div>
          <div>
            <label className="form-label">Service</label>
            <FilterSelect
              value={service}
              options={serviceOptions}
              onChange={setService}
              placeholder="Sélectionner un service"
              allLabel="—"
              block
            />
          </div>
          <div><label className="form-label">Pratique exécutée</label><input className="form-input" value={form.pratique_executee} onChange={e => set('pratique_executee', e.target.value)} /></div>
        </div>

        <h5 style={{margin:'16px 0 8px'}}>Grille I — Comportement (Score /20)</h5>
        <div className="table-wrap">
          <table className="table mb-0">
            <thead><tr><th>Critères</th><th style={{width:80}}>Note (0-4)</th></tr></thead>
            <tbody>
              {CRITERES_COMPORTEMENT.map((c, i) => (
                <tr key={i}><td>{c}</td><td><NoteScale value={form.comportement[i]} onChange={v => { const n = [...form.comportement]; n[i] = v; set('comportement', n); }} /></td></tr>
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
                <tr key={i}><td>{c}</td><td><NoteScale value={form.aptitudes[i]} onChange={v => { const n = [...form.aptitudes]; n[i] = v; set('aptitudes', n); }} /></td></tr>
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
          <button className="btn btn-primary" onClick={handleSave} disabled={saving || !service}><FiSave style={{marginRight:4}}/>{saving ? 'Enregistrement...' : 'Enregistrer'}</button>
        </div>
      </div>
    </div>
  );
}