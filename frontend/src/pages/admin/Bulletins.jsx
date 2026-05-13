import { useEffect, useState, useCallback, useRef } from 'react';
import { notesApi } from '../../api/notes';
import { adminApi } from '../../api/admin';
import { etudiantsApi } from '../../api/etudiants';
import { useToast } from '../../context/ToastContext';
import { useAnneeAcademique } from '../../context/AnneeAcademiqueContext';
import { handleApiError } from '../../utils/errorHandler';
import { formatNiveau } from '../../utils/helpers';
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

const printStyles = `
  @page { size: A4; margin: 12mm; }
  .print-only { display: none; }
  .screen-only { display: block; }
  @media print {
    body { font-family: Arial, sans-serif; font-size: 11px; margin: 0; }
    .filter-bar, .page-header, .bulletin-print-bar { display: none !important; }
    .bulletin-card { box-shadow: none !important; border: none !important; padding: 0 !important; }
    .bulletin-body { padding: 0 !important; }
    .table-wrap table { font-size: 10px; width: 100%; border-collapse: collapse; }
    .table-wrap th, .table-wrap td { padding: 3px 4px !important; font-size: 10px !important; }
    .summary-table { width: 100%; border-collapse: collapse; margin-top: 12px; }
    .summary-table td { border: 1px solid #000; padding: 4px 8px; font-size: 10px; }
    .bulletin-summary { margin-bottom: 100px; }
    .print-summary { display: flex; gap: 25px; align-items: stretch; }
    .ps-left { width: 33%; padding-top: 50px;padding-left: 20px; }
    .ps-left table { width: 100%; border-collapse: collapse; }
    .ps-left td { border: 1px solid #000; padding: 4px 8px; font-size: 10px; }
    .ps-right { width: 30%; display: flex; flex-direction: column; justify-content: flex-end; padding-top: 70px; }
    .ps-right table { width: auto; margin-left: 90px; border-collapse: collapse; }
    .ps-right td { border: 1px solid #000; padding: 4px 8px; font-size: 10px; }
    .ps-right p { font-weight: 700; text-align: right; padding: 12px; margin: 0 0 50px 0; }
    .print-footer { text-align: center; font-size: 9px; margin-top: 330px; color: #666; }
    .bulletin-info { font-size: 11px !important; line-height: 1.6 !important; margin-top: 10px !important; margin-bottom: 60px !important; }
    .bulletin-header h2 { font-size: 16px; margin: 0; }
    .bulletin-header h3 { font-size: 13px; margin: 6px 0; }
    .bulletin-header p { font-size: 11px; margin: 2px 0; }
    .print-only { display: block !important; }
    .screen-only { display: none !important; }
  }
`;

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
  }, [niveauId, currentAnnee]);

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
    const printWindow = window.open('', '_blank');
    if (!printWindow) return;
    printWindow.document.write(`<html><head><title>Bulletin</title><style>${printStyles}</style></head><body>${printRef.current?.innerHTML || ''}</body></html>`);
    printWindow.document.close();
    printWindow.focus();
    setTimeout(() => { printWindow.print(); printWindow.close(); }, 250);
  };

  const handleResetFilters = () => {
    setNiveauId('');
    setGroupeId('');
    setEtudiantId('');
  };

  const handleResetGroupeFilters = () => {
    setEtudiantId('');
  };

  const fg = (v) => v !== null && v !== undefined ? v.toFixed(2).replace('.', ',') : '—';

  return (
    <div className="page-container">
      <style>{`.print-only { display: none; } .screen-only { display: block; }`}</style>
      <div className="page-header">
        <h1>Bulletins</h1>
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
                {(() => {
                  const niv = bulletin.student.groupe?.niveau?.numero;
                  const ord = ['première', 'deuxième', 'troisième'];
                  const label = niv && ord[niv - 1] ? `Bulletins de passage de la ${ord[niv - 1]} année à la ${ord[niv] || '...'} année` : '';
                  return label ? <h3 className="print-only">{label}</h3> : null;
                })()}
              </div>

              <div className="bulletin-info" style={{marginTop: 16, fontSize: 13, lineHeight: 1.8}}>
                <div><strong>Nom et Prénom</strong> &emsp;&emsp;&emsp;&ensp;: {bulletin.student.nom || bulletin.student.nom_prenom}</div>
                <div><strong>N° inscription</strong> &emsp;&emsp;&emsp;&emsp;: {bulletin.student.numero_inscription || '—'}</div>
                <div><strong>Filière</strong> &emsp;&emsp;&emsp;&emsp;&emsp;&emsp;&ensp;: {filieres.find(f => f.id == filiereId)?.nom || '—'}</div>
                <div><strong>Section</strong> &emsp;&emsp;&emsp;&emsp;&emsp;&emsp;: {groupes.find(g => g.id == groupeId)?.nom || '—'}</div>
                <div><strong>Année scolaire</strong> &emsp;&emsp;&emsp;: {currentAnnee?.label || '—'}</div>
              </div>

              <div className="table-wrap">
                <table>
                  <thead>
                    <tr>
                      <th style={thStyle}>Unité</th>
                      <th style={thStyle}>Coefficient</th>
                      <th style={{...thStyle, background: '#e0f2fe'}}>Notes CC</th>
                      <th style={{...thStyle, background: '#fef3c7'}}>Note examen théorique</th>
                      <th style={{...thStyle, background: '#dcfce7'}}>Note examen pratique</th>
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
                          <td style={{...tdStyle, textAlign: 'center'}}>{unite.coefficient}</td>
                          <td style={{...tdStyle, background: '#f0f9ff', textAlign: 'center'}}>
                            {unite.moyenne_cc !== null ? fg(unite.moyenne_cc) : '—'}
                          </td>
                          <td style={{...tdStyle, background: '#fffbeb', textAlign: 'center'}}>
                            {unite.moyenne_theorique !== null ? fg(unite.moyenne_theorique) : '—'}
                          </td>
                          <td style={{...tdStyle, background: '#f0fdf4', textAlign: 'center'}}>
                            {unite.moyenne_pratique !== null ? fg(unite.moyenne_pratique) : '—'}
                          </td>
                        </tr>
                      ));
                    })()}
                  </tbody>
                </table>
              </div>

              <div className="bulletin-summary screen-only">
                <table className="summary-table">
                  <tbody>
                    <tr><td colSpan="2" style={{fontWeight: 700, fontSize: 14, padding: '8px 12px', borderBottom: '2px solid #333'}}>Moyenne de note obtenue</td></tr>
                    <tr>
                      <td style={{padding: '6px 12px'}}>Contrôle continu (36,66%)</td>
                      <td style={{padding: '6px 12px', textAlign: 'right', fontWeight: 600}}>{fg(bulletin.moyenne_cc)}</td>
                    </tr>
                    <tr>
                      <td style={{padding: '6px 12px'}}>Examen théorique (26,66%)</td>
                      <td style={{padding: '6px 12px', textAlign: 'right', fontWeight: 600}}>{fg(bulletin.moyenne_theorique)}</td>
                    </tr>
                    <tr>
                      <td style={{padding: '6px 12px'}}>Examen pratique (36,66%)</td>
                      <td style={{padding: '6px 12px', textAlign: 'right', fontWeight: 600}}>{fg(bulletin.moyenne_pratique)}</td>
                    </tr>
                    <tr style={{borderTop: '2px solid #333'}}>
                      <td style={{padding: '8px 12px', fontWeight: 700}}>Moyenne Générale /20</td>
                      <td style={{padding: '8px 12px', textAlign: 'right', fontWeight: 700, fontSize: 16}}>{fg(bulletin.moyenne_generale)}</td>
                    </tr>
                    <tr>
                      <td style={{padding: '6px 12px', fontWeight: 600}}>Décision du jury</td>
                      <td style={{padding: '6px 12px', textAlign: 'right', fontWeight: 700}}>{bulletin.decision || '—'}</td>
                    </tr>
                  </tbody>
                </table>
              </div>

              <div className="print-only print-summary-wrap">
                <div className="print-summary">
                    <div className="ps-left">
                      <table>
                      <tbody>
                        <tr><td colSpan="2" style={{fontWeight: 700, fontSize: 14, padding: '6px 12px'}}>Moyenne de note obtenue</td></tr>
                        <tr>
                          <td style={{padding: '6px 12px'}}>Contrôle continu (36,66%)</td>
                          <td style={{padding: '6px 12px', textAlign: 'right', fontWeight: 600}}>{fg(bulletin.moyenne_cc)}</td>
                        </tr>
                        <tr>
                          <td style={{padding: '6px 12px'}}>Examen théorique (26,66%)</td>
                          <td style={{padding: '6px 12px', textAlign: 'right', fontWeight: 600}}>{fg(bulletin.moyenne_theorique)}</td>
                        </tr>
                        <tr>
                          <td style={{padding: '6px 12px'}}>Examen pratique (36,66%)</td>
                          <td style={{padding: '6px 12px', textAlign: 'right', fontWeight: 600}}>{fg(bulletin.moyenne_pratique)}</td>
                        </tr>
                      </tbody>
                    </table>
                  </div>
                  <div className="ps-right">
                    <table>
                      <tbody>
                        <tr>
                          <td style={{padding: '8px 12px', fontWeight: 700}}>Moyenne Générale /20</td>
                          <td style={{padding: '8px 12px', textAlign: 'right', fontWeight: 700}}>{fg(bulletin.moyenne_generale)}</td>
                        </tr>
                        <tr>
                          <td style={{padding: '6px 12px', fontWeight: 600}}>Décision du jury</td>
                          <td style={{padding: '6px 12px', textAlign: 'right', fontWeight: 700}}>{bulletin.decision || '—'}</td>
                        </tr>
                      </tbody>
                    </table>
                    <p style={{fontWeight: 700, marginTop: '50px'}}>Cachet et signature de la directrice</p>
                  </div>
                </div>
              </div>

              <div className="print-only print-footer">
                Adresse : Immeuble N° :25 Avenue Al Bakay Lhbil  Berkane, Tel Fixe: 05 36 61 10 10, Tel Mobile : 06 61 61 27 67,  Adresse Mail : ifpberkane@gmail.com, Site Web : www.institutifp.com
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

