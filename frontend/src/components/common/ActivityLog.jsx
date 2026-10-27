import { useMemo } from 'react';
import { formatTimeAgo } from '../../utils/helpers';
import {
  FiPlusCircle,
  FiEdit2,
  FiTrash2,
  FiCheckCircle,
  FiXCircle,
  FiClock,
} from 'react-icons/fi';
import '../../css/components.css';

const ACTIONS = {
  create:    { label: 'Création',       icon: FiPlusCircle,  color: '#10b981', bg: 'rgba(16, 185, 129, 0.12)' },
  update:    { label: 'Modification',   icon: FiEdit2,       color: '#3b82f6', bg: 'rgba(59, 130, 246, 0.12)' },
  delete:    { label: 'Suppression',    icon: FiTrash2,      color: '#ef4444', bg: 'rgba(239, 68, 68, 0.12)' },
  publish:   { label: 'Publication',    icon: FiCheckCircle, color: '#14b8a6', bg: 'rgba(20, 184, 166, 0.12)' },
  unpublish: { label: 'Dépublication',  icon: FiXCircle,     color: '#f59e0b', bg: 'rgba(245, 158, 11, 0.12)' },
};

const FALLBACK = { label: 'Activité', icon: FiClock, color: '#6b7280', bg: 'rgba(107, 114, 128, 0.12)' };

function actionMeta(type) {
  return ACTIONS[type] || FALLBACK;
}

/**
 * Older rows stored the admin's name inside the description ("Admin IFP a
 * publié..."), while the list already prints the name next to it. Strip that
 * prefix so the line does not read "Admin IFP Admin IFP a publié...".
 */
function cleanDescription(entry) {
  const description = String(entry?.description ?? '');
  const name = String(entry?.admin_name ?? '').trim();
  if (!name) return description;

  if (description.toLowerCase().startsWith(name.toLowerCase() + ' ')) {
    return description.slice(name.length + 1);
  }
  return description;
}

function exactDate(value) {
  if (!value) return '';
  const date = new Date(value);
  if (Number.isNaN(date.getTime())) return '';
  return date.toLocaleString('fr-FR', {
    day: '2-digit',
    month: '2-digit',
    year: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
  });
}

function isNewerThan(entry, newSince) {
  if (!newSince) return false;
  const created = new Date(entry.created_at).getTime();
  const since = new Date(newSince).getTime();
  return Number.isFinite(created) && Number.isFinite(since) && created > since;
}

export default function ActivityLog({ entries = [], limit = 10, filterModel = null, newSince = null }) {
  const filtered = useMemo(() => {
    const list = [...entries].sort((a, b) => new Date(b.created_at) - new Date(a.created_at));
    const result = filterModel ? list.filter(e => e.model_type === filterModel) : list;
    return result.slice(0, limit);
  }, [entries, limit, filterModel]);

  if (filtered.length === 0) {
    return <div className="activity-log-empty">Aucune activité récente</div>;
  }

  return (
    <div className="activity-log">
      <h4 className="activity-log-title">Journal d'activité</h4>
      <div className="activity-log-list">
        {filtered.map((entry) => {
          const meta = actionMeta(entry.action_type);
          const Icon = meta.icon;
          const fresh = isNewerThan(entry, newSince);
          const description = cleanDescription(entry);

          return (
            <div
              key={entry.id}
              className={`activity-log-entry${fresh ? ' activity-log-entry--new' : ''}`}
            >
              <span
                className="activity-log-icon"
                style={{ color: meta.color, backgroundColor: meta.bg }}
                title={meta.label}
              >
                <Icon size={15} />
              </span>
              <div className="activity-log-content">
                <div className="activity-log-desc">
                  <strong>{entry.admin_name}</strong>{' '}
                  <span className="activity-log-desc-text">{description}</span>
                </div>
                <div className="activity-log-meta">
                  <span
                    className="activity-log-type"
                    style={{ color: meta.color, backgroundColor: meta.bg }}
                  >
                    {meta.label}
                  </span>
                  <span className="activity-log-time" title={exactDate(entry.created_at)}>
                    {formatTimeAgo(entry.created_at)}
                    {exactDate(entry.created_at) ? ` · ${exactDate(entry.created_at)}` : ''}
                  </span>
                  {fresh && <span className="activity-log-new">Nouveau</span>}
                </div>
              </div>
            </div>
          );
        })}
      </div>
    </div>
  );
}
