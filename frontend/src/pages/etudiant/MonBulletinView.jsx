import { useState, useEffect, useCallback } from 'react';
import portalApi from '../../api/portal';
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
};

const tdStyle = {
  padding: '6px 12px',
  borderBottom: '1px solid #e5e7eb',
};

export default function MonBulletinView() {
  const [bulletin, setBulletin] = useState(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);
  const toast = useToast();
  const { currentAnnee } = useAnneeAcademique();

  const loadBulletin = useCallback(async () => {
    setLoading(true);
    setError(null);
    try {
      const res = await portalApi.getBulletin(currentAnnee?.id);
      setBulletin(res.data.data);
    } catch (err) {
      const errorInfo = handleApiError(err, toast, { showToast: false });
      setError(errorInfo.message);
    } finally {
      setLoading(false);
    }
  }, [currentAnnee, toast]);

  useEffect(() => {
    loadBulletin();
  }, [loadBulletin]);

  if (loading) return <Spinner />;

  if (error) {
    return (
      <div className="page-container">
        <div className="alert alert-danger">
          <p>{error}</p>
          <button className="btn btn-outline mt-2" onClick={loadBulletin}>Reessayer</button>
        </div>
      </div>
    );
  }

  if (!bulletin || !bulletin.publications?.bulletin || !bulletin.bulletin) {
    return (
      <div className="page-container">
        <div className="page-header">
          <h1>Mon Bulletin</h1>
          {currentAnnee && <span className="form-select" style={{display:'inline-block', padding:'8px 12px', background:'#e8f5e9', color:'#2e7d32', fontWeight:'bold'}}>{currentAnnee.label}</span>}
        </div>
        <div className="empty-state">
          <p>Bulletin non publie pour le moment.</p>
        </div>
      </div>
    );
  }

  const { etudiant, bulletin: bulletinData } = bulletin;
  const handlePrint = () => {
    window.print();
  };

  return (
    <div className="page-container">
      <div className="page-header">
        <h1>Mon Bulletin</h1>
        {currentAnnee && <span className="form-select" style={{display:'inline-block', padding:'8px 12px', background:'#e8f5e9', color:'#2e7d32', fontWeight:'bold'}}>{currentAnnee.label}</span>}
      </div>

      <div className="card bulletin-card">
        <div className="card-body bulletin-body">
          <div className="bulletin-header">
            <h2>IFP</h2>
            <p>Institut de Formation Paramedicale</p>
            <h3>Bulletin de Notes</h3>
            <p>Annee Academique {currentAnnee?.label || ''}</p>
          </div>

          <div className="bulletin-info">
            <div><strong>Etudiant:</strong> {etudiant.nom_prenom}</div>
            <div><strong>CIN:</strong> {etudiant.cin || '—'}</div>
            <div><strong>N° Inscription:</strong> {etudiant.numero_inscription || '—'}</div>
            <div><strong>Filiere:</strong> {etudiant.groupe?.niveau?.filiere?.nom || '—'}</div>
            <div><strong>Niveau:</strong> {formatNiveau(etudiant.groupe?.niveau?.numero)}</div>
            <div><strong>Groupe:</strong> {etudiant.groupe?.nom || '—'}</div>
          </div>

          {[1, 2].map((sem) => {
            const unites = bulletinData.semestres?.[sem] || [];
            if (unites.length === 0) return null;
            return (
              <div key={sem}>
                <h3 className="mb-2 mt-3">Semestre {sem}</h3>
                <div className="table-wrap">
                  <table>
                    <thead>
                      <tr>
                        <th style={thStyle}>Unite</th>
                        <th style={{...thStyle, background: '#e0f2fe'}}>CC (36.66%)</th>
                        <th style={{...thStyle, background: '#fef3c7'}}>Theorique (26.66%)</th>
                        <th style={{...thStyle, background: '#dcfce7'}}>Pratique (36.66%)</th>
                        <th style={thStyle}>Moy. Unite</th>
                        <th style={thStyle}>Coef</th>
                      </tr>
                    </thead>
                    <tbody>
                      {unites.map(unite => (
                        <tr key={unite.nom}>
                          <td style={{...tdStyle, fontWeight: 700}}>{unite.nom}</td>
                          <td style={{...tdStyle, background: '#f0f9ff'}}>
                            {unite.moyenne_cc !== null && unite.moyenne_cc !== undefined ? unite.moyenne_cc.toFixed(2) : '—'}
                          </td>
                          <td style={{...tdStyle, background: '#fffbeb'}}>
                            {unite.moyenne_theorique !== null && unite.moyenne_theorique !== undefined ? unite.moyenne_theorique.toFixed(2) : '—'}
                          </td>
                          <td style={{...tdStyle, background: '#f0fdf4'}}>
                            {unite.moyenne_pratique !== null && unite.moyenne_pratique !== undefined ? unite.moyenne_pratique.toFixed(2) : '—'}
                          </td>
                          <td style={{...tdStyle, fontWeight: 700}}>
                            {unite.moyenne !== null && unite.moyenne !== undefined ? unite.moyenne.toFixed(2) : '—'}
                          </td>
                          <td style={{...tdStyle, fontWeight: 700}}>{unite.coefficient}</td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>
              </div>
            );
          })}

          <div className="bulletin-summary">
            <SummaryCalculations bulletinData={bulletinData} />
          </div>

          <div className="bulletin-print-bar">
            <button className="btn btn-primary" onClick={handlePrint}>
              Imprimer le bulletin
            </button>
          </div>
        </div>
      </div>
    </div>
  );
}

function SummaryCalculations({ bulletinData }) {
  const moyenneFinale = bulletinData.moyenne_generale;
  const mentionLabel = bulletinData.mention || '—';

  return (
    <div className="summary-grid">
      <div className="summary-item">
        <span className="summary-label">Moyenne CC:</span>
        <span className="summary-value">{bulletinData.moyenne_cc?.toFixed(2) || '—'}</span>
      </div>
      <div className="summary-item">
        <span className="summary-label">Moyenne Theorique:</span>
        <span className="summary-value">{bulletinData.moyenne_theorique?.toFixed(2) || '—'}</span>
      </div>
      <div className="summary-item">
        <span className="summary-label">Moyenne Pratique:</span>
        <span className="summary-value">{bulletinData.moyenne_pratique?.toFixed(2) || '—'}</span>
      </div>
      <div className="summary-item" style={{borderTop: '2px solid var(--primary)', paddingTop: 8}}>
        <span className="summary-label" style={{fontWeight: 700}}>Moyenne Generale:</span>
        <span className="summary-value" style={{fontWeight: 700, fontSize: 18}}>{moyenneFinale?.toFixed(2) || '—'}</span>
      </div>
      <div className="summary-item">
        <span className="summary-label">Mention:</span>
        <Badge label={mentionLabel} color={
          mentionLabel === 'Très Bien' ? 'teal' :
          mentionLabel === 'Bien' ? 'blue' :
          mentionLabel === 'Assez Bien' ? 'green' :
          mentionLabel === 'Passable' ? 'yellow' : 'red'
        } />
      </div>
      <div className="summary-item">
        <span className="summary-label">Decision du Jury:</span>
        <Badge
          label={bulletinData.decision || '—'}
          color={bulletinData.decision === 'Admis(e)' ? 'green' : 'red'}
        />
      </div>
    </div>
  );
}
