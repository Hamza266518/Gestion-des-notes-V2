import { useState, useEffect, useCallback } from 'react';
import { adminApi } from '../../api/admin';
import { etudiantsApi } from '../../api/etudiants';
import { useToast } from '../../context/ToastContext';
import handleApiError from '../../utils/errorHandler';
import '../../css/components.css';
import '../../css/layout.css';

export default function Dashboard() {
  const [stats, setStats] = useState({
    totalStudents: 0,
    totalFilieres: 0,
    currentAcademicYear: '',
    totalFormateurs: 0,
    totalGroupes: 0,
    totalUnites: 0,
  });
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);
  const toast = useToast();

  const loadStats = useCallback(async () => {
    setLoading(true);
    setError(null);

    try {
      const results = await Promise.allSettled([
        etudiantsApi.getEtudiants(),
        adminApi.getFilieres(),
        adminApi.getAnnees(),
        adminApi.getFormateurs(),
      ]);

      const studentsRes = results[0];
      const filieresRes = results[1];
      const anneesRes = results[2];
      const formateursRes = results[3];

      const currentYear = anneesRes.status === 'fulfilled'
        ? anneesRes.value.data.data.find(year => year.is_current === true)
        : null;

      setStats({
        totalStudents: studentsRes.status === 'fulfilled' ? studentsRes.value.data.data.length : 0,
        totalFilieres: filieresRes.status === 'fulfilled' ? filieresRes.value.data.data.length : 0,
        currentAcademicYear: currentYear ? currentYear.label : '—',
        totalFormateurs: formateursRes.status === 'fulfilled' ? formateursRes.value.data.data.length : 0,
        totalGroupes: 0,
        totalUnites: 0,
      });
    } catch (err) {
      console.error('Error fetching dashboard data:', err);
      const errorInfo = handleApiError(err, toast, { showToast: false });
      setError(errorInfo.message);
    } finally {
      setLoading(false);
    }
  }, [toast]);

  useEffect(() => {
    loadStats();
  }, [loadStats]);

  if (loading) {
    return (
      <div className="text-center mt-5">
        <p className="text-muted">Chargement du tableau de bord...</p>
      </div>
    );
  }

  if (error) {
    return (
      <div className="text-center mt-5" style={{ maxWidth: 500, margin: '100px auto' }}>
        <div style={{ fontSize: 48, marginBottom: 16 }}>⚠️</div>
        <h4 style={{ color: '#dc2626', marginBottom: 12 }}>Erreur de chargement</h4>
        <p style={{ color: '#666', marginBottom: 24 }}>{error}</p>
        <button className="btn btn-primary" onClick={loadStats}>Réessayer</button>
      </div>
    );
  }

  const statCards = [
    {
      title: 'Étudiants',
      value: stats.totalStudents,
      color: '#3b82f6',
      bg: '#eff6ff',
      icon: '🎓',
      link: '/etudiants'
    },
    {
      title: 'Filières',
      value: stats.totalFilieres,
      color: '#10b981',
      bg: '#ecfdf5',
      icon: '📚',
      link: '/filieres'
    },
    {
      title: 'Formateurs',
      value: stats.totalFormateurs,
      color: '#f59e0b',
      bg: '#fffbeb',
      icon: '👨‍🏫',
      link: '/formateurs'
    },
    {
      title: 'Année en cours',
      value: stats.currentAcademicYear,
      color: '#8b5cf6',
      bg: '#f5f3ff',
      icon: '📅',
      link: '/annees-academiques'
    },
  ];

  return (
    <div>
      <h2 className="page-title" style={{ marginBottom: 24 }}>Tableau de bord</h2>

      <div style={{
        display: 'grid',
        gridTemplateColumns: 'repeat(auto-fit, minmax(220px, 1fr))',
        gap: 16,
        marginBottom: 32
      }}>
        {statCards.map((stat, i) => (
          <div
            key={i}
            style={{
              background: stat.bg,
              borderRadius: 12,
              padding: 20,
              cursor: stat.link ? 'pointer' : 'default',
              border: `1px solid ${stat.color}20`,
            }}
            onClick={() => { if (stat.link) window.location.href = stat.link; }}
          >
            <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'flex-start' }}>
              <div>
                <p style={{ margin: 0, fontSize: 13, color: '#666', fontWeight: 500 }}>{stat.title}</p>
                <h3 style={{ margin: '8px 0 0', fontSize: 28, color: stat.color, fontWeight: 700 }}>{stat.value}</h3>
              </div>
              <span style={{ fontSize: 32 }}>{stat.icon}</span>
            </div>
          </div>
        ))}
      </div>

      <div style={{
        background: '#fff',
        borderRadius: 12,
        border: '1px solid #e5e7eb',
        padding: 20
      }}>
        <h5 style={{ margin: '0 0 16px', fontSize: 16, fontWeight: 600 }}>Actions rapides</h5>
        <div style={{ display: 'flex', gap: 12, flexWrap: 'wrap' }}>
          <button className="btn btn-primary" onClick={() => window.location.href = '/etudiants'}>
            Scanner des CIN
          </button>
          <button className="btn btn-outline" onClick={() => window.location.href = '/unites'}>
            Gérer les unités
          </button>
          <button className="btn btn-outline" onClick={() => window.location.href = '/notes'}>
            Saisir des notes
          </button>
        </div>
      </div>
    </div>
  );
}
