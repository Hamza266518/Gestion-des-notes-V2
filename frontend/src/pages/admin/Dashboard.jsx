import { useState, useEffect } from 'react';
import { useNavigate } from 'react-router-dom';
import { adminApi } from '../../api/admin';
import { etudiantsApi } from '../../api/etudiants';
import { useToast } from '../../context/ToastContext';
import { useAnneeAcademique } from '../../context/AnneeAcademiqueContext';
import handleApiError from '../../utils/errorHandler';
import Spinner from '../../components/common/Spinner';
import { FiUsers, FiBookOpen, FiUserCheck, FiCalendar, FiAlertCircle, FiChevronDown, FiChevronUp, FiCheckCircle, FiClock } from 'react-icons/fi';
import '../../css/components.css';
import '../../css/layout.css';

const STAT_CONFIGS = [
  { title: 'Etudiants', key: 'totalStudents', color: '#3b82f6', bg: '#eff6ff', link: '/admin/etudiants', icon: FiUsers },
  { title: 'Filieres', key: 'totalFilieres', color: '#10b981', bg: '#ecfdf5', link: '/admin/filieres-groupes', icon: FiBookOpen },
  { title: 'Formateurs', key: 'totalFormateurs', color: '#f59e0b', bg: '#fffbeb', link: '/admin/formateurs', icon: FiUserCheck },
  { title: 'Annee en cours', key: 'currentAcademicYear', color: '#8b5cf6', bg: '#f5f3ff', link: '/admin/annees', icon: FiCalendar },
];

const QUICK_ACTIONS = [
  { label: 'Saisir des notes', link: '/admin/notes', color: 'var(--primary)' },
  { label: 'Publications', link: '/admin/publications', color: 'var(--accent)' },
  { label: 'Bulletins', link: '/admin/bulletins', color: '#8b5cf6' },
  { label: 'Étudiants', link: '/admin/etudiants', color: '#3b82f6' },
  { label: 'Journal', link: '/admin/journal', color: '#f59e0b' },
];

export default function Dashboard() {
  const [stats, setStats] = useState({
    totalStudents: 0,
    totalFilieres: 0,
    currentAcademicYear: '—',
    totalFormateurs: 0,
  });
  const [filiereStats, setFiliereStats] = useState([]);
  const [pendingControles, setPendingControles] = useState([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);
  const [expandedSection, setExpandedSection] = useState(null);
  const [showAllPending, setShowAllPending] = useState(false);
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
          adminApi.getDashboardStats({ annee_academique_id: currentAnnee.id }),
          adminApi.getControlesPending({ annee_academique_id: currentAnnee.id, limit: 50 }),
        ]);

        if (cancelled) return;

        setStats({
          totalStudents: results[0].status === 'fulfilled' ? results[0].value.data.data.length : 0,
          totalFilieres: results[1].status === 'fulfilled' ? results[1].value.data.data.length : 0,
          currentAcademicYear: currentAnnee ? currentAnnee.label : '—',
          totalFormateurs: results[2].status === 'fulfilled' ? results[2].value.data.data.length : 0,
        });

        if (results[3].status === 'fulfilled') {
          setFiliereStats(results[3].value.data.data || []);
        }

        if (results[4].status === 'fulfilled') {
          setPendingControles(results[4].value.data.data || []);
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

  if (loading) return (
    <div className="page" style={{ display: 'flex', alignItems: 'center', justifyContent: 'center', minHeight: '60vh' }}>
      <div className="text-center">
        <Spinner />
      </div>
    </div>
  );

  if (error) {
    return (
      <div className="dashboard-error">
        <div className="dashboard-error-icon">!</div>
        <h4 className="dashboard-error-title">Erreur de chargement</h4>
        <p className="dashboard-error-msg">{error}</p>
        <button className="btn btn-primary" onClick={() => { setError(null); setLoading(true); }}>Réessayer</button>
      </div>
    );
  }

  const pendingCount = pendingControles.reduce((s, f) => s + f.total_pending_controles, 0);
  const missingCount = pendingControles.reduce((s, f) => s + f.total_missing_notes, 0);

  return (
    <div>
      <div className="stats-grid">
        {STAT_CONFIGS.map((stat, i) => {
          const Icon = stat.icon;
          return (
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
                <div className="stat-icon" style={{ background: `${stat.color}15`, color: stat.color }}>
                  <Icon size={22} />
                </div>
              </div>
            </div>
          );
        })}
      </div>

      <div style={{ display: 'flex', gap: 10, marginBottom: 20, flexWrap: 'wrap' }}>
        {QUICK_ACTIONS.map((a, i) => (
          <button
            key={i}
            className="btn btn-sm"
            style={{
              background: `${a.color}10`,
              color: a.color,
              border: `1px solid ${a.color}30`,
              fontWeight: 600,
              fontSize: 12.5,
            }}
            onClick={() => navigate(a.link)}
          >
            {a.label}
          </button>
        ))}
      </div>

      {pendingCount > 0 && (
        <div className="card" style={{ marginBottom: 20, borderLeft: `3px solid #f59e0b` }}>
          <div className="card-header" style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
            <h5 className="mb-0" style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
              <FiAlertCircle color="#f59e0b" size={18} />
              Contrôles en attente
              <span style={{ fontSize: 12, fontWeight: 400, color: 'var(--gray-500)' }}>
                {pendingCount} contrôle(s) — {missingCount} note(s) manquante(s)
              </span>
            </h5>
          </div>
          <div className="card-body p-0">
            {pendingControles.map(f => f.items.length > 0 && (
              <div key={f.filiere_id} style={{ borderBottom: '1px solid var(--gray-100)' }}>
                <div
                  style={{
                    padding: '10px 16px',
                    display: 'flex',
                    alignItems: 'center',
                    justifyContent: 'space-between',
                    cursor: 'pointer',
                    background: 'var(--gray-50)',
                  }}
                  onClick={() => setExpandedSection(expandedSection === `pending-${f.filiere_id}` ? null : `pending-${f.filiere_id}`)}
                >
                  <div style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
                    <strong style={{ fontSize: 13 }}>{f.filiere_nom}</strong>
                    <span style={{ fontSize: 11, color: '#f59e0b', background: '#fffbeb', padding: '1px 8px', borderRadius: 8 }}>
                      {f.total_pending_controles} en attente
                    </span>
                    <span style={{ fontSize: 11, color: '#dc2626', background: '#fef2f2', padding: '1px 8px', borderRadius: 8 }}>
                      {f.total_missing_notes} manquantes
                    </span>
                  </div>
                  {expandedSection === `pending-${f.filiere_id}` ? <FiChevronUp size={16} /> : <FiChevronDown size={16} />}
                </div>

                {expandedSection === `pending-${f.filiere_id}` && (
                  <div style={{ padding: '8px 16px 12px' }}>
                    {(showAllPending ? f.items : f.items.slice(0, 5)).map((item, idx) => (
                      <div key={idx} style={{
                        display: 'flex',
                        alignItems: 'center',
                        gap: 10,
                        padding: '6px 0',
                        borderBottom: '1px solid var(--gray-100)',
                        fontSize: 12.5,
                      }}>
                        <FiClock size={12} color="var(--gray-400)" />
                        <span style={{ color: 'var(--gray-600)', minWidth: 80 }}>{item.unite_nom}</span>
                        <span style={{ color: 'var(--gray-600)', minWidth: 60 }}>{item.sequence_nom}</span>
                        <span style={{ fontWeight: 600, minWidth: 30 }}>C{item.controle_numero}</span>
                        <span style={{ color: item.missing_count > 0 ? '#dc2626' : '#059669', fontWeight: 500 }}>
                          {item.notes_entered}/{item.total_expected}
                        </span>
                        {item.missing_students.length > 0 && (
                          <span style={{ color: 'var(--gray-500)' }}>
                            — {item.missing_students.slice(0, 3).map(s => s.nom_prenom).join(', ')}
                            {item.missing_students.length > 3 && ` +${item.missing_students.length - 3}`}
                          </span>
                        )}
                      </div>
                    ))}
                    {f.items.length > 5 && (
                      <button
                        className="btn btn-sm btn-link"
                        style={{ fontSize: 12, marginTop: 4 }}
                        onClick={() => setShowAllPending(!showAllPending)}
                      >
                        {showAllPending ? 'Voir moins' : `Voir les ${f.items.length} controles`}
                      </button>
                    )}
                  </div>
                )}
              </div>
            ))}
          </div>
        </div>
      )}

      {filiereStats.length > 0 && (
        <div className="card" style={{ marginBottom: 24 }}>
          <div className="card-header">
            <h5 className="mb-0" style={{ display: 'flex', alignItems: 'center', gap: 6 }}>
              <FiCheckCircle color="var(--primary)" size={18} />
              Progression par filière
            </h5>
          </div>
          <div className="card-body p-0">
            {filiereStats.map((f) => {
              const pct = f.saisie_pct || 0;
              return (
                <div key={f.filiere_id}>
                  <div
                    style={{
                      padding: '10px 16px',
                      borderBottom: '1px solid var(--gray-200)',
                      cursor: 'pointer',
                      display: 'flex',
                      alignItems: 'center',
                      gap: 12,
                      flexWrap: 'wrap',
                    }}
                    onClick={() => setExpandedSection(expandedSection === `progress-${f.filiere_id}` ? null : `progress-${f.filiere_id}`)}
                  >
                    <div style={{ flex: '1 1 140px', fontWeight: 600, fontSize: 13, color: 'var(--gray-800)' }}>
                      {f.filiere_nom}
                    </div>

                    <div style={{ display: 'flex', gap: 8, alignItems: 'center', flex: '0 0 auto' }}>
                      <span style={{ fontSize: 12, padding: '2px 8px', borderRadius: 8, background: '#ecfdf5', color: '#059669', whiteSpace: 'nowrap' }}>
                        {f.avec_notes} saisis
                      </span>
                      {f.sans_notes > 0 && (
                        <span style={{ fontSize: 12, padding: '2px 8px', borderRadius: 8, background: '#fef2f2', color: '#dc2626', whiteSpace: 'nowrap' }}>
                          {f.sans_notes} vides
                        </span>
                      )}
                    </div>

                    <div style={{ flex: '1 1 140px', minWidth: 100 }}>
                      <div style={{ height: 8, background: 'var(--gray-200)', borderRadius: 4, overflow: 'hidden', display: 'flex' }}>
                        {f.avec_notes > 0 && (
                          <div style={{
                            height: '100%',
                            width: `${(f.avec_notes / f.total_controles) * 100}%`,
                            background: '#059669',
                            minWidth: f.avec_notes > 0 ? 4 : 0,
                          }} />
                        )}
                      </div>
                      <div style={{ fontSize: 10, color: 'var(--gray-500)', marginTop: 2, textAlign: 'right' }}>
                        {pct}% — {f.total_controles} contrôle(s)
                      </div>
                    </div>

                    <div style={{ display: 'flex', gap: 6, flexWrap: 'wrap' }}>
                      <span style={{ fontSize: 11, padding: '1px 7px', borderRadius: 8, background: '#ecfdf5', color: '#059669' }}>
                        {f.active_students} actifs
                      </span>
                      {f.graduate_students > 0 && (
                        <span style={{ fontSize: 11, padding: '1px 7px', borderRadius: 8, background: '#eff6ff', color: '#2563eb' }}>
                          {f.graduate_students} diplômés
                        </span>
                      )}
                      <span style={{ fontSize: 11, padding: '1px 7px', borderRadius: 8, background: '#f5f5f5', color: 'var(--gray-500)' }}>
                        {f.total_students} étudiants
                      </span>
                    </div>
                  </div>

                  {expandedSection === `progress-${f.filiere_id}` && (
                    <div style={{ padding: '8px 16px 12px', background: 'var(--gray-50)', borderBottom: '1px solid var(--gray-200)' }}>
                      <table style={{ width: '100%', fontSize: 12.5, borderCollapse: 'collapse' }}>
                        <thead>
                          <tr style={{ borderBottom: '1px solid var(--gray-200)' }}>
                            <th style={{ textAlign: 'left', padding: '6px 8px', color: 'var(--gray-500)', fontWeight: 600 }}>Groupe</th>
                            <th style={{ textAlign: 'center', padding: '6px 8px', color: 'var(--gray-500)', fontWeight: 600 }}>Étudiants</th>
                            <th style={{ textAlign: 'center', padding: '6px 8px', color: 'var(--gray-500)', fontWeight: 600 }}>Saisis</th>
                            <th style={{ textAlign: 'center', padding: '6px 8px', color: 'var(--gray-500)', fontWeight: 600 }}>Vides</th>
                            <th style={{ textAlign: 'left', padding: '6px 8px', color: 'var(--gray-500)', fontWeight: 600 }}>Barre</th>
                          </tr>
                        </thead>
                        <tbody>
                          {(f.groupes || []).map((g) => {
                            const gPct = g.total_controles > 0 ? Math.round((g.avec_notes / g.total_controles) * 100) : 0;
                            return (
                              <tr key={g.id} style={{ borderBottom: '1px solid var(--gray-100)' }}>
                                <td style={{ padding: '6px 8px', fontWeight: 500 }}>{g.nom}</td>
                                <td style={{ padding: '6px 8px', textAlign: 'center' }}>{g.etudiants_count}</td>
                                <td style={{ padding: '6px 8px', textAlign: 'center', color: '#059669' }}>{g.avec_notes}</td>
                                <td style={{ padding: '6px 8px', textAlign: 'center', color: '#dc2626' }}>{g.sans_notes}</td>
                                <td style={{ padding: '6px 8px' }}>
                                  <div style={{ display: 'flex', alignItems: 'center', gap: 6 }}>
                                    <div style={{
                                      flex: 1,
                                      height: 6,
                                      background: 'var(--gray-200)',
                                      borderRadius: 3,
                                      overflow: 'hidden',
                                      display: 'flex',
                                      maxWidth: 100,
                                    }}>
                                      {g.avec_notes > 0 && (
                                        <div style={{
                                          height: '100%',
                                          width: `${(g.avec_notes / g.total_controles) * 100}%`,
                                          background: '#059669',
                                          minWidth: g.avec_notes > 0 ? 3 : 0,
                                        }} />
                                      )}
                                    </div>
                                    <span style={{ fontSize: 10, color: 'var(--gray-500)', minWidth: 24, textAlign: 'right' }}>
                                      {gPct}%
                                    </span>
                                  </div>
                                </td>
                              </tr>
                            );
                          })}
                        </tbody>
                      </table>
                    </div>
                  )}
                </div>
              );
            })}
          </div>
        </div>
      )}
    </div>
  );
}
