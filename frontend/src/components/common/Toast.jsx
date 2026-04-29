import { useToast } from '../../context/ToastContext';
import '../../css/components.css';

export default function Toast() {
  const { toasts } = useToast();
  return (
    <div className="toast-container">
      {toasts.map(t => (
        <div key={t.id} className={`toast toast-${t.type}`}>
          {t.message}
        </div>
      ))}
    </div>
  );
}