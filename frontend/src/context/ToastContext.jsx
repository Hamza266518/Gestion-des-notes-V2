import { createContext, useContext, useState, useCallback, useMemo, useRef, useEffect } from 'react';
import '../css/components.css';

const ToastContext = createContext(null);

export const ToastProvider = ({ children }) => {
  const [toasts, setToasts] = useState([]);
  const nextId = useRef(0);
  const timers = useRef(new Map());

  const removeToast = useCallback((id) => {
    setToasts(prev => prev.filter(t => t.id !== id));
    const timer = timers.current.get(id);
    if (timer) {
      clearTimeout(timer);
      timers.current.delete(id);
    }
  }, []);

  // Timers outlive the component otherwise, and a page that unmounts while a
  // toast is on screen would call setState on a dead provider.
  useEffect(() => {
    const pending = timers.current;
    return () => {
      pending.forEach(timer => clearTimeout(timer));
      pending.clear();
    };
  }, []);

  const addToast = useCallback((message, type) => {
    // Date.now() collided when two toasts landed in the same millisecond: they
    // shared a key and the first timer closed both.
    const id = ++nextId.current;
    setToasts(prev => [...prev, { id, message, type }]);
    timers.current.set(id, setTimeout(() => removeToast(id), 4000));
  }, [removeToast]);

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
          <div key={toast.id} className={`toast toast-${toast.type}`}>
            <span>{toast.message}</span>
            <button className="toast-close" onClick={() => removeToast(toast.id)} aria-label="Fermer">×</button>
          </div>
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