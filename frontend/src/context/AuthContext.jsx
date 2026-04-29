import { createContext, useContext, useState, useEffect, useCallback } from 'react';
import { authApi } from '../api/auth';

const AuthContext = createContext(null);

export const AuthProvider = ({ children }) => {
  const [user, setUser]     = useState(null);
  const [token, setToken]   = useState(localStorage.getItem('token'));
  const [role, setRole]     = useState(localStorage.getItem('role'));
  const [loading, setLoading] = useState(true);

  const logout = useCallback(() => {
    localStorage.clear();
    setUser(null);
    setToken(null);
    setRole(null);
  }, []);

  useEffect(() => {
    if (token) {
      authApi.me()
        .then(res => setUser(res.data.data))
        .catch(logout)
        .finally(() => setLoading(false));
    } else {
      setLoading(false);
    }
  }, [token, logout]);

  const login = (data) => {
    localStorage.setItem('token', data.token);
    localStorage.setItem('role', data.role);
    setToken(data.token);
    setRole(data.role);
  };

  return (
    <AuthContext.Provider value={{ user, token, role, login, logout, loading }}>
      {children}
    </AuthContext.Provider>
  );
};

export const useAuth = () => useContext(AuthContext);