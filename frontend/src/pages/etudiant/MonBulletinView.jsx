import { useState, useEffect, useCallback } from 'react';
import portalApi from '../../api/portal';
import { useToast } from '../../context/ToastContext';
import { useAnneeAcademique } from '../../context/AnneeAcademiqueContext';
import { handleApiError } from '../../utils/errorHandler';
import { formatNiveau } from '../../utils/helpers';
import Badge from '../../components/common/Badge';
import Spinner from '../../components/common/Spinner';
import logoMenu from '../../image/logo-menu.png';
import '../../css/components.css';
import '../../css/layout.css';
import '../../css/pages.css';
import '../../css/bulletin-view.css';

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
        <div className="empty-state">
          <p>Bulletin non publie pour le moment.</p>
        </div>
      </div>
    );
  }

  const { etudiant, bulletin: bulletinData } = bulletin;

  const groupe = etudiant.groupe || {};
  const niveau = groupe.niveau || {};
  const filiere = niveau.filiere || {};

  // These three describe where the student sits in the school, so they read as
  // one line under the name instead of three separate labelled boxes.
  const meta = [
    filiere.nom,
    niveau.numero ? formatNiveau(niveau.numero) : null,
    groupe.nom,
  ].filter(Boolean);

  // Only the two document numbers are worth labelling: they are what a student
  // has to quote at a counter or on an exam paper.
  const ids = [
    { label: 'CIN', value: etudiant.cin },
    { label: 'N° inscription', value: etudiant.numero_inscription },
  ].filter(item => item.value);

  const allUnites = [];
  if (bulletinData.semestres?.[1]) allUnites.push(...bulletinData.semestres[1]);
  if (bulletinData.semestres?.[2]) allUnites.push(...bulletinData.semestres[2]);

  return (
    <div className="bv-page">
      <div className="bv-card">
        <div className="bv-head">
          <div className="bv-brand">
            <img src={logoMenu} alt="IFP Institut de Formation Paramedicale" className="bv-logo" />
          </div>
          <div className="bv-title">
            <h2>Bulletin de Notes</h2>
            <p>Année Académique {currentAnnee?.label || ''}</p>
          </div>
        </div>

        <header className="bv-id">
          <span className="bv-id-bar" aria-hidden="true" />
          <div className="bv-id-main">
            <h1 className="bv-name">{etudiant.nom_prenom}</h1>
            {meta.length > 0 && <p className="bv-meta">{meta.join(' · ')}</p>}
          </div>
          {groupe.promotion && (
            <span className="bv-promotion">Promotion {groupe.promotion}</span>
          )}
        </header>

        {ids.length > 0 && (
          <dl className="bv-ids">
            {ids.map(item => (
              <div className="bv-id-item" key={item.label}>
                <dt>{item.label}</dt>
                <dd>{item.value}</dd>
              </div>
            ))}
          </dl>
        )}

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

        <div className="bv-summary">
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