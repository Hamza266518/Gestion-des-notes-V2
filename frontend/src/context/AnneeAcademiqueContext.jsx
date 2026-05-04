import { createContext, useContext, useState, useEffect, useMemo, useCallback } from 'react';
import apiClient from '../api/apiClient';

const AnneeAcademiqueContext = createContext(null);

export function AnneeAcademiqueProvider({ children }) {
  const [currentAnnee, setCurrentAnnee] = useState(null);
  const [annees, setAnnees] = useState([]);
  const [loading, setLoading] = useState(true);
  const [refreshTrigger, setRefreshTrigger] = useState(0);

  const fetchAnnees = useCallback(() => {
    const token = localStorage.getItem('token');
    if (!token) {
      setLoading(false);
      return Promise.resolve();
    }
    setLoading(true);
    return apiClient.get('/annee-academique/current')
      .then(res => {
        const data = res.data.data;
        setCurrentAnnee(data || null);
        setAnnees(data ? [data] : []);
      })
      .catch((err) => {
        console.error('Failed to fetch current academic year:', err);
        if (err.response?.status === 401) {
          localStorage.removeItem('token');
          localStorage.removeItem('role');
          window.location.href = '/login';
        }
      })
      .finally(() => {
        setLoading(false);
      });
  }, []);

  useEffect(() => {
    fetchAnnees();
  }, [refreshTrigger]);

  const refreshAnnees = useCallback(() => {
    setRefreshTrigger(prev => prev + 1);
  }, []);

  const value = useMemo(() => ({
    currentAnnee,
    annees,
    loading,
    refreshAnnees
  }), [currentAnnee, annees, loading, refreshAnnees]);

  return (
    <AnneeAcademiqueContext.Provider value={value}>
      {children}
    </AnneeAcademiqueContext.Provider>
  );
}

export function useAnneeAcademique() {
  const context = useContext(AnneeAcademiqueContext);
  if (!context) {
    throw new Error('useAnneeAcademique must be used inside an AnneeAcademiqueProvider');
  }
  return context;
}
