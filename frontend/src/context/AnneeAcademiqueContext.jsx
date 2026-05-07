import { createContext, useContext, useState, useEffect, useMemo, useCallback } from 'react';
import apiClient from '../api/apiClient';

const AnneeAcademiqueContext = createContext(null);

export function AnneeAcademiqueProvider({ children }) {
  const [currentAnnee, setCurrentAnnee] = useState(null);
  const [annees, setAnnees] = useState([]);
  const [loading, setLoading] = useState(true);
  const [refreshTrigger, setRefreshTrigger] = useState(0);
  const [warning, setWarning] = useState(null);

  const fetchAnnees = useCallback(() => {
    const token = localStorage.getItem('token');
    const role = localStorage.getItem('role');
    if (!token) {
      setLoading(false);
      return;
    }
    setLoading(true);
    setWarning(null);

    if (role === 'admin') {
      apiClient.get('/admin/annees-academiques')
        .then(res => {
          const anneesData = res.data.data || [];
          setAnnees(anneesData);
          const current = anneesData.find(a => a.is_current) || null;
          setCurrentAnnee(current);
          setLoading(false);
          apiClient.get('/annee-academique/current')
            .then(res => {
              if (res?.data?.warning) setWarning(res.data.warning);
            })
            .catch(() => {});
        })
        .catch((err) => {
          console.error('Failed to fetch academic years:', err);
          setLoading(false);
        });
    } else {
      apiClient.get('/annee-academique/current')
        .then(res => {
          const annee = res.data.data || null;
          setCurrentAnnee(annee);
          setAnnees(annee ? [annee] : []);
          if (res?.data?.warning) setWarning(res.data.warning);
          setLoading(false);
        })
        .catch(() => {
          setLoading(false);
        });
    }
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
    refreshAnnees,
    warning,
  }), [currentAnnee, annees, loading, refreshAnnees, warning]);

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
