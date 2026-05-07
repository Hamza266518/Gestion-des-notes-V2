import '../../css/components.css';

export default function EmptyState({ message, actions }) {
  return (
    <div className="empty-state">
      <p className="empty-state-message">{message}</p>
      {actions && <div className="empty-state-actions">{actions}</div>}
    </div>
  );
}
