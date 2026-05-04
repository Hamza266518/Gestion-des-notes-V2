import { useState, useEffect, useCallback } from 'react';
import portalApi from '../../api/portal';
import { useToast } from '../../context/ToastContext';
import { useAnneeAcademique } from '../../context/AnneeAcademiqueContext';
import { handleApiError } from '../../utils/errorHandler';
import { formatNiveau, getMention } from '../../utils/helpers';
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
                        <th style={thStyle}>Moyenne</th>
                        <th style={thStyle}>Coef</th>
                      </tr>
                    </thead>
                    <tbody>
                      {unites.map(unite => (
                        <tr key={unite.nom}>
                          <td style={{ ...tdStyle, fontWeight: 700 }}>{unite.nom}</td>
                          <td style={{ ...tdStyle, fontWeight: 700 }}>
                            {unite.moyenne !== null && unite.moyenne !== undefined ? unite.moyenne.toFixed(2) : '—'}
                          </td>
                          <td style={{ ...tdStyle, fontWeight: 700 }}>{unite.coefficient}</td>
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
  const allUnites = [
    ...(bulletinData.semestres?.[1] || []),
    ...(bulletinData.semestres?.[2] || [])
  ];
  const validUnites = allUnites.filter(u => u.moyenne !== null && u.moyenne !== undefined);
  const totalCoef = validUnites.reduce((s, u) => s + u.coefficient, 0);

  const mpcc = totalCoef > 0
    ? validUnites.reduce((s, u) => s + u.moyenne * u.coefficient, 0) / totalCoef
    : null;

  const moyenneFinale = bulletinData.moyenne_generale ?? mpcc;
  const { label: mentionLabel, color: mentionColorVal } = getMention(moyenneFinale);

  return (
    <div className="summary-grid">
      <div className="summary-item">
        <span className="summary-label">Moyenne generale:</span>
        <span className="summary-value">{moyenneFinale?.toFixed(2) || '—'}/20</span>
      </div>
      <div className="summary-item">
        <span className="summary-label">Mention:</span>
        <Badge label={mentionLabel} color={mentionColorVal} />
      </div>
    </div>
  );
}
