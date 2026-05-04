import { createContext, useContext, useState, useEffect, useCallback } from 'react';

const STORAGE_KEY = 'ifp_grades';

const GradesContext = createContext(null);

const loadGrades = () => {
  try {
    const raw = localStorage.getItem(STORAGE_KEY);
    return raw ? JSON.parse(raw) : [];
  } catch {
    return [];
  }
};

export const GradesProvider = ({ children }) => {
  const [grades, setGrades] = useState(loadGrades);

  useEffect(() => {
    localStorage.setItem(STORAGE_KEY, JSON.stringify(grades));
  }, [grades]);

  const addGrades = useCallback((newGrades, context) => {
    const entries = newGrades.map((g, i) => ({
      id: `${Date.now()}-${i}-${Math.random().toString(36).slice(2, 8)}`,
      name: g.name,
      grade: g.grade,
      filiere: context.filiere,
      annee: context.annee,
      groupe: context.groupe,
      matiere: context.matiere,
      controle: context.controle,
      createdAt: new Date().toISOString(),
    }));
    setGrades((prev) => [...prev, ...entries]);
    return entries.length;
  }, []);

  const getFilteredGrades = useCallback(
    (filters) => {
      return grades.filter((g) => {
        if (filters.filiere && g.filiere !== filters.filiere) return false;
        if (filters.annee && g.annee !== filters.annee) return false;
        if (filters.groupe && g.groupe !== filters.groupe) return false;
        if (filters.matiere && g.matiere !== filters.matiere) return false;
        if (filters.controle && g.controle !== filters.controle) return false;
        return true;
      });
    },
    [grades]
  );

  return (
    <GradesContext.Provider value={{ grades, addGrades, getFilteredGrades }}>
      {children}
    </GradesContext.Provider>
  );
};

export const useGrades = () => {
  const context = useContext(GradesContext);
  if (!context) {
    throw new Error('useGrades must be used inside a GradesProvider');
  }
  return context;
};
