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

  const formatGrade = (v) => v !== null && v !== undefined ? v.toFixed(2).replace('.', ',') : '—';

  const tableTitle = tableType === 'mpcc1' ? 'Tableau récapitulatif des résultats des contrôles continus Bloc 01'
    : tableType === 'mpcc2' ? 'Tableau récapitulatif des résultats des contrôles continus Bloc 02'
    : tableType === 'mpcc_global' ? 'Tableau récapitulatif des résultats des contrôles continus (Global)'
    : tableType === 'mpefcf1' ? 'Tableau récapitulatif des résultats des examens théoriques Bloc 01'
    : tableType === 'mpefcf2' ? 'Tableau récapitulatif des résultats des examens théoriques Bloc 02'
    : tableType === 'mpefcfp1' ? 'Tableau récapitulatif des résultats des examens pratiques Bloc 01'
    : tableType === 'mpefcfp2' ? 'Tableau récapitulatif des résultats des examens pratiques Bloc 02'
    : 'Tableau récapitulatif des résultats';

  const avgLabel = tableType.startsWith('mpefcfp') ? tableType.toUpperCase()
    : tableType.startsWith('mpefcf') ? tableType.toUpperCase()
    : tableType.startsWith('mpcc') ? tableType.toUpperCase() + '*' : 'MPCC';

  const buildHeaderTable = (h) => `
    <table class="t">
      <tr>
        <td class="c" style="width:50%"><b>Date :</b> ${h?.date || '___/___/______'}, <b>à :</b> ___h</td>
        <td class="c" style="width:50%"><b>Lieu :</b> ${h?.lieu || 'Institut des formations paramédicales privé'}</td>
      </tr>
      <tr>
        <td colspan="2" class="c" style="font-weight:bold;text-align:center">Membre de jury présents</td>
      </tr>
      <tr>
        <td class="c" style="width:33%"><b>Membres professionnels :</b><br/>Président de jury : _________________<br/>Membres : 1. _________________<br/>2. _________________</td>
        <td class="c" style="width:33%"><b>Membre de l'établissement :</b><br/>Formateur permanent :<br/>1. _________________</td>
        <td class="c" style="width:33%"><b>Membre représentant :</b><br/>_________________</td>
      </tr>
      <tr>
        <td class="c"><b>Filière :</b> ${h?.filiere || '—'}</td>
        <td class="c"><b>Année Scolaire :</b> ${h?.annee_scolaire || currentAnnee?.label || '____/____'}</td>
      </tr>
      <tr>
        <td class="c"><b>Section :</b> ${h?.section || '—'}</td>
        <td class="c"><b>Année de formation :</b> ${h?.annee_formation || '____/____'}</td>
      </tr>
    </table>`;

  const buildGradeTable = (data) => {
    const { students, subjects, class_average } = data;
    const cols = subjects.length;
    const totalCols = 4 + cols;

    let html = '<table class="t">';
    html += `<tr><td colspan="${totalCols}" class="c" style="font-weight:bold;text-align:center">${tableTitle}</td></tr>`;
    html += '<tr>';
    html += '<th class="c">#</th>';
    html += '<th class="c">Nom et Prénom</th>';
    subjects.forEach(s => { html += `<th class="c">${s.nom}</th>`; });
    html += `<th class="c">${avgLabel}</th>`;
    html += '<th class="c">Observation</th>';
    html += '</tr>';
    html += '<tr class="coeff">';
    html += '<td class="c"></td><td class="c"></td>';
    subjects.forEach(s => { html += `<td class="c">Coef. ${s.coefficient}</td>`; });
    html += '<td class="c"></td><td class="c"></td>';
    html += '</tr>';

    students.forEach((student, idx) => {
      html += '<tr>';
      html += `<td class="c">${idx + 1}</td>`;
      html += `<td class="c" style="text-align:left">${student.nom_prenom}</td>`;
      subjects.forEach(s => { html += `<td class="c">${formatGrade(student.notes?.[s.id])}</td>`; });
      html += `<td class="c" style="font-weight:bold">${formatGrade(student.average)}</td>`;
      html += '<td class="c"></td>';
      html += '</tr>';
    });

    if (class_average) {
      html += '<tr class="cavg">';
      html += '<td class="c"></td>';
      html += '<td class="c">Moyenne de la classe</td>';
      subjects.forEach(s => { html += `<td class="c">${formatGrade(class_average.subjects?.[s.id])}</td>`; });
      html += `<td class="c">${formatGrade(class_average.overall)}</td>`;
      html += '<td class="c"></td>';
      html += '</tr>';
    }

    html += '</table>';
    return html;
  };

  const buildSignatureTable = () => `
    <table class="t" style="margin-top:30px">
      <tr>
        <td class="c" style="height:50px;vertical-align:top;width:25%"><b>Signature de président de jury</b></td>
        <td class="c" style="height:50px;vertical-align:top;width:25%"><b>Signature des membres de jury externe</b><br/>1. _________________<br/>2. _________________</td>
        <td class="c" style="height:50px;vertical-align:top;width:25%"><b>Signature de membre de jury interne</b><br/>1. _________________<br/>2. _________________</td>
        <td class="c" style="height:50px;vertical-align:top;width:25%"><b>Signature du représentant<br/>de la formation professionnelle</b></td>
      </tr>
    </table>`;

  const handlePrint = () => {
    if (!recapData) return;
    const h = recapData.header;
    const printWindow = window.open('', '_blank');
    printWindow.document.write(`
      <html>
        <head>
          <title>Relevé de Notes</title>
          <style>
            @page { size: landscape; margin: 6mm; }
            body { font-family: Arial, sans-serif; margin: 0; font-size: 9px; }
            .t { width: 100%; border-collapse: collapse; border: 1px solid #000; margin-bottom: 3px; }
            .c { border: 1px solid #000; padding: 1px 2px; font-size: 8px; text-align: center; line-height: 1.1; }
            th.c { background: #f0f0f0; font-weight: bold; }
            .coeff { background: #fafafa; font-style: italic; }
            .cavg { background: #eaf3ff; font-weight: bold; }
          </style>
        </head>
        <body>
          ${buildHeaderTable(h)}
          ${buildGradeTable(recapData)}
          ${buildSignatureTable()}
        </body>
      </html>
    `);
    printWindow.document.close();
    printWindow.focus();
    setTimeout(() => { printWindow.print(); printWindow.close(); }, 250);
  };

  const renderTable = () => {
    if (!recapData) return null;
    const { students, subjects, class_average, header } = recapData;

    return (
      <div ref={printRef} className="recap-table-wrap">
        <div style={{ marginBottom: 10, fontSize: 14 }}>
          <div><strong>Filière :</strong> {header?.filiere || '—'} | <strong>Section :</strong> {header?.section || '—'}</div>
          <div><strong>Année scolaire :</strong> {header?.annee_scolaire || currentAnnee?.label || '—'}</div>
        </div>

          <div style={{ overflowX: 'auto' }}>
          <table className="recap-table" style={{ fontSize: 12, minWidth: subjects.length > 6 ? 900 : '100%' }}>
            <thead>
              <tr>
                <th>#</th>
                <th style={{ whiteSpace: 'nowrap' }}>Nom et Prénom</th>
                {subjects?.map(s => (
                  <th key={s.id} style={{ whiteSpace: 'nowrap' }}>{s.nom}</th>
                ))}
                <th style={{ whiteSpace: 'nowrap' }}>{avgLabel}</th>
                <th>Observation</th>
              </tr>
              <tr className="coeff-row">
                <td></td>
                <td></td>
                {subjects?.map(s => <td key={s.id} style={{ textAlign: 'center' }}>Coef. {s.coefficient}</td>)}
                <td></td>
                <td></td>
              </tr>
            </thead>
            <tbody>
              {students?.map((student, idx) => (
                <tr key={student.id}>
                  <td>{idx + 1}</td>
                  <td style={{ whiteSpace: 'nowrap' }}>{student.nom_prenom}</td>
                  {subjects?.map(s => (
                    <td key={s.id} className={student.notes?.[s.id] < 10 ? 'note-fail' : ''} style={{ textAlign: 'center' }}>
                      {formatGrade(student.notes?.[s.id])}
                    </td>
                  ))}
                  <td style={{ fontWeight: 'bold', textAlign: 'center' }}>{formatGrade(student.average)}</td>
                  <td></td>
                </tr>
              ))}
              {class_average && (
                <tr className="class-avg-row">
                  <td colSpan="2"><strong>Moyenne de la classe</strong></td>
                  {subjects?.map(s => (
                    <td key={s.id} style={{ textAlign: 'center' }}><strong>{formatGrade(class_average.subjects?.[s.id])}</strong></td>
                  ))}
                  <td style={{ fontWeight: 'bold', textAlign: 'center' }}><strong>{formatGrade(class_average.overall)}</strong></td>
                  <td></td>
                </tr>
              )}
            </tbody>
          </table>
        </div>
      </div>
    );
  };

  return (
    <div className="page">
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
        {recapData && (
          <button className="btn btn-primary" onClick={handlePrint} style={{ marginLeft: 'auto' }}>
            Imprimer
          </button>
        )}
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
