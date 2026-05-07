import { useEffect, useState, useCallback, useRef } from 'react';
import { notesApi } from '../../api/notes';
import { adminApi } from '../../api/admin';
import { unitesApi } from '../../api/unites';
import { etudiantsApi } from '../../api/etudiants';
import { useToast } from '../../context/ToastContext';
import { useAnneeAcademique } from '../../context/AnneeAcademiqueContext';
import { handleApiError } from '../../utils/errorHandler';
import { formatNiveau } from '../../utils/helpers';
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
  whiteSpace: 'nowrap',
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
    if (!niveauId) {
      setGroupes([]);
      setGroupeId('');
      return;
    }
    const params = { niveau_id: niveauId };
    if (currentAnnee) {
      params.annee_academique_id = currentAnnee.id;
    }
    adminApi.getGroupes(params)
      .then(res => {
        setGroupes(res.data.data || []);
        setGroupeId('');
      })
      .catch(() => setGroupes([]));
  }, [niveauId]);

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
      const res = await notesApi.getBulletin({
        etudiant_id: etudiantId,
        groupe_id: groupeId,
        annee_academique_id: currentAnnee.id,
      });
      setBulletin(res.data.data);
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

      {loading && (
        <div className="text-center mt-5">
          <Spinner />
          <p className="mt-2 text-muted">Chargement du bulletin...</p>
        </div>
      )}

      {bulletin && bulletin.student && (
        <div ref={printRef}>
          <div className="card bulletin-card">
            <div className="card-body bulletin-body">
              <div className="bulletin-header">
                <h2>IFP</h2>
                <p>Institut de Formation Paramedicale</p>
                <h3>Bulletin de Notes</h3>
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
                      <th style={{...thStyle, background: '#e0f2fe'}}>CC (36.66%)</th>
                      <th style={{...thStyle, background: '#fef3c7'}}>Theorique (26.66%)</th>
                      <th style={{...thStyle, background: '#dcfce7'}}>Pratique (36.66%)</th>
                      <th style={thStyle}>Moy. Unite</th>
                      <th style={thStyle}>Coef</th>
                    </tr>
                  </thead>
                  <tbody>
                    {(() => {
                      const allUnites = [
                        ...(bulletin.semesters?.[1]?.unites || []),
                        ...(bulletin.semesters?.[2]?.unites || []),
                      ];
                      return allUnites.map(unite => (
                        <tr key={unite.id} className="bulletin-unite-row">
                          <td style={{...tdStyle, fontWeight: 700}}>{unite.nom}</td>
                          <td style={{...tdStyle, background: '#f0f9ff'}}>
                            {unite.moyenne_cc !== null ? unite.moyenne_cc.toFixed(2) : '—'}
                          </td>
                          <td style={{...tdStyle, background: '#fffbeb'}}>
                            {unite.moyenne_theorique !== null ? unite.moyenne_theorique.toFixed(2) : '—'}
                          </td>
                          <td style={{...tdStyle, background: '#f0fdf4'}}>
                            {unite.moyenne_pratique !== null ? unite.moyenne_pratique.toFixed(2) : '—'}
                          </td>
                          <td style={{...tdStyle, fontWeight: 700}}>
                            {unite.moyenneUnite !== null ? unite.moyenneUnite.toFixed(2) : '—'}
                          </td>
                          <td style={{...tdStyle, fontWeight: 700}}>{unite.coefficient}</td>
                        </tr>
                      ));
                    })()}
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

function SummaryCalculations({ bulletin }) {
  const { label: mentionLabel, color: mentionColorVal } = bulletin.mention || { label: '—', color: 'gray' };

  return (
    <div className="summary-grid">
      <div className="summary-item">
        <span className="summary-label">Moyenne CC:</span>
        <span className="summary-value">{bulletin.moyenne_cc?.toFixed(2) || '—'}</span>
      </div>
      <div className="summary-item">
        <span className="summary-label">Moyenne Theorique:</span>
        <span className="summary-value">{bulletin.moyenne_theorique?.toFixed(2) || '—'}</span>
      </div>
      <div className="summary-item">
        <span className="summary-label">Moyenne Pratique:</span>
        <span className="summary-value">{bulletin.moyenne_pratique?.toFixed(2) || '—'}</span>
      </div>
      <div className="summary-item" style={{borderTop: '2px solid var(--primary)', paddingTop: 8}}>
        <span className="summary-label" style={{fontWeight: 700}}>Moyenne Generale:</span>
        <span className="summary-value" style={{fontWeight: 700, fontSize: 18}}>{bulletin.moyenne_generale?.toFixed(2) || '—'}</span>
      </div>
      <div className="summary-item">
        <span className="summary-label">Mention:</span>
        <Badge label={mentionLabel} color={mentionColorVal} />
      </div>
      <div className="summary-item">
        <span className="summary-label">Decision du Jury:</span>
        <Badge
          label={bulletin.decision || '—'}
          color={bulletin.decision === 'Admis(e)' ? 'green' : 'red'}
        />
      </div>
    </div>
  );
}
