import { useState, useEffect, useMemo } from 'react';
import { FiSave } from 'react-icons/fi';
import { useAnneeAcademique } from '../../context/AnneeAcademiqueContext';
import { stagesApi } from '../../api/stages';
import { handleApiError, showSuccess } from '../../utils/errorHandler';
import { useToast } from '../../context/ToastContext';
import NoteStepper from './NoteStepper';
import FilterSelect from '../common/FilterSelect';

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

// One exam grid per service: the stored payload is a { service => grid } map,
// so each department keeps its own sheet — exactly like the evaluation grid.
const emptyForm = () => ({
  lieu: '', pratique_executee: '',
  lignes: LIGNES_EXAMEN.map(() => ({ note: '', observation: '' })),
});

// Older sheets stored a single grid (the scoring fields at the top level) with
// the service inside; normalize both shapes to the keyed map.
const normalizeMap = (raw) => {
  if (!raw || typeof raw !== 'object') return {};
  const isSingle = ['lignes', 'pratique_executee']
    .some(k => Object.prototype.hasOwnProperty.call(raw, k));
  if (isSingle) {
    const svc = typeof raw.service === 'string' ? raw.service.trim() : '';
    return svc ? { [svc]: raw } : {};
  }
  return raw;
};

// Older sheets stored the place and the department in a single field.
const migrateGrid = (grid) => {
  const out = { ...grid };
  if (out.lieu == null && out.lieu_service != null) out.lieu = out.lieu_service;
  delete out.lieu_service;
  return out;
};

export default function GrilleExamenStage({ etudiant, onDataChange, onSaved }) {
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
    stagesApi.load({ etudiant_id: etudiant.id, annee_academique_id: currentAnnee.id, type: 'grille_examen' })
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

  // "Services" are the Stage unit's own controls, named by the admin — the same
  // list the evaluation grid offers.
  useEffect(() => {
    if (!etudiant?.id) { setServices([]); return; }
    stagesApi.stageServices(etudiant.id)
      .then(res => setServices(res.data.data || []))
      .catch(() => setServices([]));
  }, [etudiant?.id]);

  // Show the selected service's saved sheet.
  useEffect(() => {
    if (!loaded) return;
    setForm({ ...emptyForm(), ...migrateGrid(all[service] ?? {}) });
  }, [service, all, loaded]);

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

  const updateLigne = (idx, key, value) => {
    const lignes = form.lignes.map((l, i) => i === idx ? { ...l, [key]: value } : l);
    set('lignes', lignes);
  };

  const totalNote = useMemo(() =>
    form.lignes.reduce((sum, l) => sum + (parseFloat(l.note) || 0), 0),
    [form.lignes]
  );

  const handleSave = async () => {
    if (!etudiant?.id || !currentAnnee?.id || !service) return;
    setSaving(true);
    try {
      const map = { ...all, [service]: form };
      await stagesApi.save({
        etudiant_id: etudiant.id,
        annee_academique_id: currentAnnee.id,
        type: 'grille_examen',
        data: map,
      });
      setAll(map);
      showSuccess(toast, `Grille d'examen enregistrée — ${service}`);
      onSaved?.();
    } catch (err) {
      handleApiError(err, toast);
    } finally {
      setSaving(false);
    }
  };

  return (
    <div className="card">
      <div className="card-header"><h5 className="mb-0">Grille d'Examen du Stage</h5></div>
      <div className="card-body">
        <div className="data-grid">
          <div><label className="form-label">Lieu</label><input className="form-input" value={form.lieu} onChange={e => set('lieu', e.target.value)} /></div>
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
                    <NoteStepper
                      value={form.lignes[i]?.note ?? ''}
                      onChange={v => updateLigne(i, 'note', v)}
                      min={0} max={l.bareme} step={l.bareme <= 3 ? 0.1 : 0.5} />
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
          <button className="btn btn-primary" onClick={handleSave} disabled={saving || !service}><FiSave style={{marginRight:4}}/>{saving ? 'Enregistrement...' : 'Enregistrer'}</button>
        </div>
      </div>
    </div>
  );
}