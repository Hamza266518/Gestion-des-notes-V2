import { useState, useEffect } from 'react';
import { useNavigate } from 'react-router-dom';
import { adminApi } from '../../api/admin';
import { etudiantsApi } from '../../api/etudiants';
import { useToast } from '../../context/ToastContext';
import { useAnneeAcademique } from '../../context/AnneeAcademiqueContext';
import handleApiError from '../../utils/errorHandler';
import Spinner from '../../components/common/Spinner';
import { FiUsers, FiBookOpen, FiUserCheck, FiCalendar, FiAlertCircle, FiChevronRight, FiCheckCircle, FiClock, FiEdit, FiFileText, FiAward, FiSend } from 'react-icons/fi';
import '../../css/components.css';
import '../../css/layout.css';

const STAT_CARDS = [
  { title: 'Étudiants', key: 'totalStudents', color: '#2563eb', bg: '#eff6ff', link: '/admin/etudiants', icon: FiUsers },
  { title: 'Filières', key: 'totalFilieres', color: '#059669', bg: '#ecfdf5', link: '/admin/filieres-groupes', icon: FiBookOpen },
  { title: 'Formateurs', key: 'totalFormateurs', color: '#d97706', bg: '#fffbeb', link: '/admin/formateurs', icon: FiUserCheck },
  { title: 'Année en cours', key: 'currentAcademicYear', color: '#7c3aed', bg: '#f5f3ff', link: '/admin/annees', icon: FiCalendar },
];

const QUICK_TOOLS = [
  { label: 'Saisie des notes', desc: 'Saisir ou modifier les notes', link: '/admin/saisie-notes', icon: FiEdit, color: '#2563eb' },
  { label: 'Publications', desc: 'Publier les notes et bulletins', link: '/admin/publications', icon: FiSend, color: '#059669' },
  { label: 'Bulletins', desc: 'Générer les bulletins individuels', link: '/admin/bulletins', icon: FiFileText, color: '#7c3aed' },
  { label: 'Relevé des notes', desc: 'Consulter les relevés par groupe', link: '/admin/notes', icon: FiCheckCircle, color: '#d97706' },
  { label: 'Diplômes', desc: 'Générer les diplômes', link: '/admin/diplomes', icon: FiAward, color: '#dc2626' },
];

export default function Dashboard() {
  const [stats, setStats] = useState({ totalStudents: 0, totalFilieres: 0, totalFormateurs: 0, currentAcademicYear: '—' });
  const [filiereStats, setFiliereStats] = useState([]);
  const [pendingControles, setPendingControles] = useState([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);
  const toast = useToast();
  const navigate = useNavigate();
  const { currentAnnee, loading: anneeLoading } = useAnneeAcademique();

  useEffect(() => {
    let cancelled = false;
    const load = async () => {
      if (anneeLoading) return;
      if (!currentAnnee) { setLoading(false); return; }
      setLoading(true); setError(null);
      try {
        const [etudiantsRes, filieresRes, formateursRes, statsRes, pendingRes] = await Promise.all([
          etudiantsApi.getEtudiants({ annee_academique_id: currentAnnee.id }),
          adminApi.getFilieres(),
          adminApi.getFormateurs(),
          adminApi.getDashboardStats({ annee_academique_id: currentAnnee.id }),
          adminApi.getControlesPending({ annee_academique_id: currentAnnee.id, limit: 50 }),
        ]);
        if (cancelled) return;
        setStats({
          totalStudents: etudiantsRes.data.data.length,
          totalFilieres: filieresRes.data.data.length,
          totalFormateurs: formateursRes.data.data.length,
          currentAcademicYear: currentAnnee.label,
        });
        if (statsRes.status === 'fulfilled') setFiliereStats(statsRes.value.data.data || []);
        else setFiliereStats(statsRes.data.data || []);
        if (pendingRes.status === 'fulfilled') setPendingControles(pendingRes.value.data.data || []);
        else setPendingControles(pendingRes.data.data || []);
      } catch (err) {
        if (cancelled) return;
        const info = handleApiError(err, toast, { showToast: false });
        setError(info.message);
      } finally { if (!cancelled) setLoading(false); }
    };
    load();
    return () => { cancelled = true; };
  }, [currentAnnee, anneeLoading]);

  if (loading) return <div className="page" style={{ display: 'flex', alignItems: 'center', justifyContent: 'center', minHeight: '60vh' }}><Spinner /></div>;
  if (error) return (
    <div style={{ textAlign: 'center', padding: 60 }}>
      <div style={{ fontSize: 48, marginBottom: 12 }}>⚠️</div>
      <h3>Erreur de chargement</h3>
      <p style={{ color: 'var(--gray-500)', marginBottom: 16 }}>{error}</p>
      <button className="btn btn-primary" onClick={() => { setError(null); setLoading(true); }}>Réessayer</button>
    </div>
  );

  const pendingCount = pendingControles.reduce((s, f) => s + f.total_pending_controles, 0);
  const missingCount = pendingControles.reduce((s, f) => s + f.total_missing_notes, 0);
  const topPending = pendingControles.flatMap(f =>
    (f.items || []).map(i => ({ ...i, filiere_nom: f.filiere_nom }))
  ).sort((a, b) => b.missing_count - a.missing_count).slice(0, 8);

  return (
    <div>

      <div className="stats-grid" style={{ gridTemplateColumns: 'repeat(4, 1fr)' }}>
        {STAT_CARDS.map((s, i) => {
          const Icon = s.icon;
          return (
            <div key={i} className="stat-card" style={{ border: 'none', boxShadow: '0 1px 3px rgba(0,0,0,0.08)', padding: 18, cursor: 'pointer' }} onClick={() => navigate(s.link)}>
              <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'flex-start' }}>
                <div>
                  <div className="stat-card-label" style={{ fontSize: 11, fontWeight: 600, textTransform: 'uppercase', letterSpacing: '0.05em', color: 'var(--gray-400)' }}>{s.title}</div>
                  <div className="stat-card-value" style={{ fontSize: 26, color: s.color, marginTop: 4 }}>{stats[s.key]}</div>
                </div>
                <div style={{ width: 36, height: 36, borderRadius: 10, background: s.bg, display: 'flex', alignItems: 'center', justifyContent: 'center', color: s.color }}>
                  <Icon size={18} />
                </div>
              </div>
            </div>
          );
        })}
      </div>

      <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 20, marginBottom: 24 }}>
        <div className="card" style={{ border: 'none', boxShadow: '0 1px 3px rgba(0,0,0,0.08)' }}>
          <div className="card-header" style={{ padding: '14px 18px', borderBottom: '1px solid var(--gray-100)' }}>
            <h5 style={{ margin: 0, fontSize: 13, fontWeight: 700, display: 'flex', alignItems: 'center', gap: 6 }}>
              <FiEdit size={14} color="var(--primary)" />
              Accès rapide
            </h5>
          </div>
          <div className="card-body" style={{ padding: 12 }}>
            <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 8 }}>
              {QUICK_TOOLS.map((t, i) => {
                const Icon = t.icon;
                return (
                  <button key={i} onClick={() => navigate(t.link)}
                    style={{
                      display: 'flex', alignItems: 'center', gap: 10,
                      padding: '10px 12px', borderRadius: 8, border: '1px solid var(--gray-200)',
                      background: 'var(--white)', cursor: 'pointer', textAlign: 'left',
                      transition: 'all 0.15s', fontFamily: 'var(--font)',
                    }}
                    onMouseEnter={e => { e.currentTarget.style.borderColor = t.color; e.currentTarget.style.background = `${t.color}06`; }}
                    onMouseLeave={e => { e.currentTarget.style.borderColor = 'var(--gray-200)'; e.currentTarget.style.background = 'var(--white)'; }}
                  >
                    <div style={{ width: 32, height: 32, borderRadius: 8, background: `${t.color}12`, display: 'flex', alignItems: 'center', justifyContent: 'center', color: t.color, flexShrink: 0 }}>
                      <Icon size={15} />
                    </div>
                    <div style={{ minWidth: 0 }}>
                      <div style={{ fontSize: 12.5, fontWeight: 600, color: 'var(--gray-800)' }}>{t.label}</div>
                      <div style={{ fontSize: 11, color: 'var(--gray-400)', marginTop: 1 }}>{t.desc}</div>
                    </div>
                  </button>
                );
              })}
            </div>
          </div>
        </div>

        <div className="card" style={{ border: 'none', boxShadow: '0 1px 3px rgba(0,0,0,0.08)' }}>
          <div className="card-header" style={{ padding: '14px 18px', borderBottom: '1px solid var(--gray-100)' }}>
            <h5 style={{ margin: 0, fontSize: 13, fontWeight: 700, display: 'flex', alignItems: 'center', gap: 6 }}>
              <FiClock size={14} color={pendingCount > 0 ? '#d97706' : 'var(--gray-400)'} />
              Contrôles en attente
              {pendingCount > 0 && (
                <span style={{ fontSize: 11, fontWeight: 600, background: '#fef3c7', color: '#92400e', padding: '0 8px', borderRadius: 8, marginLeft: 4 }}>
                  {pendingCount}
                </span>
              )}
            </h5>
          </div>
          <div className="card-body" style={{ padding: pendingCount === 0 ? '24px 18px' : '8px 12px' }}>
            {pendingCount === 0 ? (
              <div style={{ textAlign: 'center' }}>
                <FiCheckCircle size={32} color="var(--gray-300)" style={{ marginBottom: 8 }} />
                <div style={{ fontSize: 13, color: 'var(--gray-500)' }}>Tous les contrôles sont à jour</div>
              </div>
            ) : (
              <>
                <div style={{ fontSize: 12, color: 'var(--gray-500)', margin: '0 6px 8px', display: 'flex', justifyContent: 'space-between' }}>
                  <span>{missingCount} note(s) manquante(s)</span>
                  <span style={{ cursor: 'pointer', color: 'var(--primary)', fontWeight: 600 }} onClick={() => navigate('/admin/saisie-notes')}>Saisir →</span>
                </div>
                <div style={{ display: 'flex', flexDirection: 'column', gap: 4 }}>
                  {topPending.map((item, i) => (
                    <div key={i} style={{ display: 'flex', alignItems: 'center', gap: 8, padding: '6px 8px', borderRadius: 6, background: 'var(--gray-50)', fontSize: 12 }}>
                      <div style={{ width: 6, height: 6, borderRadius: '50%', background: item.missing_count > 0 ? '#dc2626' : '#059669', flexShrink: 0 }} />
                      <span style={{ fontWeight: 500, color: 'var(--gray-700)', minWidth: 0, overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap', flex: 1 }}>
                        {item.filiere_nom} — C{item.controle_numero}
                      </span>
                      {item.missing_count > 0 && (
                        <span style={{ color: '#dc2626', fontWeight: 600, whiteSpace: 'nowrap' }}>
                          {item.notes_entered}/{item.total_expected}
                        </span>
                      )}
                    </div>
                  ))}
                </div>
                {topPending.length > 0 && pendingControles.some(f => (f.items || []).length > 8) && (
                  <div style={{ textAlign: 'center', marginTop: 8 }}>
                    <button className="btn btn-sm btn-link" style={{ fontSize: 12 }} onClick={() => navigate('/admin/saisie-notes')}>Voir tout</button>
                  </div>
                )}
              </>
            )}
          </div>
        </div>
      </div>

      {filiereStats.length > 0 && (
        <div className="card" style={{ border: 'none', boxShadow: '0 1px 3px rgba(0,0,0,0.08)' }}>
          <div className="card-header" style={{ padding: '14px 18px', borderBottom: '1px solid var(--gray-100)' }}>
            <h5 style={{ margin: 0, fontSize: 13, fontWeight: 700, display: 'flex', alignItems: 'center', gap: 6 }}>
              <FiCheckCircle size={14} color="var(--primary)" />
              Progression par filière
            </h5>
          </div>
          <div className="card-body" style={{ padding: 0 }}>
            {filiereStats.map((f) => {
              const pct = f.total_controles > 0 ? Math.round((f.avec_notes / f.total_controles) * 100) : 0;
              return (
                <div key={f.filiere_id} style={{ padding: '12px 18px', borderBottom: '1px solid var(--gray-100)', display: 'flex', alignItems: 'center', gap: 16, flexWrap: 'wrap' }}>
                  <div style={{ fontWeight: 600, fontSize: 13, color: 'var(--gray-800)', minWidth: 160, flex: '0 0 auto' }}>{f.filiere_nom}</div>
                  <div style={{ flex: '1 1 160px', minWidth: 120 }}>
                    <div style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
                      <div style={{ flex: 1, height: 8, background: 'var(--gray-100)', borderRadius: 4, overflow: 'hidden' }}>
                        <div style={{ height: '100%', width: `${pct}%`, background: pct === 100 ? '#059669' : '#3b82f6', borderRadius: 4, transition: 'width 0.3s' }} />
                      </div>
                      <span style={{ fontSize: 12, fontWeight: 600, color: pct === 100 ? '#059669' : 'var(--gray-600)', minWidth: 36, textAlign: 'right' }}>{pct}%</span>
                    </div>
                  </div>
                  <div style={{ display: 'flex', gap: 8, alignItems: 'center', flexWrap: 'wrap' }}>
                    <span style={{ fontSize: 11, padding: '1px 8px', borderRadius: 6, background: '#ecfdf5', color: '#059669', whiteSpace: 'nowrap' }}>
                      {f.avec_notes} saisis
                    </span>
                    {f.sans_notes > 0 && (
                      <span style={{ fontSize: 11, padding: '1px 8px', borderRadius: 6, background: '#fef2f2', color: '#dc2626', whiteSpace: 'nowrap' }}>
                        {f.sans_notes} vides
                      </span>
                    )}
                    <span style={{ fontSize: 11, padding: '1px 8px', borderRadius: 6, background: '#f5f5f5', color: 'var(--gray-500)', whiteSpace: 'nowrap' }}>
                      {f.total_students} étudiants
                    </span>
                  </div>
                </div>
              );
            })}
          </div>
        </div>
      )}
    </div>
  );
}
