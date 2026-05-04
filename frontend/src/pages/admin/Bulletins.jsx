import { useEffect, useState, useCallback, useRef } from 'react';
import { notesApi } from '../../api/notes';
import { adminApi } from '../../api/admin';
import { unitesApi } from '../../api/unites';
import { etudiantsApi } from '../../api/etudiants';
import { publicationsApi } from '../../api/publications';
import { useToast } from '../../context/ToastContext';
import { useAnneeAcademique } from '../../context/AnneeAcademiqueContext';
import { handleApiError } from '../../utils/errorHandler';
import { getMention, formatNiveau } from '../../utils/helpers';
import Badge from '../../components/common/Badge';
import Spinner from '../../components/common/Spinner';
import '../../css/components.css';
import '../../css/layout.css';
import '../../css/pages.css';

const thStyle = {
  padding: '8px 12px',
  textAlign: 'left',
  fontWeight: 600,
  borderBottom: '2px solid #e5e7eb',
  fontSize: 12,
};

const tdStyle = {
  padding: '6px 12px',
  borderBottom: '1px solid #e5e7eb',
};

export default function Bulletins() {
  const [filieres, setFilieres] = useState([]);
  const [niveaux, setNiveaux] = useState([]);
  const [groupes, setGroupes] = useState([]);
  const [etudiants, setEtudiants] = useState([]);
  const [unites, setUnites] = useState([]);

  const [filiereId, setFiliereId] = useState('');
  const [niveauId, setNiveauId] = useState('');
  const [groupeId, setGroupeId] = useState('');
  const [etudiantId, setEtudiantId] = useState('');

  const [bulletin, setBulletin] = useState(null);
  const [loading, setLoading] = useState(false);

  const printRef = useRef(null);
  const toast = useToast();
  const { currentAnnee } = useAnneeAcademique();

  useEffect(() => {
    adminApi.getFilieres()
      .then(res => setFilieres(res.data.data || []))
      .catch(() => setFilieres([]));
  }, []);

  useEffect(() => {
    if (filiereId) {
      adminApi.getNiveaux(filiereId)
        .then(res => {
          setNiveaux(res.data.data || []);
          setNiveauId('');
          setGroupeId('');
        })
        .catch(() => setNiveaux([]));
    } else {
      setNiveaux([]);
      setNiveauId('');
      setGroupeId('');
    }
  }, [filiereId]);

  useEffect(() => {
    if (niveauId && currentAnnee) {
      adminApi.getGroupes({ niveau_id: niveauId, annee_academique_id: currentAnnee.id })
        .then(res => {
          setGroupes(res.data.data || []);
          setGroupeId('');
        })
        .catch(() => setGroupes([]));
    } else {
      setGroupes([]);
      setGroupeId('');
    }
  }, [niveauId, currentAnnee]);

  useEffect(() => {
    if (!filiereId || !currentAnnee) {
      setUnites([]);
      return;
    }
    unitesApi.getUnites({ filiere_id: filiereId })
      .then(res => setUnites(res.data.data || []))
      .catch(() => setUnites([]));
  }, [filiereId, currentAnnee]);

  useEffect(() => {
    if (!groupeId) {
      setEtudiants([]);
      setEtudiantId('');
      return;
    }
    etudiantsApi.getEtudiants({ groupe_id: groupeId })
      .then(res => {
        setEtudiants(res.data.data || []);
        setEtudiantId('');
      })
      .catch(() => setEtudiants([]));
  }, [groupeId]);

  const loadBulletin = useCallback(async () => {
    if (!etudiantId || !groupeId || !currentAnnee) return;

    setLoading(true);
    try {
      const [bulletinRes] = await Promise.all([
        notesApi.getBulletin({ etudiant_id: etudiantId, groupe_id: groupeId, annee_academique_id: currentAnnee.id }),
      ]);
      setBulletin(bulletinRes.data.data);
    } catch (error) {
      handleApiError(error, toast);
    } finally {
      setLoading(false);
    }
  }, [etudiantId, groupeId, currentAnnee]);

  useEffect(() => {
    loadBulletin();
  }, [loadBulletin]);

  const handlePrint = () => {
    window.print();
  };

  const handleResetFilters = () => {
    setNiveauId('');
    setGroupeId('');
    setEtudiantId('');
  };

  const handleResetGroupeFilters = () => {
    setEtudiantId('');
  };

  return (
    <div className="page-container">
      <div className="page-header">
        <h1>Bulletins</h1>
        {currentAnnee && (
          <span className="form-select" style={{display:'inline-block', padding:'8px 12px', background:'#e8f5e9', color:'#2e7d32', fontWeight:'bold'}}>
            {currentAnnee.label}
          </span>
        )}
      </div>

      <div className="filter-bar">
        <select className="form-select" value={filiereId} onChange={e => { setFiliereId(e.target.value); handleResetFilters(); }}>
          <option value="">Filiere</option>
          {filieres.map(f => <option key={f.id} value={f.id}>{f.nom}</option>)}
        </select>
        <select className="form-select" value={niveauId} onChange={e => { setNiveauId(e.target.value); setGroupeId(''); handleResetGroupeFilters(); }} disabled={!filiereId}>
          <option value="">Niveau</option>
          {niveaux.map(n => <option key={n.id} value={n.id}>{formatNiveau(n.numero)}</option>)}
        </select>
        <select className="form-select" value={groupeId} onChange={e => { setGroupeId(e.target.value); handleResetGroupeFilters(); }} disabled={!niveauId}>
          <option value="">Groupe</option>
          {groupes.map(g => <option key={g.id} value={g.id}>{g.nom}</option>)}
        </select>
        <select className="form-select" value={etudiantId} onChange={e => setEtudiantId(e.target.value)} disabled={!groupeId}>
          <option value="">Etudiant</option>
          {etudiants.map(e => <option key={e.id} value={e.id}>{e.nom || e.nom_prenom}</option>)}
        </select>
      </div>

      {bulletin && bulletin.student && (
        <div ref={printRef}>
          <div className="card bulletin-card">
            <div className="card-body bulletin-body">
              <div className="bulletin-header">
                <h2>IFP</h2>
                <p>Institut de Formation Paramedicale</p>
                <h3>Bulletin de Notes</h3>
                <p>Annee Academique {currentAnnee?.label || ''}</p>
              </div>

              <div className="bulletin-info">
                <div><strong>Etudiant:</strong> {bulletin.student.nom || bulletin.student.nom_prenom}</div>
                <div><strong>CIN:</strong> {bulletin.student.cin || '—'}</div>
                <div><strong>N° Inscription:</strong> {bulletin.student.numero_inscription || '—'}</div>
                <div><strong>Filiere:</strong> {filieres.find(f => f.id == filiereId)?.nom || '—'}</div>
              </div>

              <div className="table-wrap">
                <table>
              <thead>
                <tr>
                  <th style={thStyle}>Unité</th>
                  {(() => {
                    const allSequences = [];
                    (bulletin?.semesters?.[1]?.unites || []).forEach(u => {
                      (u.sequences || []).forEach((_, i) => {
                        if (!allSequences.includes(i)) allSequences.push(i);
                      });
                    });
                    return allSequences.map(i => (
                      <th key={i} style={thStyle}>C{i + 1}</th>
                    ));
                  })()}
                  <th style={thStyle}>Moyenne</th>
                  <th style={thStyle}>Moyenne</th>
                  <th style={thStyle}>Coef</th>
                </tr>
              </thead>
                  <tbody>
                    {bulletin.semesters[1]?.unites.map(unite => (
                      <UniteRow key={unite.id} unite={unite} />
                    ))}
                  </tbody>
                </table>
              </div>

              <div className="bulletin-summary">
                <SummaryCalculations bulletin={bulletin} />
              </div>

              <div className="bulletin-print-bar">
                <button className="btn btn-primary" onClick={handlePrint}>
                  Imprimer le bulletin
                </button>
              </div>
            </div>
          </div>
        </div>
      )}
    </div>
  );
}

function UniteRow({ unite }) {
  const maxControles = Math.max(...unite.sequences.map(s => s.controles.length), 3);

  return (
    <>
      <tr className="bulletin-unite-row">
        <td style={{ ...tdStyle, fontWeight: 700 }}>{unite.nom}</td>
        {Array.from({ length: maxControles }).map((_, i) => (
          <td key={`u-e-${i}`} style={tdStyle}></td>
        ))}
        <td style={{ ...tdStyle, fontWeight: 700 }}>
          {unite.moyenneUnite !== null ? unite.moyenneUnite.toFixed(2) : '—'}
        </td>
        <td style={{ ...tdStyle, fontWeight: 700 }}>
          {unite.moyenneUnite !== null ? unite.moyenneUnite.toFixed(2) : '—'}
        </td>
        <td style={{ ...tdStyle, fontWeight: 700 }}>{unite.coefficient}</td>
      </tr>
      {unite.sequences.map((seq) => {
        const cells = [];
        for (let i = 0; i < maxControles; i++) {
          const ctrl = seq.controles.find(c => c.numero === i + 1);
          cells.push(
            <td key={i} style={tdStyle}>
              {ctrl?.valeur !== null && ctrl?.valeur !== undefined ? ctrl.valeur.toFixed(2) : '—'}
            </td>
          );
        }
        return (
          <tr key={seq.id}>
            <td className="bulletin-seq-name" style={tdStyle}>
              <span className="bulletin-seq-arrow">→</span>{seq.nom}
            </td>
            {cells}
            <td style={{ ...tdStyle, fontWeight: 600 }}>
              {seq.moyenneSeq !== null ? seq.moyenneSeq.toFixed(2) : '—'}
            </td>
            <td style={tdStyle}></td>
            <td style={tdStyle}>{seq.coefficient}</td>
          </tr>
        );
      })}
    </>
  );
}

function SummaryCalculations({ bulletin }) {
  const allUnites = [...(bulletin.semesters?.[1]?.unites || []), ...(bulletin.semesters?.[2]?.unites || [])];
  const validUnites = allUnites.filter(u => u.moyenneUnite !== null);
  const totalCoef = validUnites.reduce((s, u) => s + u.coefficient, 0);

  const mpcc = totalCoef > 0
    ? validUnites.reduce((s, u) => s + u.moyenneUnite * u.coefficient, 0) / totalCoef
    : null;

  const examTheo = bulletin.examNotes?.filter(e => e.type === 'theorique' && e.valeur !== null) || [];
  const examPra = bulletin.examNotes?.filter(e => e.type === 'pratique' && e.valeur !== null) || [];

  const mpefcft = examTheo.length > 0
    ? examTheo.reduce((s, e) => s + e.valeur * (e.unite_coef || 1), 0) / examTheo.reduce((s, e) => s + (e.unite_coef || 1), 0)
    : null;

  const mpefcfp = examPra.length > 0
    ? examPra.reduce((s, e) => s + e.valeur * (e.unite_coef || 1), 0) / examPra.reduce((s, e) => s + (e.unite_coef || 1), 0)
    : null;

  const stageNote = bulletin.stageNote;

  const weights = { mpcc: 1, theo: 0.3, pra: 0.2, stage: 0.4 };
  let moyenneFinale = null;
  let totalWeight = 0;
  if (mpcc !== null) { moyenneFinale = mpcc * 1; totalWeight += 1; }
  if (mpefcft !== null) { moyenneFinale = (moyenneFinale || 0) + mpefcft * weights.theo; totalWeight += weights.theo; }
  if (mpefcfp !== null) { moyenneFinale = (moyenneFinale || 0) + mpefcfp * weights.pra; totalWeight += weights.pra; }
  if (stageNote !== null) { moyenneFinale = (moyenneFinale || 0) + stageNote * weights.stage; totalWeight += weights.stage; }
  if (totalWeight > 0) moyenneFinale = moyenneFinale / totalWeight;

  const { label: mentionLabel, color: mentionColorVal } = getMention(moyenneFinale);

  return (
    <div className="summary-grid">
      <div className="summary-item">
        <span className="summary-label">Moyenne annuelle:</span>
        <span className="summary-value">{moyenneFinale?.toFixed(2) || '—'}</span>
      </div>
      <div className="summary-item">
        <span className="summary-label">Mention:</span>
        <Badge label={mentionLabel} color={mentionColorVal} />
      </div>
    </div>
  );
}

