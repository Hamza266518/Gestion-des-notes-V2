import { createContext, useContext, useState, useCallback, useMemo, useRef, useEffect } from 'react';
import '../css/components.css';

const ToastContext = createContext(null);

const TOAST_DURATION = 4000;
const TICK = 100;

// One item manages its own countdown. A thin progress bar at the bottom drains
// from full to empty; hovering the toast pauses the countdown, moving the mouse
// away resumes it. Removing the toast early (× button) drops it instantly.
function ToastItem({ toast, onRemove }) {
  const [remaining, setRemaining] = useState(toast.duration);
  const pausedRef = useRef(false);

  useEffect(() => {
    const t = setInterval(() => {
      if (!pausedRef.current) setRemaining(r => Math.max(0, r - TICK));
    }, TICK);
    return () => clearInterval(t);
  }, []);

  useEffect(() => {
    if (remaining <= 0) onRemove(toast.id);
  }, [remaining, toast.id, onRemove]);

  return (
    <div
      className={`toast toast-${toast.type}`}
      onMouseEnter={() => { pausedRef.current = true; }}
      onMouseLeave={() => { pausedRef.current = false; }}
    >
      <span>{toast.message}</span>
      <button className="toast-close" onClick={() => onRemove(toast.id)} aria-label="Fermer">×</button>
      <div className="toast-progress" style={{ width: `${Math.round((remaining / toast.duration) * 100)}%` }} />
    </div>
  );
}

export const ToastProvider = ({ children }) => {
  const [toasts, setToasts] = useState([]);
  const nextId = useRef(0);

  const removeToast = useCallback((id) => {
    setToasts(prev => prev.filter(t => t.id !== id));
  }, []);

  const addToast = useCallback((message, type) => {
    // Date.now() collided when two toasts landed in the same millisecond: they
    // shared a key and the first timer closed both.
    const id = ++nextId.current;
    setToasts(prev => [...prev, { id, message, type, duration: TOAST_DURATION }]);
  }, []);

  // Stable identities: these are dependency arrays in consuming pages, and new
  // functions on every render made every effect that mentions one re-run.
  const success = useCallback((msg) => addToast(msg, 'success'), [addToast]);
  const error = useCallback((msg) => addToast(msg, 'error'), [addToast]);
  const warning = useCallback((msg) => addToast(msg, 'warning'), [addToast]);
  const info = useCallback((msg) => addToast(msg, 'info'), [addToast]);

  const value = useMemo(
    () => ({ toasts, success, error, warning, info, removeToast }),
    [toasts, success, error, warning, info, removeToast]
  );

  return (
    <ToastContext.Provider value={value}>
      {children}
      <div className="toast-container">
        {toasts.map(toast => (
          <ToastItem key={toast.id} toast={toast} onRemove={removeToast} />
        ))}
      </div>
    </ToastContext.Provider>
  );
};

export const useToast = () => {
  const context = useContext(ToastContext);
  if (!context) throw new Error('useToast must be used within ToastProvider');
  return context;
};