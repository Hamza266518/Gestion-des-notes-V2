import { createContext, useContext, useState, useCallback, useMemo } from 'react';

const ToastContext = createContext(null);

const TOAST_DURATION = {
  success: 3000,
  error: 5000,
  warning: 4000,
  info: 2000
};

const TOAST_COLORS = {
  success: '#10b981',
  error: '#ef4444',
  warning: '#f59e0b',
  info: '#3b82f6'
};

export const ToastProvider = ({ children }) => {
  const [toasts, setToasts] = useState([]);

  const addToast = useCallback((message, type = 'success', dismissible = false) => {
    const id = Date.now() + Math.random();
    setToasts(prev => [...prev, { id, message, type, dismissible }]);

    if (!dismissible) {
      setTimeout(() => {
        setToasts(prev => prev.filter(t => t.id !== id));
      }, TOAST_DURATION[type] || 3000);
    }
  }, []);

  const removeToast = useCallback((id) => {
    setToasts(prev => prev.filter(t => t.id !== id));
  }, []);

  const success = useCallback((msg) => addToast(msg, 'success'), [addToast]);
  const error = useCallback((msg) => addToast(msg, 'error'), [addToast]);
  const warning = useCallback((msg) => addToast(msg, 'warning'), [addToast]);
  const info = useCallback((msg) => addToast(msg, 'info'), [addToast]);

  const contextValue = useMemo(() => ({
    toasts,
    success,
    error,
    warning,
    info,
    removeToast
  }), [toasts, success, error, warning, info, removeToast]);

  return (
    <ToastContext.Provider value={contextValue}>
      {children}
      <div style={{
        position: 'fixed',
        top: 20,
        right: 20,
        zIndex: 9999,
        display: 'flex',
        flexDirection: 'column',
        gap: 8
      }}>
        {toasts.map(toast => (
          <div
            key={toast.id}
            onClick={() => toast.dismissible && removeToast(toast.id)}
            style={{
              backgroundColor: TOAST_COLORS[toast.type] || TOAST_COLORS.info,
              color: 'white',
              padding: '12px 20px',
              borderRadius: 8,
              boxShadow: '0 4px 12px rgba(0,0,0,0.15)',
              fontSize: 14,
              fontWeight: 500,
              minWidth: 300,
              maxWidth: 450,
              cursor: toast.dismissible ? 'pointer' : 'default',
              display: 'flex',
              justifyContent: 'space-between',
              alignItems: 'center'
            }}
          >
            <span>{toast.message}</span>
            {toast.dismissible && (
              <span style={{ marginLeft: 12, fontSize: 18 }}>✕</span>
            )}
          </div>
        ))}
      </div>
    </ToastContext.Provider>
  );
};

export const useToast = () => useContext(ToastContext);