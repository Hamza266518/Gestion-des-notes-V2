import '../../css/components.css';

export default function Modal({ open, onClose, title, children }) {
  if (!open) return null;
  return (
    <div className="custom-modal-overlay" onClick={onClose}>
      <div className="custom-modal" onClick={e => e.stopPropagation()}>
        <div className="custom-modal-header">
          <span className="custom-modal-title">{title}</span>
          <button className="custom-modal-close" onClick={onClose}>✕</button>
        </div>
        <div className="custom-modal-body">
          {children}
        </div>
      </div>
    </div>
  );
}