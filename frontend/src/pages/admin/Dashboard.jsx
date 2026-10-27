import { useCallback, useEffect, useMemo, useRef, useState, Fragment } from 'react';
import { useNavigate } from 'react-router-dom';
import { adminApi } from '../../api/admin';
import { useToast } from '../../context/ToastContext';
import { useAnneeAcademique } from '../../context/AnneeAcademiqueContext';
import { handleApiError } from '../../utils/errorHandler';
import { formatNiveau } from '../../utils/helpers';
import Spinner from '../../components/common/Spinner';
import {
  FiAward, FiEdit, FiTrendingUp, FiSend, FiFileText,
  FiChevronDown, FiChevronUp, FiCheckCircle, FiAlertCircle,
  FiRefreshCw, FiArrowRight, FiInbox, FiInfo,
} from 'react-icons/fi';
import '../../css/dashboard.css';

const MAX_PENDING_ROWS = 6;

const PUB_LABELS = { notes_s1: 'S1', notes_s2: 'S2', bulletin: 'Bull.' };

const QUICK_ACTIONS = [
  { label: 'Saisie des notes', desc: 'Saisir ou corriger les notes', link: '/admin/saisie-notes', icon: FiEdit },
  { label: 'Relevé des notes', desc: 'Voir les notes par étudiant', link: '/admin/notes', icon: FiTrendingUp },
  { label: 'Publications', desc: 'Publier notes et bulletins', link: '/admin/publications', icon: FiSend },
  { label: 'Bulletins', desc: 'Générer et imprimer les bulletins', link: '/admin/bulletins', icon: FiFileText },
  { label: 'Diplômes', desc: 'Éditer et imprimer les diplômes', link: '/admin/diplomes', icon: FiAward },
];

const clamp = (v) => Math.max(0, Math.min(100, Number(v) || 0));
const pctOf = (a, b) => (b > 0 ? clamp((a / b) * 100) : 0);
const stateOf = (pct) => (pct >= 100 ? 'done' : pct > 0 ? 'active' : 'todo');
const formatPct = (v) => Number(v || 0).toFixed(1).replace('.', ',');

/* Small "!" button: the detailed explanation only appears on click. */
function InfoNote() {
  const [open, setOpen] = useState(false);
  const ref = useRef(null);

  useEffect(() => {
    if (!open) return undefined;
    const onDown = (e) => { if (ref.current && !ref.current.contains(e.target)) setOpen(false); };
    const onKey = (e) => { if (e.key === 'Escape') setOpen(false); };
    document.addEventListener('mousedown', onDown);
    document.addEventListener('keydown', onKey);
    return () => {
      document.removeEventListener('mousedown', onDown);
      document.removeEventListener('keydown', onKey);
    };
  }, [open]);

  return (
    <div className="info" ref={ref}>
      <button
        type="button"
        className="info-btn"
        aria-label="Comment lire ces chiffres"
        aria-expanded={open}
        onClick={() => setOpen((o) => !o)}
      >
        <FiInfo size={13} />
      </button>
      {open && (
        <div className="info-pop" role="dialog">
          <span className="info-pop-title">Comment lire ces chiffres</span>
          <p><b>Une note</b> = la note d’un étudiant à un contrôle.</p>
          <p>Les <b>notes attendues</b> comptent une note par étudiant actif ou diplômé, pour chaque contrôle de son année d’étude.</p>
          <p>Les notes de <b>stage</b> sont suivies séparément et ne sont pas comptées ici.</p>
        </div>
      )}
    </div>
  );
}

/* Publication counts for a whole filière: "S1 2/3  S2 1/3  Bull. 0/3". */
function PubChips({ pub, total }) {
  return (
    <span className="db-pubs">
      {Object.keys(PUB_LABELS).map((t) => {
        const done = Number(pub?.[t]) || 0;
        const isDone = total > 0 && done >= total;
        return (
          <span key={t} className={`db-pub${isDone ? ' is-done' : ''}`}>
            {PUB_LABELS[t]} {done}/{total}
          </span>
        );
      })}
    </span>
  );
}

/* Publication types already published for a single group. */
function GroupPubs({ types }) {
  if (!types || types.length === 0) return <span className="db-dash">—</span>;
  return (
    <span className="db-pubs">
      {types.map((t) => <span key={t} className="db-pub is-done">{PUB_LABELS[t] || t}</span>)}
    </span>
  );
}

export default function Dashboard() {
  const [ovData, setOvData] = useState(null);
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
      const [statsRes, pendingRes] = await Promise.allSettled([
        adminApi.getDashboardStats({ annee_academique_id: currentAnnee.id }),
        adminApi.getControlesPending({ annee_academique_id: currentAnnee.id, limit: 50 }),
      ]);
      if (statsRes.status === 'rejected') throw statsRes.reason;

      const dataOf = (r) => (r.status === 'fulfilled' ? r.value.data.data : null);
      const statsData = dataOf(statsRes) || {};

      setOvData(statsData.overview || null);
      setFiliereStats(statsData.filieres || []);
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

  const pending = useMemo(() => {
    const rows = pendingControles
      .flatMap((f) => (f.items || []).map((item) => ({ ...item, filiere_nom: f.filiere_nom })));
    return {
      controls: pendingControles.reduce((sum, f) => sum + (f.total_pending_controles || 0), 0),
      rows,
    };
  }, [pendingControles]);

  const ov = ovData || {};
  const hasStats = filiereStats.length > 0;

  if (loading) {
    return (
      <div className="dash">
        <div style={{ display: 'flex', justifyContent: 'center', paddingTop: 80 }}><Spinner /></div>
      </div>
    );
  }

  if (error) {
    return (
      <div className="dash">
        <div className="card" style={{ marginTop: 8 }}>
          <div className="db-empty" style={{ paddingTop: 48 }}>
            <div className="db-empty-icon is-muted"><FiAlertCircle size={22} /></div>
            <div className="db-empty-title">Erreur de chargement</div>
            <p className="db-empty-text">{error}</p>
            <button className="btn btn-sm btn-primary" onClick={() => { setError(null); setLoading(true); load(); }}>
              Réessayer
            </button>
          </div>
        </div>
      </div>
    );
  }

  if (!currentAnnee) {
    return (
      <div className="dash">
        <div className="dash-head">
          <div>
            <h1 className="dash-title">Tableau de bord</h1>
            <p className="dash-subtitle">Aucune année académique courante</p>
          </div>
        </div>
        <div className="card">
          <div className="db-empty" style={{ paddingTop: 48 }}>
            <div className="db-empty-icon is-muted"><FiInbox size={22} /></div>
            <div className="db-empty-title">Aucune année académique</div>
            <p className="db-empty-text">Définissez une année académique courante pour afficher le tableau de bord.</p>
            <button className="btn btn-sm btn-primary" onClick={() => navigate('/admin/annees')}>
              Gérer les années
            </button>
          </div>
        </div>
      </div>
    );
  }

  /* ── figures ─────────────────────────────────────────────── */
  const students = Number(ov.total_students) || 0;
  const active = Number(ov.active_students) || 0;
  const grad = Number(ov.graduate_students) || 0;
  const notesMissing = Number(ov.notes_missing) || 0;
  const notesPct = Number(ov.notes_pct) || 0;
  const emptyControls = Number(ov.controles_sans_notes) || 0;
  const totalGroupes = Number(ov.total_groupes) || 0;
  const publishedGroups = Number(ov.groupes_publies) || 0;
  const totalDiplomes = Number(ov.total_diplomes) || 0;
  const diplomesPrinted = Number(ov.diplomes_printed) || 0;
  const formateurs = Number(ov.total_formateurs) || 0;

  const bulletinsPublished = filiereStats.reduce(
    (sum, f) => sum + (Number(f.publications?.bulletin) || 0), 0,
  );

  /* The year as a workflow: inscription → notes → publications → bulletins → diplômes. */
  const stages = [
    {
      key: 'inscriptions', label: 'Inscriptions', link: '/admin/etudiants',
      hint: 'Étudiants inscrits cette année (actifs et diplômés).',
      figure: students, sub: `${active} actifs · ${grad} diplômés`,
      state: students > 0 ? 'done' : 'todo',
      stacked: [
        { value: active, className: 'is-active' },
        { value: grad, className: 'is-done' },
      ],
    },
    {
      key: 'notes', label: 'Saisie des notes', link: '/admin/saisie-notes',
      hint: 'Notes saisies par rapport aux notes attendues.',
      figure: `${formatPct(notesPct)}%`,
      sub: notesMissing > 0
        ? `${notesMissing} notes restantes · ${emptyControls} contrôles sans note`
        : 'toutes saisies',
      pct: notesPct, state: stateOf(notesPct),
    },
    {
      key: 'publications', label: 'Publications', link: '/admin/publications',
      hint: 'Groupes dont un semestre (ou le bulletin) est publié.',
      figure: `${publishedGroups}/${totalGroupes}`,
      sub: totalGroupes - publishedGroups > 0
        ? `${totalGroupes - publishedGroups} groupe(s) à publier` : 'tout est publié',
      pct: pctOf(publishedGroups, totalGroupes), state: stateOf(pctOf(publishedGroups, totalGroupes)),
    },
    {
      key: 'bulletins', label: 'Bulletins', link: '/admin/bulletins',
      hint: 'Groupes dont le bulletin est publié.',
      figure: `${bulletinsPublished}/${totalGroupes}`,
      sub: totalGroupes - bulletinsPublished > 0
        ? `${totalGroupes - bulletinsPublished} à générer` : 'tous générés',
      pct: pctOf(bulletinsPublished, totalGroupes), state: stateOf(pctOf(bulletinsPublished, totalGroupes)),
    },
    {
      key: 'diplomes', label: 'Diplômes', link: '/admin/diplomes',
      hint: 'Diplômes imprimés par rapport au total délivré.',
      figure: `${diplomesPrinted}/${totalDiplomes}`,
      sub: totalDiplomes - diplomesPrinted > 0
        ? `${totalDiplomes - diplomesPrinted} à imprimer` : 'tous imprimés',
      pct: pctOf(diplomesPrinted, totalDiplomes), state: stateOf(pctOf(diplomesPrinted, totalDiplomes)),
    },
  ];

  /* First unfinished stage = the year's bottleneck. */
  const nextStage = stages.find((s) => s.key !== 'inscriptions' && s.state !== 'done');

  const shownRows = pending.rows.slice(0, showAllPending ? pending.rows.length : MAX_PENDING_ROWS);

  return (
    <div className="dash">

      {/* ── HEADER ── */}
      <div className="dash-head">
        <div>
          <h1 className="dash-title">Tableau de bord</h1>
          <p className="dash-subtitle">Année académique <strong>{currentAnnee.label}</strong></p>
        </div>
        <button
          type="button"
          className={`dash-refresh${refreshing ? ' is-spinning' : ''}`}
          onClick={() => load(true)}
          disabled={refreshing}
          title="Actualiser les données"
          aria-label="Actualiser les données"
        >
          <FiRefreshCw size={16} />
        </button>
      </div>

      {!hasStats ? (
        <div className="card">
          <div className="db-empty" style={{ paddingTop: 48 }}>
            <div className="db-empty-icon is-muted"><FiInbox size={22} /></div>
            <div className="db-empty-title">Aucune donnée pour cette année</div>
            <p className="db-empty-text">
              Créez des filières, des unités et des contrôles pour suivre la saisie des notes.
            </p>
            <button type="button" className="btn btn-sm btn-primary" onClick={() => navigate('/admin/unites')}>
              Gérer les unités
            </button>
          </div>
        </div>
      ) : (
        <>
          {/* ── RACCOURCIS (en haut) ── */}
          <nav className="quick" aria-label="Raccourcis">
            {QUICK_ACTIONS.map((a) => {
              const Icon = a.icon;
              return (
                <button key={a.link} type="button" className="quick-item" onClick={() => navigate(a.link)}>
                  <span className="quick-icon"><Icon size={16} /></span>
                  <span className="quick-text">
                    <span className="quick-label">{a.label}</span>
                    <span className="quick-desc">{a.desc}</span>
                  </span>
                </button>
              );
            })}
          </nav>

          {/* ── LE PARCOURS DE L'ANNÉE ── */}
          <section className="card flow-card">
            <header className="card-head">
              <h2 className="card-title">Le parcours de l’année</h2>
              <InfoNote />
              <span className="card-meta">
                {filiereStats.length} filières · {totalGroupes} groupes · {formateurs} formateurs
              </span>
            </header>

            <div className="card-body">
              <div className="flow-track">
                {stages.map((s, i) => (
                  <Fragment key={s.key}>
                    {i > 0 && <span className="flow-arrow" aria-hidden="true"><FiArrowRight size={15} /></span>}
                    <button
                      type="button"
                      className={`flow-stage is-${s.state}${nextStage?.key === s.key ? ' is-current' : ''}`}
                      onClick={() => navigate(s.link)}
                      title={s.hint}
                    >
                      <span className="flow-stage-head">
                        <span className="flow-step">{String(i + 1).padStart(2, '0')}</span>
                        <span className="flow-label">{s.label}</span>
                        {nextStage?.key === s.key && <span className="flow-flag">à traiter</span>}
                      </span>
                      <span className="flow-bar">
                        {s.stacked ? (
                          s.stacked.map((part) => (
                            <span
                              key={part.className}
                              className={`flow-seg ${part.className}`}
                              style={{ width: `${pctOf(part.value, s.figure)}%` }}
                            />
                          ))
                        ) : (
                          <span className="flow-fill" style={{ width: `${clamp(s.pct)}%` }} />
                        )}
                      </span>
                      <span className="flow-figure">{s.figure}</span>
                      <span className="flow-sub">{s.sub}</span>
                    </button>
                  </Fragment>
                ))}
              </div>
            </div>
          </section>

          {/* ── CONTRÔLES À COMPLÉTER ── */}
          <section className="card">
            <header className="card-head">
              <h2 className="card-title">Contrôles à compléter</h2>
              {pending.controls > 0
                ? <span className="badge badge-warn">{pending.controls} au total</span>
                : <span className="badge badge-ok">à jour</span>}
            </header>

            {pending.controls === 0 ? (
              <div className="db-empty" style={{ paddingTop: 40, paddingBottom: 44 }}>
                <div className="db-empty-icon"><FiCheckCircle size={22} /></div>
                <div className="db-empty-title">Rien à faire</div>
                <p className="db-empty-text">Tous les contrôles ont leurs notes saisies.</p>
              </div>
            ) : (
              <>
                <div className="card-body">
                  <div className="todo-grid">
                    {shownRows.map((item) => {
                      const state = item.missing_count === 0 ? 'ok' : (item.notes_entered === 0 ? 'danger' : 'warn');
                      return (
                        <button
                          key={item.controle_id}
                          type="button"
                          className={`todo-row is-${state}`}
                          onClick={() => navigate('/admin/saisie-notes')}
                        >
                          <span className="todo-dot" />
                          <span className="todo-main">
                            <span className="todo-title" title={item.unite_nom}>{item.unite_nom}</span>
                            <span className="todo-meta">
                              {item.filiere_nom} · {formatNiveau(item.niveau_numero)} · Contrôle {item.controle_numero}
                            </span>
                            {item.missing_students?.length > 0 && (
                              <span
                                className="todo-missing"
                                title={item.missing_students.map((s) => s.nom_prenom).join(', ')}
                              >
                                Manque : {item.missing_students.slice(0, 3).map((s) => s.nom_prenom).join(', ')}
                                {item.missing_count > 3 ? ` +${item.missing_count - 3}` : ''}
                              </span>
                            )}
                          </span>
                          <span className="todo-side">
                            <span className="todo-chip">
                              {item.missing_count > 0 ? `${item.missing_count} restante${item.missing_count > 1 ? 's' : ''}` : 'complet'}
                            </span>
                            <span className="todo-count">{item.notes_entered}/{item.total_expected}</span>
                          </span>
                        </button>
                      );
                    })}
                  </div>
                </div>

                <div className="card-foot">
                  <span>
                    {showAllPending
                      ? `${pending.rows.length} contrôle(s) affiché(s)`
                      : `Aperçu de ${Math.min(MAX_PENDING_ROWS, pending.rows.length)} sur ${pending.controls}`}
                  </span>
                  <button
                    type="button"
                    className="link-action"
                    onClick={() => setShowAllPending((v) => !v)}
                  >
                    {showAllPending ? 'Réduire' : 'Tout afficher'}
                  </button>
                </div>
              </>
            )}
          </section>

          {/* ── DÉTAIL PAR FILIÈRE ── */}
          <section className="card">
            <header className="card-head">
              <h2 className="card-title">Détail par filière</h2>
              <span className="badge badge-neutral">{publishedGroups}/{totalGroupes} groupes publiés</span>
            </header>

            <div className="card-body card-body--table">
              <div className="db-scroll">
                <table className="db-table">
                  <thead>
                    <tr>
                      <th>Filière</th>
                      <th>Étudiants</th>
                      <th>Notes saisies</th>
                      <th>Publications</th>
                      <th>Diplômes</th>
                    </tr>
                  </thead>
                  <tbody>
                    {filiereStats.map((f) => {
                      const isOpen = expandedFiliere === f.filiere_id;
                      const hasGroupes = (f.groupes || []).length > 0;
                      return (
                        <Fragment key={f.filiere_id}>
                          <tr
                            className={`db-row${hasGroupes ? ' is-clickable' : ''}`}
                            onClick={hasGroupes ? () => setExpandedFiliere(isOpen ? null : f.filiere_id) : undefined}
                          >
                            <td>
                              <span className="db-name">
                                {hasGroupes && (
                                  <span className="db-chevron">
                                    {isOpen ? <FiChevronUp size={14} /> : <FiChevronDown size={14} />}
                                  </span>
                                )}
                                {f.filiere_nom}
                              </span>
                            </td>
                            <td>
                              <span className="db-big">{f.total_students}</span>
                              <span className="db-small">{f.active_students} actifs · {f.graduate_students} diplômés</span>
                            </td>
                            <td>
                              <span className="db-saisie">
                                <span className={`db-bar${f.notes_pct >= 100 ? ' is-done' : f.notes_pct <= 0 ? ' is-empty' : ''}`}>
                                  <span style={{ width: `${clamp(f.notes_pct)}%` }} />
                                </span>
                                <span className="db-pct">{formatPct(f.notes_pct)}%</span>
                              </span>
                              <span className="db-small">{f.notes_entered} / {f.notes_expected} notes</span>
                            </td>
                            <td>
                              <PubChips pub={f.publications} total={f.total_groupes} />
                              <span className="db-small">{f.groupes_publies}/{f.total_groupes} groupes publiés</span>
                            </td>
                            <td>
                              <span className="db-big">{f.total_diplomes}</span>
                              <span className="db-small">{f.diplomes_printed} imprimés</span>
                            </td>
                          </tr>

                          {isOpen && hasGroupes && (f.groupes || []).map((g) => (
                            <tr key={g.id} className="db-sub">
                              <td>
                                <span className="db-sub-name">{g.nom} <em>{formatNiveau(g.niveau_numero)}</em></span>
                              </td>
                              <td>{g.etudiants_count}</td>
                              <td>
                                <span className="db-saisie">
                                  <span className={`db-bar${g.notes_pct >= 100 ? ' is-done' : g.notes_pct <= 0 ? ' is-empty' : ''}`}>
                                    <span style={{ width: `${clamp(g.notes_pct)}%` }} />
                                  </span>
                                  <span className="db-pct">{formatPct(g.notes_pct)}%</span>
                                </span>
                                <span className="db-small">{g.notes_entered} saisies</span>
                              </td>
                              <td><GroupPubs types={g.publie_types} /></td>
                              <td><span className="db-dash">—</span></td>
                            </tr>
                          ))}
                        </Fragment>
                      );
                    })}
                  </tbody>
                </table>
              </div>
            </div>

            <div className="card-foot">
              <span>{notesMissing} note{notesMissing > 1 ? 's' : ''} restante{notesMissing > 1 ? 's' : ''} sur l’année</span>
              <button type="button" className="link-action" onClick={() => navigate('/admin/publications')}>
                Gérer les publications
              </button>
            </div>
          </section>
        </>
      )}
    </div>
  );
}
