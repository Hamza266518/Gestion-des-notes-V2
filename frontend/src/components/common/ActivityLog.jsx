import { useMemo } from 'react';
import { formatTimeAgo } from '../../utils/helpers';
import '../../css/components.css';

const COLORS = {
  create: '#10b981',
  update: '#3b82f6',
  delete: '#ef4444',
  publish: '#14b8a6',
  unpublish: '#f59e0b',
  default: '#6b7280',
};

export default function ActivityLog({ entries = [], limit = 10, filterModel = null }) {
  const filtered = useMemo(() => {
    const list = [...entries].sort((a, b) => new Date(b.created_at) - new Date(a.created_at));
    const result = filterModel ? list.filter(e => e.model_type === filterModel) : list;
    return result.slice(0, limit);
  }, [entries, limit, filterModel]);

  if (filtered.length === 0) {
    return <div className="activity-log-empty">Aucune activite recente</div>;
  }

  return (
    <div className="activity-log">
      <h4 className="activity-log-title">Journal d'activite</h4>
      <div className="activity-log-list">
        {filtered.map((entry) => {
          const color = COLORS[entry.action_type] || COLORS.default;
          return (
            <div key={entry.id} className="activity-log-entry">
              <div className="activity-log-dot" style={{ backgroundColor: color }} />
              <div className="activity-log-content">
                <div className="activity-log-desc">
                  <strong>{entry.admin_name}</strong>{' '}
                  <span className="activity-log-desc-text">{entry.description}</span>
                </div>
                <div className="activity-log-time">{formatTimeAgo(entry.created_at)}</div>
              </div>
            </div>
          );
        })}
      </div>
    </div>
  );
}
