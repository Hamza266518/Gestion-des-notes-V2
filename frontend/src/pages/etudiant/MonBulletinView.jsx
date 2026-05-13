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
        </div>
        <div className="empty-state">
          <p>Bulletin non publie pour le moment.</p>
        </div>
      </div>
    );
  }

  const { etudiant, bulletin: bulletinData } = bulletin;

  const allUnites = [];
  if (bulletinData.semestres?.[1]) allUnites.push(...bulletinData.semestres[1]);
  if (bulletinData.semestres?.[2]) allUnites.push(...bulletinData.semestres[2]);

  return (
    <div className="page-container">
      <div className="page-header">
        <h1>Mon Bulletin</h1>
      </div>

      <div className="bulletin-card">
        <div className="bulletin-header">
          <div className="bulletin-brand">
            <h2>IFP</h2>
            <p>Institut de Formation Paramedicale</p>
          </div>
          <div className="bulletin-title-area">
            <h3>Bulletin de Notes</h3>
            <p>Année Académique {currentAnnee?.label || ''}</p>
          </div>
        </div>

        <div className="bulletin-info">
          <div className="bulletin-info-item">
            <span className="bulletin-info-label">Étudiant</span>
            <span className="bulletin-info-value">{etudiant.nom_prenom}</span>
          </div>
          <div className="bulletin-info-item">
            <span className="bulletin-info-label">CIN</span>
            <span className="bulletin-info-value">{etudiant.cin || '—'}</span>
          </div>
          <div className="bulletin-info-item">
            <span className="bulletin-info-label">N° Inscription</span>
            <span className="bulletin-info-value">{etudiant.numero_inscription || '—'}</span>
          </div>
          <div className="bulletin-info-item">
            <span className="bulletin-info-label">Filière</span>
            <span className="bulletin-info-value">{etudiant.groupe?.niveau?.filiere?.nom || '—'}</span>
          </div>
          <div className="bulletin-info-item">
            <span className="bulletin-info-label">Niveau</span>
            <span className="bulletin-info-value">{formatNiveau(etudiant.groupe?.niveau?.numero)}</span>
          </div>
          <div className="bulletin-info-item">
            <span className="bulletin-info-label">Groupe</span>
            <span className="bulletin-info-value">{etudiant.groupe?.nom || '—'}</span>
          </div>
        </div>

        {allUnites.length > 0 && (
          <div className="bulletin-table-wrap">
            <table className="bulletin-table">
              <thead>
                <tr>
                  <th>Unité</th>
                  <th className="bulletin-col-cc">CC (36.66%)</th>
                  <th className="bulletin-col-theo">Théorique (26.66%)</th>
                  <th className="bulletin-col-pra">Pratique (36.66%)</th>
                  <th>Moy. Unité</th>
                  <th>Coef</th>
                </tr>
              </thead>
              <tbody>
                {allUnites.map(unite => (
                  <tr key={unite.nom}>
                    <td className="bulletin-unite-name">{unite.nom}</td>
                    <td className="bulletin-cell-cc">
                      {unite.moyenne_cc !== null && unite.moyenne_cc !== undefined ? unite.moyenne_cc.toFixed(2) : '—'}
                    </td>
                    <td className="bulletin-cell-theo">
                      {unite.moyenne_theorique !== null && unite.moyenne_theorique !== undefined ? unite.moyenne_theorique.toFixed(2) : '—'}
                    </td>
                    <td className="bulletin-cell-pra">
                      {unite.moyenne_pratique !== null && unite.moyenne_pratique !== undefined ? unite.moyenne_pratique.toFixed(2) : '—'}
                    </td>
                    <td className="bulletin-cell-moy">{unite.moyenne?.toFixed(2) || '—'}</td>
                    <td className="bulletin-cell-coef">{unite.coefficient}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}

        <div className="bulletin-summary">
          <SummaryCalculations bulletinData={bulletinData} />
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
        <span className="summary-label">Moyenne CC</span>
        <span className="summary-value">{bulletinData.moyenne_cc?.toFixed(2) || '—'}</span>
      </div>
      <div className="summary-item">
        <span className="summary-label">Moyenne Théorique</span>
        <span className="summary-value">{bulletinData.moyenne_theorique?.toFixed(2) || '—'}</span>
      </div>
      <div className="summary-item">
        <span className="summary-label">Moyenne Pratique</span>
        <span className="summary-value">{bulletinData.moyenne_pratique?.toFixed(2) || '—'}</span>
      </div>
      <div className="summary-item summary-item-total">
        <span className="summary-label">Moyenne Générale</span>
        <span className="summary-value summary-value-main">{moyenneFinale?.toFixed(2) || '—'}</span>
      </div>
      <div className="summary-item">
        <span className="summary-label">Mention</span>
        <Badge label={mentionLabel} color={
          mentionLabel === 'Très Bien' ? 'teal' :
          mentionLabel === 'Bien' ? 'blue' :
          mentionLabel === 'Assez Bien' ? 'green' :
          mentionLabel === 'Passable' ? 'yellow' : 'red'
        } />
      </div>
      <div className="summary-item">
        <span className="summary-label">Décision du Jury</span>
        <Badge
          label={bulletinData.decision || '—'}
          color={bulletinData.decision === 'Admis(e)' ? 'green' : 'red'}
        />
      </div>
    </div>
  );
}
