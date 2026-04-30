import { useState } from 'react';
import ExamRow from './ExamRow';
import { FiChevronDown } from 'react-icons/fi';

export default function SubjectCard({ subject }) {
  const [expanded, setExpanded] = useState(false);
  const moyenne = subject.moyenne;
  const mention = getMention(moyenne);

  return (
    <div className="subject-card">
      <div
        className="subject-header clickable"
        onClick={() => setExpanded(!expanded)}
      >
        <div className="subject-title-row">
          <FiChevronDown
            size={14}
            className={`expand-icon ${expanded ? 'expanded' : ''}`}
          />
          <span className="subject-name">{subject.nom}</span>
        </div>
        <div className="subject-right">
          <span className="subject-coeff">Coef. {subject.coefficient}</span>
          {moyenne != null && (
            <span className={`mention-badge ${mention.class}`}>{mention.label}</span>
          )}
        </div>
      </div>

      {expanded && (
        <div className="exams-list">
          {subject.controles?.map((ctrl, idx) => (
            <ExamRow
              key={idx}
              exam={{
                nom: `Contrôle ${ctrl.numero}`,
                valeur: ctrl.valeur,
              }}
              mention={getMention(ctrl.valeur)}
            />
          ))}
        </div>
      )}

      {moyenne != null && (
        <div className="subject-average">
          Moyenne: <strong>{Number(moyenne).toFixed(2)}/20</strong>
        </div>
      )}
    </div>
  );
}

function getMention(note) {
  if (note == null) return { label: '', class: '' };
  const n = Number(note);
  if (n >= 16) return { label: 'Très Bien', class: 'mention-tres-bien' };
  if (n >= 14) return { label: 'Bien', class: 'mention-bien' };
  if (n >= 12) return { label: 'Assez Bien', class: 'mention-assez-bien' };
  if (n >= 10) return { label: 'Passable', class: 'mention-passable' };
  return { label: 'Insuffisant', class: 'mention-insuffisant' };
}
