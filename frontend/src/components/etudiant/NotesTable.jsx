import { useState } from 'react';

// Generate soft/faded background color from unit name
function getUnitColor(unitName) {
  let hash = 0;
  for (let i = 0; i < unitName.length; i++) {
    hash = unitName.charCodeAt(i) + ((hash << 5) - hash);
  }
  const hue = Math.abs(hash) % 360;
  return `hsl(${hue}, 20%, 93%)`;
}

// Get border color (slightly darker than background)
function getUnitBorderColor(unitName) {
  let hash = 0;
  for (let i = 0; i < unitName.length; i++) {
    hash = unitName.charCodeAt(i) + ((hash << 5) - hash);
  }
  const hue = Math.abs(hash) % 360;
  return `hsl(${hue}, 45%, 55%)`;
}

export default function NotesTable({ modules, semestre }) {
  if (!modules || modules.length === 0) {
    return <div className="table-empty">Aucune donnée disponible pour ce semestre.</div>;
  }

  // Build rows: each sequence becomes a row
  const rows = [];
  modules.forEach(unite => {
    if (!unite.sequences) return;
    unite.sequences.forEach(seq => {
      rows.push({
        unite: unite.nom,
        seq: seq.nom,
        seqId: seq.id,
        // The sequence "coefficient" only mirrors the control count and is not
        // a real weight, so show how many controls there are instead.
        nbControles: (seq.controles || []).length,
        controles: seq.controles || [],
      });
    });
  });

  return (
    <div className="table-wrapper">
      <table className="notes-table">
        <thead>
          <tr>
            <th>Unité</th>
            <th>Séquences</th>
            <th>Contrôle 1</th>
            <th>Contrôle 2</th>
            <th>Contrôle 3</th>
          </tr>
        </thead>
        <tbody>
          {rows.map((row, idx) => {
            const bgColor = getUnitColor(row.unite);
            const borderColor = getUnitBorderColor(row.unite);
            return (
              <tr key={`${row.unite}-${row.seqId}`} style={{ backgroundColor: bgColor }}>
                <td className="unit-name-cell" style={{ borderLeftColor: borderColor, borderLeftWidth: '4px', borderLeftStyle: 'solid' }}>{row.unite}</td>
                <td className="seq-cell">
                  <div className="seq-info">
                    <span className="seq-name">{row.seq}</span>
                    <span className="seq-coeff">{row.nbControles} contrôle(s)</span>
                  </div>
                </td>
                {[0, 1, 2].map((i) => (
                  <td key={i} className="grade-cell">
                    {row.controles[i] === undefined ? (
                      <span className="no-grade">-</span>
                    ) : row.controles[i].est_absent ? (
                      <span style={{ color: '#b91c1c', fontWeight: 600, fontSize: 12 }}>Absent</span>
                    ) : row.controles[i].valeur != null ? (
                      <span className={getGradeClass(row.controles[i].valeur)}>
                        {Number(row.controles[i].valeur).toFixed(2)}/20
                      </span>
                    ) : (
                      <span></span>
                    )}
                  </td>
                ))}
              </tr>
            );
          })}
        </tbody>
      </table>
    </div>
  );
}

function getGradeClass(note) {
  if (note == null) return '';
  const n = Number(note);
  if (n >= 16) return 'grade-excellent';
  if (n >= 14) return 'grade-good';
  if (n >= 12) return 'grade-average';
  if (n >= 10) return 'grade-pass';
  return 'grade-fail';
}
