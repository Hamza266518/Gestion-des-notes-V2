import { Fragment, useState } from 'react';
import { FiChevronDown, FiChevronRight } from 'react-icons/fi';
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

const fg = (v) => v !== null && v !== undefined ? v.toFixed(2).replace('.', ',') : '—';

/**
 * "36,67%" / "26,66%" / "30%" -- the weight labels of the summary. The backend
 * sends the weights actually applied to the student's programme (a final-year
 * soutenance programme is 30/20/30/20, every other one 36,67/26,66/36,67).
 */
const pct = (w) => {
  if (w === null || w === undefined) return '—';
  return `${String(Math.round(w * 10000) / 100).replace('.', ',')}%`;
};

/**
 * Per-unit drill-down: the sequences of a unit and every control inside them,
 * with the mark the student holds. This data was always in the payload
 * (`semesters[].unites[].sequences[].controles[]`); the page only ever showed
 * the three aggregate columns.
 */
function SequenceDetail({ unite }) {
  if (!unite.sequences?.length) {
    return (
      <div style={{ padding: '6px 12px 10px 32px', fontSize: 11, color: '#6b7280' }}>
        Aucune séquence enregistrée pour cette unité.
      </div>
    );
  }

  return (
    <div className="seq-detail" style={{ padding: '4px 12px 10px 32px', background: '#f9fafb' }}>
      {unite.sequences.map(seq => (
        <div key={seq.id} style={{ marginBottom: 8 }}>
          <div style={{ fontSize: 11, fontWeight: 600, color: '#374151', marginBottom: 3 }}>
            {seq.nom}
            {seq.controles?.length ? <span style={{ fontWeight: 400, color: '#6b7280' }}> · {seq.controles.length} contrôle(s)</span> : null}
            <span style={{ fontWeight: 400, color: '#6b7280' }}> · Moyenne séquence : {fg(seq.moyenneSeq)}</span>
          </div>
          {seq.controles?.length ? (
            <table style={{ width: '100%', borderCollapse: 'collapse', fontSize: 11 }}>
              <tbody>
                {seq.controles.map(ctrl => (
                  <tr key={ctrl.id}>
                    <td style={{ padding: '2px 0', color: '#4b5563' }}>
                      {ctrl.nom}
                      <span style={{ color: '#9ca3af' }}>
                        {' '}· /{ctrl.note_max ?? 20}
                      </span>
                    </td>
                    <td style={{ padding: '2px 0', textAlign: 'right', fontWeight: 600, width: 90 }}>
                      {ctrl.est_absent ? 'Abs.' : fg(ctrl.valeur)}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          ) : (
            <div style={{ fontSize: 11, color: '#9ca3af' }}>Aucun contrôle.</div>
          )}
        </div>
      ))}
    </div>
  );
}

/**
 * The sheet itself: identity, units and the weighted summary, without the card
 * around it or the action bar.
 */
function BulletinSheetBody({ bulletin, filiereNom, groupeNom, anneeLabel }) {
  const [openUnites, setOpenUnites] = useState([]);

  if (!bulletin || !bulletin.student) return null;

  const toggleUnite = (id) => {
    setOpenUnites(prev => (prev.includes(id) ? prev.filter(x => x !== id) : [...prev, id]));
  };

  const allUnites = [
    ...(bulletin.semesters?.[1]?.unites || []),
    ...(bulletin.semesters?.[2]?.unites || []),
  ];

  // The components of the final mark, in the order the sheet prints them. The
  // NSTI only exists for the programmes whose final year ends with a soutenance.
  const weights = bulletin.weights || { cc: 0.3667, theorique: 0.2666, pratique: 0.3667 };
  const componentRows = [
    { key: 'cc', label: 'Contrôle continu', value: bulletin.moyenne_cc },
    { key: 'theorique', label: 'Examen théorique', value: bulletin.moyenne_theorique },
    { key: 'pratique', label: 'Examen pratique', value: bulletin.moyenne_pratique },
    ...(bulletin.has_soutenance
      ? [{ key: 'nsti', label: 'Note de soutenance (NSTI)', value: bulletin.nsti }]
      : []),
  ];

  return (
    <>
      <div className="bulletin-info" style={{ marginTop: 16, fontSize: 13, lineHeight: 1.8 }}>
        <div><strong>Nom et Prénom</strong> &emsp;&emsp;&ensp;: {bulletin.student.nom || bulletin.student.nom_prenom}</div>
        <div><strong>N° inscription</strong> &emsp;&emsp;&emsp;: {bulletin.student.numero_inscription || '—'}</div>
        <div><strong>Filière</strong> &emsp;&emsp;&emsp;&emsp;&ensp;: {filiereNom || '—'}</div>
        <div><strong>Section</strong> &emsp;&emsp;&emsp;&emsp;&emsp;: {groupeNom || '—'}</div>
        <div><strong>Année scolaire</strong> &emsp;&emsp;: {anneeLabel || '—'}</div>
      </div>

      <div className="table-wrap">
        <table>
          <thead>
            <tr>
              <th style={thStyle}>Unité</th>
              <th style={thStyle}>Coefficient</th>
              <th style={{ ...thStyle, background: '#e0f2fe' }}>Notes CC</th>
              <th style={{ ...thStyle, background: '#fef3c7' }}>Note examen théorique</th>
              <th style={{ ...thStyle, background: '#dcfce7' }}>Note examen pratique</th>
            </tr>
          </thead>
          <tbody>
            {allUnites.map(unite => (
              <Fragment key={unite.id}>
                <tr className="bulletin-unite-row">
                  <td style={{ ...tdStyle, fontWeight: 700 }}>
                    <button
                      type="button"
                      onClick={() => toggleUnite(unite.id)}
                      title={unite.sequences?.length ? 'Voir les séquences et les contrôles' : undefined}
                      style={{
                        display: 'inline-flex', alignItems: 'center', gap: 4,
                        background: 'none', border: 'none', padding: 0,
                        cursor: unite.sequences?.length ? 'pointer' : 'default',
                        fontWeight: 700, color: 'inherit', fontSize: 'inherit',
                      }}
                    >
                      {unite.sequences?.length
                        ? (openUnites.includes(unite.id) ? <FiChevronDown size={13} /> : <FiChevronRight size={13} />)
                        : null}
                      {unite.nom}
                    </button>
                  </td>
                  <td style={{ ...tdStyle, textAlign: 'center' }}>{unite.coefficient}</td>
                  <td style={{ ...tdStyle, background: '#f0f9ff', textAlign: 'center' }}>
                    {unite.moyenne_cc !== null ? fg(unite.moyenne_cc) : '—'}
                  </td>
                  <td style={{ ...tdStyle, background: '#fffbeb', textAlign: 'center' }}>
                    {unite.moyenne_theorique !== null ? fg(unite.moyenne_theorique) : '—'}
                  </td>
                  <td style={{ ...tdStyle, background: '#f0fdf4', textAlign: 'center' }}>
                    {unite.moyenne_pratique !== null ? fg(unite.moyenne_pratique) : '—'}
                  </td>
                </tr>
                {openUnites.includes(unite.id) && (
                  <tr>
                    <td colSpan={5} style={{ padding: 0 }}>
                      <SequenceDetail unite={unite} />
                    </td>
                  </tr>
                )}
              </Fragment>
            ))}
          </tbody>
        </table>
      </div>

      <div className="bulletin-summary">
        <table className="summary-table">
          <tbody>
            <tr><td colSpan="2" style={{ fontWeight: 700, fontSize: 14, padding: '8px 12px', borderBottom: '2px solid #333' }}>Moyenne de note obtenue</td></tr>
            {componentRows.map(row => (
              <tr key={row.key}>
                <td style={{ padding: '6px 12px' }}>{row.label} ({pct(weights[row.key])})</td>
                <td style={{ padding: '6px 12px', textAlign: 'right', fontWeight: 600 }}>{fg(row.value)}</td>
              </tr>
            ))}
            <tr style={{ borderTop: '2px solid #333' }}>
              <td style={{ padding: '8px 12px', fontWeight: 700 }}>Moyenne Générale /20</td>
              <td style={{ padding: '8px 12px', textAlign: 'right', fontWeight: 700, fontSize: 16 }}>{fg(bulletin.moyenne_generale)}</td>
            </tr>
            <tr>
              <td style={{ padding: '6px 12px', fontWeight: 600 }}>Décision du jury</td>
              <td style={{ padding: '6px 12px', textAlign: 'right', fontWeight: 700 }}>{bulletin.decision || '—'}</td>
            </tr>
          </tbody>
        </table>
      </div>
    </>
  );
}

/**
 * A student's bulletin: the units with their three averages, the weighted
 * summary, and the jury decision.
 *
 * Moved out of Bulletins.jsx so the archived-year viewer shows the very same
 * sheet. `filiereNom`, `groupeNom` and `anneeLabel` are passed in because the
 * page used to read them out of its own filter lists.
 */
export default function BulletinSheet({
  bulletin,
  filiereNom,
  groupeNom,
  anneeLabel,
  onDownloadDocx,
  onDownloadDocxAll,
  downloadingDocx,
  downloadingDocxAll,
}) {
  if (!bulletin || !bulletin.student) return null;

  return (
    <div className="card bulletin-card">
      <div className="card-body bulletin-body">
        <BulletinSheetBody
          bulletin={bulletin}
          filiereNom={filiereNom}
          groupeNom={groupeNom}
          anneeLabel={anneeLabel}
        />

        <div
          className="bulletin-actions"
          style={{ display: 'flex', justifyContent: 'flex-end', alignItems: 'center', gap: 8, marginTop: 12, flexWrap: 'wrap' }}
        >
          {onDownloadDocx ? (
            <button className="btn btn-outline" onClick={onDownloadDocx} disabled={downloadingDocx}>
              {downloadingDocx ? 'Génération…' : 'Télécharger le bulletin (.docx)'}
            </button>
          ) : null}
          {onDownloadDocxAll ? (
            <button className="btn btn-outline" onClick={onDownloadDocxAll} disabled={downloadingDocxAll}>
              {downloadingDocxAll ? 'Génération…' : 'Télécharger tous les bulletins (.docx)'}
            </button>
          ) : null}
        </div>
      </div>
    </div>
  );
}
