import { useState, useEffect } from 'react';
import { useNavigate } from 'react-router-dom';
import { adminApi } from '../../api/admin';
import { etudiantsApi } from '../../api/etudiants';
import ActivityLog from '../../components/common/ActivityLog';
import { useToast } from '../../context/ToastContext';
import { useAnneeAcademique } from '../../context/AnneeAcademiqueContext';
import handleApiError from '../../utils/errorHandler';
import { SkeletonDashboard } from '../../components/common/Skeleton';
import '../../css/components.css';
import '../../css/layout.css';

const STAT_CONFIGS = [
  { title: 'Etudiants', key: 'totalStudents', color: '#3b82f6', bg: '#eff6ff', link: '/admin/etudiants' },
  { title: 'Filieres', key: 'totalFilieres', color: '#10b981', bg: '#ecfdf5', link: '/admin/filieres-groupes' },
  { title: 'Formateurs', key: 'totalFormateurs', color: '#f59e0b', bg: '#fffbeb', link: '/admin/formateurs' },
  { title: 'Annee en cours', key: 'currentAcademicYear', color: '#8b5cf6', bg: '#f5f3ff', link: '/admin/annees' },
];

const QUICK_ACTIONS = [
  { label: 'Saisir des notes', link: '/admin/notes', primary: true },
  { label: 'Gerer les publications', link: '/admin/publications', primary: false },
  { label: 'Consulter bulletins', link: '/admin/bulletins', primary: false },
  { label: 'Gerer etudiants', link: '/admin/etudiants', primary: false },
];

export default function Dashboard() {
  const [stats, setStats] = useState({
    totalStudents: 0,
    totalFilieres: 0,
    currentAcademicYear: '\u2014',
    totalFormateurs: 0,
  });
  const [activities, setActivities] = useState([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);
  const toast = useToast();
  const navigate = useNavigate();
  const { currentAnnee, loading: anneeLoading } = useAnneeAcademique();

  useEffect(() => {
    let cancelled = false;

    const load = async () => {
      if (anneeLoading) return;
      if (!currentAnnee) {
        setLoading(false);
        return;
      }

      setLoading(true);
      setError(null);

      try {
        const results = await Promise.allSettled([
          etudiantsApi.getEtudiants({ annee_academique_id: currentAnnee.id }),
          adminApi.getFilieres(),
          adminApi.getFormateurs(),
          adminApi.getActivityLogs(),
        ]);

        if (cancelled) return;

        setStats({
          totalStudents: results[0].status === 'fulfilled' ? results[0].value.data.data.length : 0,
          totalFilieres: results[1].status === 'fulfilled' ? results[1].value.data.data.length : 0,
          currentAcademicYear: currentAnnee ? currentAnnee.label : '\u2014',
          totalFormateurs: results[2].status === 'fulfilled' ? results[2].value.data.data.length : 0,
        });

        if (results[3].status === 'fulfilled') {
          setActivities(results[3].value.data.data || []);
        }
      } catch (err) {
        if (cancelled) return;
        const errorInfo = handleApiError(err, toast, { showToast: false });
        setError(errorInfo.message);
      } finally {
        if (!cancelled) {
          setLoading(false);
        }
      }
    };

    load();

    return () => {
      cancelled = true;
    };
  }, [currentAnnee, anneeLoading]);

  if (loading) return <SkeletonDashboard />;

  if (error) {
    return (
      <div className="dashboard-error">
        <div className="dashboard-error-icon">!</div>
        <h4 className="dashboard-error-title">Erreur de chargement</h4>
        <p className="dashboard-error-msg">{error}</p>
        <button className="btn btn-primary" onClick={() => {
          setError(null);
          setLoading(true);
        }}>Réessayer</button>
      </div>
    );
  }

  return (
    <div>
      <h2 className="page-title dashboard-title">Tableau de bord</h2>

      <div className="stats-grid">
        {STAT_CONFIGS.map((stat, i) => (
          <div
            key={i}
            className="stat-card"
            style={{ background: stat.bg, border: `1px solid ${stat.color}20` }}
            onClick={() => navigate(stat.link)}
          >
            <div className="stat-card-header">
              <div>
                <p className="stat-card-label">{stat.title}</p>
                <h3 className="stat-card-value" style={{ color: stat.color }}>{stats[stat.key]}</h3>
              </div>
            </div>
          </div>
        ))}
      </div>

      <div className="card dashboard-quick-actions">
        <h5 className="dashboard-quick-title">Actions rapides</h5>
        <div className="dashboard-quick-buttons">
          {QUICK_ACTIONS.map((action, i) => (
            <button
              key={i}
              className={action.primary ? 'btn btn-primary' : 'btn btn-outline'}
              onClick={() => navigate(action.link)}
            >
              {action.label}
            </button>
          ))}
        </div>
      </div>

      <div className="card dashboard-activity">
        <ActivityLog entries={activities} limit={8} />
      </div>
    </div>
  );
}
