import { useState } from 'react';
import SubjectCard from './SubjectCard';
import { FiChevronDown } from 'react-icons/fi';

export default function ModuleCard({ module, semestre, annee }) {
  const [expanded, setExpanded] = useState(false);
  const moyenne = module.moyenne;

  return (
    <div className="module-card">
      <div
        className="module-card-header clickable"
        onClick={() => setExpanded(!expanded)}
      >
        <div className="module-title-row">
          <FiChevronDown
            size={16}
            className={`expand-icon ${expanded ? 'expanded' : ''}`}
          />
          <h3 className="module-title">{module.nom}</h3>
        </div>
        <div className="module-badges">
          <span className="badge badge-blue">S{semestre}</span>
          {annee && <span className="badge badge-teal">{annee}</span>}
          {moyenne != null && (
            <span className="badge badge-gray">Moy: {Number(moyenne).toFixed(2)}</span>
          )}
        </div>
      </div>

      {expanded && (
        <div className="subjects-list">
          {module.sequences?.map(seq => (
            <SubjectCard key={seq.id} subject={seq} />
          ))}
        </div>
      )}
    </div>
  );
}
