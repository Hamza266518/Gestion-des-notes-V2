import { useState } from 'react';
import { notesApi } from '../../api/notes';
import { useToast } from '../../context/ToastContext';
import '../../css/pages.css';

export const TABLE_TYPES = [
  { value: 'mpcc1', label: 'MPCC1 (Bloc 1 contrôles continus)' },
  { value: 'mpcc2', label: 'MPCC2 (Bloc 2 contrôles continus)' },
  { value: 'mpcc_global', label: 'MPCC global (tous blocs)' },
  { value: 'mpefcf1', label: 'MPEFCFT1 (théorique Bloc 1)' },
  { value: 'mpefcf2', label: 'MPEFCFT2 (théorique Bloc 2)' },
  { value: 'mpefcf_global', label: 'MPEFCFT global (théorique tous blocs)' },
  { value: 'mpefcfp1', label: 'MPEFCFP1 (pratique Bloc 1)' },
  { value: 'mpefcfp2', label: 'MPEFCFP2 (pratique Bloc 2)' },
  { value: 'mpefcfp_global', label: 'MPEFCFP global (pratique tous blocs)' },
  { value: 'nsti', label: "NSTI (Critères arrêtés par le jury d'examens)" },
  { value: 'stage', label: 'Stage (résultats de stage)' },
  { value: 'synthese', label: 'Synthèse (note finale NF)' },
];

export function formatGrade(v) {
  return v !== null && v !== undefined ? v.toFixed(2).replace('.', ',') : '—';
}

/**
 * The coefficient above a subject: a whole number when the weight is exact
 * (30 / 20 / 30 / 20) and a two-decimal French value otherwise
 * (36,67 / 26,66 / 36,67), matching the bulletin's percentages.
 */
export function formatCoef(v) {
  if (v === null || v === undefined) return '';
  return Number.isInteger(v) ? String(v) : v.toFixed(2).replace('.', ',');
}

/**
 * The "Tableau" picker options for a group: TABLE_TYPES minus the tables the
 * group does not have -- NSTI when the filière/year ends with no soutenance,
 * Stage when it has no Stage unit. `available` is the type list the backend
 * reports; an empty/missing list falls back to every table.
 */
export function tableTypeOptions(available) {
  const all = TABLE_TYPES.map(t => ({ id: t.value, label: t.label }));
  if (!Array.isArray(available) || available.length === 0) return all;
  return all.filter(o => available.includes(o.id));
}

export function tableTitleFor(tableType) {
  if (tableType === 'mpcc1') return 'Tableau récapitulatif des résultats des contrôles continus Bloc 01';
  if (tableType === 'mpcc2') return 'Tableau récapitulatif des résultats des contrôles continus Bloc 02';
  if (tableType === 'mpcc_global') return 'Tableau récapitulatif des résultats des contrôles continus (Global)';
  if (tableType === 'mpefcf1') return 'Tableau récapitulatif des résultats des examens théoriques Bloc 01';
  if (tableType === 'mpefcf2') return 'Tableau récapitulatif des résultats des examens théoriques Bloc 02';
  if (tableType === 'mpefcf_global') return 'Tableau récapitulatif des résultats des examens théoriques (Global)';
  if (tableType === 'mpefcfp1') return 'Tableau récapitulatif des résultats des examens pratiques Bloc 01';
  if (tableType === 'mpefcfp2') return 'Tableau récapitulatif des résultats des examens pratiques Bloc 02';
  if (tableType === 'mpefcfp_global') return 'Tableau récapitulatif des résultats des examens pratiques (Global)';
  if (tableType === 'nsti') return "Critères arrêtés par le jury d'examens";
  if (tableType === 'stage') return 'Tableau récapitulatif des résultats de stage';
  if (tableType === 'synthese') return 'Tableau récapitulatif de synthèse (note finale NF)';
  return 'Tableau récapitulatif des résultats';
}

export function avgLabelFor(tableType) {
  if (tableType === 'nsti') return 'NSTI*';
  if (tableType === 'synthese') return 'NF*****';
  // The Stage sheet has no average column at all.
  if (tableType === 'stage') return '';
  if (tableType.startsWith('mpefcfp')) return tableType.toUpperCase();
  if (tableType.startsWith('mpefcf')) return tableType.toUpperCase();
  if (tableType.startsWith('mpcc')) return tableType.toUpperCase() + '*';
  return 'MPCC';
}

export function obsLabelFor(tableType) {
  return ['nsti', 'stage', 'synthese'].includes(tableType)
    ? 'Proposition / commentaire de jury'
    : 'Observation';
}

const DOCX_MIME = 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';
export { DOCX_MIME };

function saveBlob(blob, filename) {
  const url = URL.createObjectURL(blob);
  const a = document.createElement('a');
  a.href = url;
  a.download = filename;
  document.body.appendChild(a);
  a.click();
  a.remove();
  URL.revokeObjectURL(url);
}
export { saveBlob };

function filenameFromDisposition(header, fallback) {
  if (!header) return fallback;
  const star = header.match(/filename\*=(?:UTF-8|utf-8)''([^;]+)/i);
  if (star) {
    try {
      return decodeURIComponent(star[1]);
    } catch {
      /* fall through to the plain filename */
    }
  }
  const match = header.match(/filename="?([^";]+)"?/);
  return match ? match[1] : fallback;
}
export { filenameFromDisposition };

/**
 * The jury sheet: one row per student, one column per unit, plus the class
 * average row the backend already computes in `class_average`.
 *
 * Moved out of Notes.jsx unchanged so the archived-year viewer and the live
 * relevé render the same grid; the print is a server-generated .docx matching
 * the reference recap document, so `groupeId` + `tableType` drive the download.
 */
export default function RecapTable({ recapData, tableType, anneeLabel, groupeId, showDownloadBar = true }) {
  const toast = useToast();
  const [downloading, setDownloading] = useState(false);

  if (!recapData) return null;

  const { students, subjects, class_average, header } = recapData;
  const tableTitle = tableTitleFor(tableType);
  const avgLabel = avgLabelFor(tableType);
  const obsLabel = obsLabelFor(tableType);
  // The Stage sheet has no average column (and the backend exposes no average
  // for it), so the header, the per-student cell and the class row skip it.
  const showAverage = tableType !== 'stage';

  const downloadDocx = async () => {
    if (!groupeId) return;
    setDownloading(true);
    try {
      const res = await notesApi.downloadRecapDocx({ groupe_id: groupeId, type: tableType });
      const blob = new Blob([res.data], { type: DOCX_MIME });
      saveBlob(blob, filenameFromDisposition(res.headers?.['content-disposition'], 'Releve_Notes.docx'));
    } catch (err) {
      toast.error(err?.response?.data?.message || 'Erreur lors de la génération du relevé');
    } finally {
      setDownloading(false);
    }
  };

  return (
    <>
      <div className="recap-table-wrap">
        <div style={{ marginBottom: 10, fontSize: 14 }}>
          <div><strong>Filière :</strong> {header?.filiere || '—'} | <strong>Section :</strong> {header?.section || '—'}</div>
          <div><strong>Année scolaire :</strong> {header?.annee_scolaire || anneeLabel || '—'}</div>
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
                {showAverage && <th style={{ whiteSpace: 'nowrap' }}>{avgLabel}</th>}
                <th>{obsLabel}</th>
              </tr>
              <tr className="coeff-row">
                <td></td>
                <td></td>
                {subjects?.map(s => <td key={s.id} style={{ textAlign: 'center' }}>Coef. {formatCoef(s.coefficient)}</td>)}
                {showAverage && <td></td>}
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
                  {showAverage && (
                    <td style={{ fontWeight: 'bold', textAlign: 'center' }}>{formatGrade(student.average)}</td>
                  )}
                  <td></td>
                </tr>
              ))}
              {class_average && (
                <tr className="class-avg-row">
                  <td colSpan="2"><strong>Moyenne de la classe</strong></td>
                  {subjects?.map(s => (
                    <td key={s.id} style={{ textAlign: 'center' }}><strong>{formatGrade(class_average.subjects?.[s.id])}</strong></td>
                  ))}
                  {showAverage && (
                    <td style={{ fontWeight: 'bold', textAlign: 'center' }}><strong>{formatGrade(class_average.overall)}</strong></td>
                  )}
                  <td></td>
                </tr>
              )}
            </tbody>
          </table>
        </div>
      </div>

      {showDownloadBar && (
        <div className="recap-print-bar" style={{ display: 'flex', justifyContent: 'flex-end', marginTop: 12 }}>
          <button className="btn btn-primary" onClick={downloadDocx} disabled={downloading || !groupeId}>
            {downloading ? 'Génération…' : 'Télécharger .docx'}
          </button>
        </div>
      )}
    </>
  );
}
