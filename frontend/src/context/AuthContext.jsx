import { createContext, useContext, useState, useEffect, useCallback, useMemo } from 'react';
import { authApi } from '../api/auth';

const AuthContext = createContext(null);

export const AuthProvider = ({ children }) => {
  const [user, setUser] = useState(null);
  const [token, setToken] = useState(() => localStorage.getItem('token'));
  const [role, setRole] = useState(() => localStorage.getItem('role'));
  const [isInitialized, setIsInitialized] = useState(false);

  const logout = useCallback(() => {
    localStorage.removeItem('token');
    localStorage.removeItem('role');
    setUser(null);
    setToken(null);
    setRole(null);
    setIsInitialized(true);
  }, []);

  const login = useCallback((data) => {
    localStorage.setItem('token', data.token);
    localStorage.setItem('role', data.role);
    setToken(data.token);
    setRole(data.role);
    setUser(data.user || null);
    setIsInitialized(true);
  }, []);

  useEffect(() => {
    if (!token) {
      setIsInitialized(true);
      return;
    }
    authApi.me()
      .then(res => {
        setUser(res.data.data);
      })
      .catch((err) => {
        console.error('Auth check failed:', err);
        logout();
      })
      .finally(() => {
        setIsInitialized(true);
      });
  }, [token, logout]);

  const value = useMemo(() => ({
    user,
    token,
    role,
    login,
    logout,
    loading: !isInitialized
  }), [user, token, role, login, logout, isInitialized]);

  return (
    <AuthContext.Provider value={value}>
      {children}
    </AuthContext.Provider>
  );
};

export const useAuth = () => {
  const context = useContext(AuthContext);
  if (!context) {
    throw new Error('useAuth must be used inside an AuthProvider');
  }
  return context;
};