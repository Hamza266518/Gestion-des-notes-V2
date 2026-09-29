import { useState, useEffect, useCallback, useMemo, useRef } from 'react';
import { useNavigate } from 'react-router-dom';
import { adminApi } from '../../api/admin';
import { etudiantsApi } from '../../api/etudiants';
import { useToast } from '../../context/ToastContext';
import { useAnneeAcademique } from '../../context/AnneeAcademiqueContext';
import handleApiError from '../../utils/errorHandler';
import Spinner from '../../components/common/Spinner';
import {
  FiFileText,
  FiChevronDown, FiChevronUp, FiCheckCircle,
  FiEdit, FiAward, FiSend, FiTrendingUp, FiAlertCircle,
  FiRefreshCw, FiArrowRight, FiInbox, FiGrid,
} from 'react-icons/fi';
import '../../css/dashboard.css';

const MAX_PENDING_ROWS = 6;

const QUICK_ACTIONS = [
  { label: 'Saisie des notes', desc: 'Saisir les notes',       link: '/admin/saisie-notes', icon: FiEdit },
  { label: 'Publications',     desc: 'Publier les résultats', link: '/admin/publications', icon: FiSend },
  { label: 'Bulletins',        desc: 'Générer les bulletins',  link: '/admin/bulletins',   icon: FiFileText },
  { label: 'Relevé des notes', desc: 'Consulter les relevés',  link: '/admin/notes',       icon: FiTrendingUp },
  { label: 'Diplômes',         desc: 'Éditer les diplômes',   link: '/admin/diplomes',    icon: FiAward },
];

/* A small heading that tells you what a block of information is for. */
function SectionLabel({ icon: Icon, children, note }) {
  return (
    <div className="dash-section-label">
      <Icon size={14} />
      <span>{children}</span>
      {note && <span className="dash-section-note">{note}</span>}
    </div>
  );
}

export default function Dashboard() {
  const [stats, setStats] = useState({
    totalStudents: 0,
    totalFilieres: 0,
    totalFormateurs: 0,
    totalControles: 0,
  });
  const [filiereStats, setFiliereStats] = useState([]);
  const [pendingControles, setPendingControles] = useState([]);
  const [loading, setLoading] = useState(true);
  const [refreshing, setRefreshing] = useState(false);
  const [error, setError] = useState(null);
  const [expandedFiliere, setExpandedFiliere] = useState(null);
  const [showAllPending, setShowAllPending] = useState(false);

  const toast = useToast();
  const toastRef = useRef(toast);
  useEffect(() => { toastRef.current = toast; }, [toast]);

  const navigate = useNavigate();
  const { currentAnnee, loading: anneeLoading } = useAnneeAcademique();

  const load = useCallback(async (isRefresh = false) => {
    if (!currentAnnee) { setLoading(false); return; }
    if (isRefresh) setRefreshing(true); else setLoading(true);
    setError(null);

    try {
      const [etudiantsRes, filieresRes, formateursRes, statsRes, pendingRes] = await Promise.allSettled([
        etudiantsApi.getEtudiants({ annee_academique_id: currentAnnee.id }),
        adminApi.getFilieres(),
        adminApi.getFormateurs(),
        adminApi.getDashboardStats({ annee_academique_id: currentAnnee.id }),
        adminApi.getControlesPending({ annee_academique_id: currentAnnee.id, limit: 50 }),
      ]);

      const dataOf = r => (r.status === 'fulfilled' ? r.value.data.data : null);
      const etudiants = dataOf(etudiantsRes);
      const filieres = dataOf(filieresRes);
      const formateurs = dataOf(formateursRes);
      const fStats = dataOf(statsRes) || [];

      setStats({
        totalStudents: etudiants ? etudiants.length : 0,
        totalFilieres: filieres ? filieres.length : 0,
        totalFormateurs: formateurs ? formateurs.length : 0,
        totalControles: fStats.reduce((s, f) => s + (f.total_controles || 0), 0),
      });
      setFiliereStats(fStats);
      setPendingControles(dataOf(pendingRes) || []);
    } catch (err) {
      const info = handleApiError(err, toastRef.current, { showToast: false });
      setError(info.message);
    } finally {
      setLoading(false);
      setRefreshing(false);
    }
  }, [currentAnnee]);

  useEffect(() => {
    if (anneeLoading) return;
    if (!currentAnnee) { setLoading(false); return; }
    load();
  }, [anneeLoading, currentAnnee, load]);

  /* ── derived operational data (all from real API payloads) ── */
  const overview = useMemo(() => {
    const sum = key => filiereStats.reduce((s, f) => s + (f[key] || 0), 0);
    return {
      active: sum('active_students'),
      graduate: sum('graduate_students'),
      dropped: sum('dropped_students'),
      groupes: filiereStats.reduce((s, f) => s + ((f.groupes || []).length), 0),
      missingNotes: sum('sans_notes'),
      haveStats: filiereStats.length > 0,
    };
  }, [filiereStats]);

  const pending = useMemo(() => {
    const rows = pendingControles
      .flatMap(f => (f.items || []).map(i => ({ ...i, filiere_nom: f.filiere_nom })))
      .sort((a, b) => b.missing_count - a.missing_count);

    return {
      controls: pendingControles.reduce((s, f) => s + (f.total_pending_controles || 0), 0),
      missing: pendingControles.reduce((s, f) => s + (f.total_missing_notes || 0), 0),
      rows,
      hasOverflow: rows.length > MAX_PENDING_ROWS,
    };
  }, [pendingControles]);

  if (loading || anneeLoading) {
    return (
      <div className="dash" style={{ display: 'flex', justifyContent: 'center', paddingTop: 90 }}>
        <Spinner />
      </div>
    );
  }

  if (error) {
    return (
      <div className="dash">
        <div className="dash-error">
          <div className="dash-error-icon"><FiAlertCircle size={20} /></div>
          <div className="dash-error-title">Erreur de chargement</div>
          <p className="dash-error-msg">{error}</p>
          <button className="btn btn-primary" onClick={() => { setError(null); setLoading(true); }}>
            Réessayer
          </button>
        </div>
      </div>
    );
  }

  const yearLabel = currentAnnee?.label ?? '—';

  /*
   * Both the attention card and the side panel must agree. The API can report
   * pending controls with zero missing notes (a group containing no students),
   * so "is there work" is keyed off the control count, not the note count.
   */
  const hasWork = pending.controls > 0;
  const hasMissing = pending.missing > 0;
  const statTiles = [
    { key: 'totalStudents',   label: 'Étudiants',   value: stats.totalStudents,   sub: `${overview.active} actifs`,     link: '/admin/etudiants' },
    { key: 'totalFilieres',   label: 'Filières',    value: stats.totalFilieres,   sub: `${overview.groupes} groupes`,   link: '/admin/filieres-groupes' },
    { key: 'totalFormateurs', label: 'Formateurs',  value: stats.totalFormateurs, sub: 'équipe pédagogique',           link: '/admin/formateurs' },
    { key: 'totalControles',  label: 'Contrôles',   value: stats.totalControles,  sub: yearLabel,                     link: '/admin/controles' },
  ];

  return (
    <div className="dash">

      {/* ── HEADER ── */}
      <div className="dash-head">
        <div className="dash-head-text">
          <h1 className="dash-title">Tableau de bord</h1>
          <p className="dash-subtitle">Année académique {yearLabel}</p>
        </div>
        <button
          className={`dash-refresh${refreshing ? ' is-spinning' : ''}`}
          onClick={() => load(true)}
          disabled={refreshing}
          title="Actualiser les données"
          aria-label="Actualiser les données"
        >
          <FiRefreshCw size={15} />
        </button>
      </div>

      {/* ── 1. THE ONE THING THAT MATTERS ── */}
      {hasWork ? (
        <div className="dash-attention">
          <div className="dash-attention-main">
            <div className="dash-attention-value">
              {hasMissing ? pending.missing : pending.controls}
              <span className="dash-attention-unit">
                {hasMissing ? 'notes à saisir' : 'contrôles à vérifier'}
              </span>
            </div>
            <p className="dash-attention-text">
              {hasMissing ? (
                <>
                  réparties sur <strong>{pending.controls}</strong>{' '}
                  {pending.controls > 1 ? 'contrôles' : 'contrôle'} incomplet
                  {pending.controls > 1 ? 's' : ''}.
                </>
              ) : (
                <>
                  aucun groupe ne contient d’étudiant pour ces contrôles.
                </>
              )}
            </p>
          </div>
          <button className="dash-attention-btn" onClick={() => navigate('/admin/saisie-notes')}>
            Commencer la saisie <FiArrowRight size={15} />
          </button>
        </div>
      ) : (
        <div className="dash-clear">
          <FiCheckCircle size={18} />
          <div>
            <div className="dash-clear-title">Tout est à jour</div>
            <p className="dash-clear-text">Aucune note en attente. Toutes les notes attendues ont été saisies.</p>
          </div>
        </div>
      )}

      {/* ── 2. KEY NUMBERS ── */}
      <SectionLabel icon={FiGrid}>Chiffres clés</SectionLabel>
      <div className="dash-stats">
        {statTiles.map(t => (
          <button
            key={t.key}
            className="stat"
            onClick={() => navigate(t.link)}
          >
            <span className="stat-value">{t.value}</span>
            <span className="stat-label">{t.label}</span>
            <span className="stat-sub">{t.sub}</span>
          </button>
        ))}
      </div>

      {/* ── 3. DETAIL ── */}
      <div className="dash-grid">

        {/* LEFT — PROGRESSION */}
        <div className="panel">
          <div className="panel-head">
            <span className="panel-title">Progression de la saisie</span>
            <span className="panel-head-note">
              {filiereStats.length > 0
                ? `${overview.missingNotes} note(s) manquante(s) au total`
                : ''}
            </span>
          </div>

          {filiereStats.length === 0 ? (
            <div className="dash-empty">
              <div className="dash-empty-icon is-muted"><FiInbox size={18} /></div>
              <div className="dash-empty-title">Aucune donnée de progression</div>
              <p className="dash-empty-text">
                Créez des filières, des unités et des contrôles pour visualiser la progression de la saisie des notes.
              </p>
              <button className="btn btn-sm btn-primary" onClick={() => navigate('/admin/unites')}>
                Gérer les unités
              </button>
            </div>
          ) : (
            <>
              <div className="prog-head">
                <span>Filière</span>
                <span className="prog-head-right">Progression de la saisie</span>
              </div>

              {filiereStats.map(f => {
                const pct = f.total_controles > 0 ? Math.round((f.avec_notes / f.total_controles) * 100) : 0;
                const complete = f.total_controles > 0 && f.sans_notes === 0;
                const isOpen = expandedFiliere === f.filiere_id;
                const hasGroupes = (f.groupes || []).length > 0;
                const missing = f.sans_notes || 0;

                return (
                  <div key={f.filiere_id} className="prog-item">
                    <button
                      className={`prog-row${hasGroupes ? ' is-clickable' : ''}`}
                      onClick={hasGroupes ? () => setExpandedFiliere(isOpen ? null : f.filiere_id) : undefined}
                      aria-expanded={hasGroupes ? isOpen : undefined}
                    >
                      <span className="prog-label">
                        <span className="prog-name" title={f.filiere_nom}>{f.filiere_nom}</span>
                        <span className="prog-meta">
                          {f.total_students} étudiants · {f.total_controles} contrôles
                        </span>
                      </span>

                      <span className="prog-figure">
                        <span className="prog-track">
                          <span
                            className={`prog-fill${complete ? ' is-complete' : ''}`}
                            style={{ width: `${pct}%` }}
                          />
                        </span>
                        <span className="prog-pct">{pct}%</span>
                        <span className={`prog-missing${missing > 0 ? ' is-alert' : ' is-clear'}`}>
                          {missing > 0 ? `${missing} manquante${missing > 1 ? 's' : ''}` : 'Complet'}
                        </span>
                      </span>

                      {hasGroupes && (
                        <span className="prog-chevron">
                          {isOpen ? <FiChevronUp size={15} /> : <FiChevronDown size={15} />}
                        </span>
                      )}
                    </button>

                    {isOpen && hasGroupes && (
                      <div className="prog-detail">
                        {(f.groupes || []).map(g => {
                          const gPct = g.total_controles > 0 ? Math.round((g.avec_notes / g.total_controles) * 100) : 0;
                          const gComplete = g.total_controles > 0 && g.sans_notes === 0;
                          return (
                            <div key={g.id} className="prog-detail-row">
                              <span className="prog-detail-name" title={g.nom}>{g.nom}</span>
                              <span className="prog-detail-meta">
                                {g.etudiants_count} étudiants · {g.total_controles} contrôles
                              </span>
                              <span className="prog-detail-figure">
                                <span className="prog-track is-thin">
                                  <span
                                    className={`prog-fill${gComplete ? ' is-complete' : ''}`}
                                    style={{ width: `${gPct}%` }}
                                  />
                                </span>
                                <span className="prog-pct is-sm">{gPct}%</span>
                              </span>
                            </div>
                          );
                        })}
                      </div>
                    )}
                  </div>
                );
              })}
            </>
          )}
        </div>

        {/* RIGHT — À TRAITER */}
        <div className="panel">
          <div className="panel-head">
            <span className="panel-title">Contrôles à compléter</span>
            {pending.controls > 0 && (
              <span className="panel-count">{pending.controls}</span>
            )}
          </div>

          {pending.controls === 0 ? (
            <div className="dash-empty">
              <div className="dash-empty-icon"><FiCheckCircle size={18} /></div>
              <div className="dash-empty-title">Rien à faire</div>
              <p className="dash-empty-text">Tous les contrôles ont leurs notes saisies.</p>
            </div>
          ) : (
            <>
              <div className="pend-list">
                {pending.rows
                  .slice(0, showAllPending ? pending.rows.length : MAX_PENDING_ROWS)
                  .map(item => {
                    const state = item.missing_count > 0
                      ? (item.notes_entered === 0 ? 'danger' : 'warn')
                      : 'ok';
                    return (
                      <button
                        key={item.controle_id}
                        className="pend-row"
                        onClick={() => navigate('/admin/saisie-notes')}
                      >
                        <span className={`pend-dot ${state}`} />
                        <span className="pend-body">
                          <span className="pend-title" title={item.unite_nom}>{item.unite_nom}</span>
                          <span className="pend-sub">
                            {item.filiere_nom} · Contrôle {item.controle_numero}
                          </span>
                        </span>
                        <span className="pend-figure">
                          <span className="pend-done">{item.notes_entered}/{item.total_expected}</span>
                          <span className={`pend-left${item.missing_count > 0 ? ' is-alert' : ''}`}>
                            {item.missing_count > 0
                              ? `${item.missing_count} restante${item.missing_count > 1 ? 's' : ''}`
                              : 'complet'}
                          </span>
                        </span>
                      </button>
                    );
                  })}
              </div>

              <div className="pend-foot">
                <span className="pend-foot-note">
                  {showAllPending || !pending.hasOverflow
                    ? `${pending.rows.length} contrôle(s) au total`
                    : `${MAX_PENDING_ROWS} sur ${pending.rows.length} affichés`}
                </span>
                {pending.hasOverflow && (
                  <button className="link-action" onClick={() => setShowAllPending(v => !v)}>
                    {showAllPending ? 'Réduire' : 'Tout afficher'}
                  </button>
                )}
              </div>
            </>
          )}
        </div>
      </div>

      {/* ── 4. QUICK ACTIONS ── */}
      <SectionLabel icon={FiEdit}>Raccourcis</SectionLabel>
      <div className="qa-grid">
        {QUICK_ACTIONS.map(a => {
          const Icon = a.icon;
          return (
            <button key={a.link} className="qa-item" onClick={() => navigate(a.link)}>
              <span className="qa-icon"><Icon size={15} /></span>
              <span className="qa-text">
                <span className="qa-title">{a.label}</span>
                <span className="qa-desc">{a.desc}</span>
              </span>
            </button>
          );
        })}
      </div>
    </div>
  );
}
