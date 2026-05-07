import { useState, useCallback, useRef } from 'react';
import { notesApi } from '../../api/notes';
import { useAnneeAcademique } from '../../context/AnneeAcademiqueContext';
import FiliereCascade from '../../components/common/FiliereCascade';
import Spinner from '../../components/common/Spinner';
import EmptyState from '../../components/common/EmptyState';
import '../../css/components.css';
import '../../css/layout.css';

const TABLE_TYPES = [
  { value: 'mpcc1', label: 'MPCC1 (Bloc 1 contrôles continus)' },
  { value: 'mpcc2', label: 'MPCC2 (Bloc 2 contrôles continus)' },
  { value: 'mpcc_global', label: 'MPCC global (full cursus)' },
  { value: 'mpefcf1', label: 'MPEFCFT1 (théorique Bloc 1)' },
  { value: 'mpefcf2', label: 'MPEFCFT2 (théorique Bloc 2)' },
  { value: 'mpefcfp1', label: 'MPEFCFP1 (pratique Bloc 1)' },
  { value: 'mpefcfp2', label: 'MPEFCFP2 (pratique Bloc 2)' },
  { value: 'nsti', label: 'NSTI (soutenance)' },
  { value: 'nf', label: 'NF (note finale)' },
];

export default function Notes() {
  const [selected, setSelected] = useState({
    filiere_id: '',
    niveau_id: '',
    groupe_id: '',
    annee_academique_id: '',
  });
  const [tableType, setTableType] = useState('mpcc1');
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState(null);
  const [recapData, setRecapData] = useState(null);
  const printRef = useRef(null);

  const { currentAnnee } = useAnneeAcademique();

  const fetchRecap = useCallback(() => {
    if (!selected.groupe_id || !tableType) return;
    setLoading(true);
    setError(null);
    notesApi.getRecapNotes({ groupe_id: selected.groupe_id, type: tableType })
      .then(res => setRecapData(res.data.data))
      .catch(err => setError(err.response?.data?.message || 'Erreur lors du chargement'))
      .finally(() => setLoading(false));
  }, [selected.groupe_id, tableType]);

  const handlePrint = () => {
    const printContent = printRef.current;
    if (!printContent) return;

    const printWindow = window.open('', '_blank');
    printWindow.document.write(`
      <html>
        <head>
          <title>Relevé de Notes - Jury</title>
          <style>
            body { font-family: Arial, sans-serif; font-size: 12px; margin: 20px; }
            table { width: 100%; border-collapse: collapse; margin-top: 20px; }
            th, td { border: 1px solid #333; padding: 6px 8px; text-align: center; }
            th { background: #f0f0f0; font-weight: bold; }
            .header-row { display: flex; justify-content: space-between; margin-bottom: 8px; }
            .student-name { text-align: left; font-weight: 500; }
            .avg-cell { font-weight: bold; }
            .class-avg-row { background: #e8f5e9; }
            .class-avg-row td { font-weight: bold; }
            .coeff { font-size: 10px; color: #666; }
            .avg-header { background: #1B3A6B !important; color: white !important; }
            @media print {
              body { margin: 0; }
              .no-print { display: none; }
            }
          </style>
        </head>
        <body>
          ${printContent.innerHTML}
        </body>
      </html>
    `);
    printWindow.document.close();
    printWindow.focus();
    setTimeout(() => {
      printWindow.print();
      printWindow.close();
    }, 250);
  };

  const getAverageColumnLabel = () => {
    if (tableType === 'nf') return 'NF';
    return 'MPCC';
  };

  const renderTable = () => {
    if (!recapData) return null;
    const { students, subjects, class_average, header } = recapData;

    return (
      <div ref={printRef} className="recap-table-wrap">
        <div className="recap-header">
          <div className="recap-header-row">
            <span><strong>Date :</strong> {header?.date || '___/___/______'}</span>
            <span><strong>Lieu :</strong> {header?.lieu || 'IFP Berkane'}</span>
          </div>
          <div className="recap-header-row">
            <span><strong>Jury :</strong> {header?.jury || '________________________________'}</span>
          </div>
          <div className="recap-header-row">
            <span><strong>Filière :</strong> {header?.filiere || '—'}</span>
            <span><strong>Section :</strong> {header?.section || '—'}</span>
          </div>
          <div className="recap-header-row">
            <span><strong>Année scolaire :</strong> {header?.annee_scolaire || currentAnnee?.label || '—'}</span>
            <span><strong>Année de formation :</strong> {header?.annee_formation || '—'}</span>
          </div>
        </div>

        <table className="recap-table">
          <thead>
            <tr>
              <th rowSpan="2">#</th>
              <th rowSpan="2">Nom et Prénom</th>
              {subjects?.map(s => (
                <th key={s.id} className="subject-header">
                  <div>{s.nom}</div>
                  {s.coefficient && <div className="coeff">Coef. {s.coefficient}</div>}
                </th>
              ))}
              <th rowSpan="2" className="avg-header">{getAverageColumnLabel()}</th>
            </tr>
          </thead>
          <tbody>
            {students?.map((student, idx) => (
              <tr key={student.id}>
                <td>{idx + 1}</td>
                <td className="student-name">{student.nom_prenom}</td>
                {subjects?.map(s => (
                  <td key={s.id} className={student.notes?.[s.id] < 10 ? 'note-fail' : ''}>
                    {student.notes?.[s.id] ?? '—'}
                  </td>
                ))}
                <td className="avg-cell">
                  <strong>{student.average ?? '—'}</strong>
                </td>
              </tr>
            ))}
            {class_average !== undefined && (
              <tr className="class-avg-row">
                <td colSpan="2"><strong>Moyenne de la classe</strong></td>
                {subjects?.map(s => (
                  <td key={s.id}><strong>{class_average.subjects?.[s.id] ?? '—'}</strong></td>
                ))}
                <td className="avg-cell">
                  <strong>{class_average.overall ?? '—'}</strong>
                </td>
              </tr>
            )}
          </tbody>
        </table>
      </div>
    );
  };

  return (
    <div className="page">
      <div className="page-header">
        <h2 className="page-title">Relevé de Notes - Jury</h2>
        {recapData && (
          <button className="btn btn-primary" onClick={handlePrint}>
            Imprimer
          </button>
        )}
      </div>

      <div className="filter-bar">
        <FiliereCascade selected={selected} onChange={setSelected} />
        <select
          className="form-select"
          value={tableType}
          onChange={e => setTableType(e.target.value)}
          disabled={!selected.groupe_id}
        >
          {TABLE_TYPES.map(t => (
            <option key={t.value} value={t.value}>{t.label}</option>
          ))}
        </select>
        <button
          className="btn btn-primary"
          onClick={fetchRecap}
          disabled={!selected.groupe_id || loading}
        >
          {loading ? 'Chargement...' : 'Afficher'}
        </button>
      </div>

      {loading && <Spinner />}
      {error && <div className="alert alert-error">{error}</div>}
      {!loading && !error && !recapData && (
        <EmptyState message="Sélectionnez une filière, un niveau, un groupe et un type de tableau pour afficher le relevé." />
      )}
      {!loading && !error && recapData && renderTable()}
    </div>
  );
}
