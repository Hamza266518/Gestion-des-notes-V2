import { createContext, useContext, useState, useCallback } from 'react';

const ToastContext = createContext(null);

export const ToastProvider = ({ children }) => {
  const [toasts, setToasts] = useState([]);

  const addToast = useCallback((message, type) => {
    const id = Date.now();
    setToasts(prev => [...prev, { id, message, type }]);
    setTimeout(() => {
      setToasts(prev => prev.filter(t => t.id !== id));
    }, 3000);
  }, []);

  const success = (msg) => addToast(msg, 'success');
  const error = (msg) => addToast(msg, 'error');

  return (
    <ToastContext.Provider value={{ toasts, success, error }}>
      {children}
      <div style={{ position: 'fixed', bottom: 24, right: 24, zIndex: 9999 }}>
        {toasts.map(toast => (
          <div
            key={toast.id}
            style={{
              background: toast.type === 'error' ? '#ef4444' : '#10b981',
              color: 'white',
              padding: '12px 20px',
              marginBottom: 8,
              borderRadius: 8,
              boxShadow: '0 4px 12px rgba(0,0,0,0.15)',
              minWidth: 260,
            }}
          >
            <span>{toast.message}</span>
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
