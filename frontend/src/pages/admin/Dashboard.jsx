import { useState, useEffect, useCallback, useMemo, useRef, Fragment } from 'react';
import { useNavigate } from 'react-router-dom';
import { adminApi } from '../../api/admin';
import { useToast } from '../../context/ToastContext';
import { useAnneeAcademique } from '../../context/AnneeAcademiqueContext';
import handleApiError from '../../utils/errorHandler';
import { formatNiveau } from '../../utils/helpers';
import Spinner from '../../components/common/Spinner';
import {
  FiFileText,
  FiChevronDown, FiChevronUp, FiCheckCircle,
  FiEdit, FiAward, FiSend, FiTrendingUp, FiAlertCircle,
  FiRefreshCw, FiArrowRight, FiInbox, FiGrid,
} from 'react-icons/fi';
import '../../css/dashboard.css';

const MAX_PENDING_ROWS = 6;

const PUB_LABELS = { notes_s1: 'Notes S1', notes_s2: 'Notes S2', bulletin: 'Bulletin' };

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

function n(v) { return Number(v || 0); }
function fmt(v) { return n(v).toLocaleString('fr-FR'); }

function pubText(types) {
  if (!types || types.length === 0) return '—';
  return types.map(t => PUB_LABELS[t] || t).join(' · ');
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

      const dataOf = r => (r.status === 'fulfilled' ? r.value.data.data : null);
      const statsData = dataOf(statsRes) || {};
      const fStats = statsData.filieres || [];

      setOvData(statsData.overview || null);
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

  const ov = ovData || {};

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

  const hasStats = filiereStats.length > 0;
  const totalFilieres = filiereStats.length;

  const statTiles = [
    { key: 'students',  label: 'Étudiants',   value: fmt(ov.total_students),      sub: `${fmt(ov.active_students)} actifs · ${fmt(ov.graduate_students)} diplômés`, link: '/admin/etudiants' },
    { key: 'groupes',   label: 'Groupes',     value: fmt(ov.total_groupes),       sub: `${fmt(ov.total_niveaux)} niveaux · ${totalFilieres} ${totalFilieres > 1 ? 'filières' : 'filière'}`, link: '/admin/filieres-groupes' },
    { key: 'formateurs', label: 'Formateurs', value: fmt(ov.total_formateurs),    sub: 'équipe pédagogique', link: '/admin/formateurs' },
    { key: 'controles', label: 'Contrôles',   value: fmt(ov.total_controles),     sub: `${fmt(ov.controles_avec_notes)} complétés · ${fmt(ov.controles_sans_notes)} vides`, link: '/admin/unites' },
    { key: 'notes',     label: 'Notes saisies', value: `${Number(ov.notes_pct || 0).toFixed(1).replace('.', ',')}%`, sub: `${fmt(ov.notes_entered)} saisies · ${fmt(ov.notes_expected)} attendues`, link: '/admin/saisie-notes' },
    { key: 'diplomes',  label: 'Diplômes',    value: fmt(ov.total_diplomes),      sub: `${fmt(ov.diplomes_printed)} imprimés`, link: '/admin/diplomes' },
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

        {/* LEFT — RESULTS OF THE YEAR */}
        <div className="panel">
          <div className="panel-head">
            <span className="panel-title">Résultats de l’année</span>
            <span className="panel-head-note">
              {hasStats
                ? `${fmt(ov.notes_missing)} note${n(ov.notes_missing) > 1 ? 's' : ''} attendante${n(ov.notes_missing) > 1 ? 's' : ''}`
                : ''}
            </span>
          </div>

          {!hasStats ? (
            <div className="dash-empty">
              <div className="dash-empty-icon is-muted"><FiInbox size={18} /></div>
              <div className="dash-empty-title">Aucune donnée pour cette année</div>
              <p className="dash-empty-text">
                Créez des filières, des unités et des contrôles pour visualiser les résultats de l’année.
              </p>
              <button className="btn btn-sm btn-primary" onClick={() => navigate('/admin/unites')}>
                Gérer les unités
              </button>
            </div>
          ) : (
            <div className="rt-scroll">
              <table className="rt">
                <thead>
                  <tr>
                    <th>Filière</th>
                    <th>Étudiants</th>
                    <th>Groupes</th>
                    <th>Contrôles</th>
                    <th>Notes</th>
                    <th>G. publiés</th>
                    <th>Diplômes</th>
                  </tr>
                </thead>
                <tbody>
                  {filiereStats.map(f => {
                    const isOpen = expandedFiliere === f.filiere_id;
                    const hasGroupes = (f.groupes || []).length > 0;
                    return (
                      <Fragment key={f.filiere_id}>
                        <tr
                          className={`rt-row${hasGroupes ? ' rt-clickable' : ''}`}
                          onClick={hasGroupes ? () => setExpandedFiliere(isOpen ? null : f.filiere_id) : undefined}
                        >
                          <td>
                            <span className="rt-name">
                              {hasGroupes && (
                                <span className="rt-chevron">
                                  {isOpen ? <FiChevronUp size={14} /> : <FiChevronDown size={14} />}
                                </span>
                              )}
                              {f.filiere_nom}
                            </span>
                          </td>
                          <td>
                            <span className="rt-big">{fmt(f.total_students)}</span>
                            <span className="rt-small">
                              {fmt(f.active_students)} actifs · {fmt(f.graduate_students)} diplômés{f.dropped_students > 0 ? ` · ${fmt(f.dropped_students)} abandonnés` : ''}
                            </span>
                          </td>
                          <td>
                            <span className="rt-big">{f.total_groupes}</span>
                          </td>
                          <td>
                            <span className="rt-big">{fmt(f.controles_avec_notes)}/{fmt(f.total_controles)}</span>
                            <span className="rt-small">{f.saisie_pct}% complétés</span>
                          </td>
                          <td>
                            <span className="rt-big">{Number(f.notes_pct || 0).toFixed(1).replace('.', ',')}%</span>
                            <span className="rt-small">{fmt(f.notes_entered)} / {fmt(f.notes_expected)} saisies</span>
                          </td>
                          <td>
                            <span className="rt-big">{f.groupes_publies}/{f.total_groupes}</span>
                            <span className="rt-small">groupes publiés</span>
                          </td>
                          <td>
                            <span className="rt-big">{fmt(f.total_diplomes)}</span>
                            <span className="rt-small">{fmt(f.diplomes_printed)} imprimés · {fmt(f.diplomes_non_imprimes)} à imprimer</span>
                          </td>
                        </tr>

                        {isOpen && hasGroupes && (f.groupes || []).map(g => (
                          <tr key={g.id} className="rt-sub">
                            <td><span className="rt-grp-name">{g.nom} · {formatNiveau(g.niveau_numero)}</span></td>
                            <td>{g.etudiants_count}</td>
                            <td>—</td>
                            <td>
                              <span className="rt-big">{g.avec_notes}/{g.total_controles}</span>
                              <span className="rt-small">{g.total_controles > 0 ? Math.round((g.avec_notes / g.total_controles) * 100) : 0}% complétés</span>
                            </td>
                            <td>
                              <span className="rt-big">{Number(g.notes_pct || 0).toFixed(1).replace('.', ',')}%</span>
                              <span className="rt-small">{fmt(g.notes_entered)} saisies</span>
                            </td>
                            <td colSpan="2" className="rt-pub">
                              <span className="rt-small">Publié : {pubText(g.publie_types)}</span>
                            </td>
                          </tr>
                        ))}
                      </Fragment>
                    );
                  })}
                </tbody>
              </table>
            </div>
          )}
        </div>

        {/* RIGHT — TO PROCESS */}
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

      {/* ── 4. PUBLICATIONS + SYNTHESIS ── */}
      <div className="dash-grid">

        {/* LEFT — PUBLICATIONS */}
        <div className="panel">
          <div className="panel-head">
            <span className="panel-title">Publications</span>
            <span className="panel-head-note">{fmt(ov.groupes_publies)}/{fmt(ov.total_groupes)} groupes publiés</span>
          </div>

          {!hasStats ? (
            <div className="dash-empty">
              <div className="dash-empty-icon is-muted"><FiInbox size={18} /></div>
              <div className="dash-empty-title">Aucune publication enregistrée</div>
              <p className="dash-empty-text">Publiez les notes et les bulletins depuis la page Publications.</p>
            </div>
          ) : (
            <>
              <div className="rt-scroll">
                <table className="rt is-narrow">
                  <thead>
                    <tr>
                      <th>Filière</th>
                      <th>Notes S1</th>
                      <th>Notes S2</th>
                      <th>Bulletins</th>
                    </tr>
                  </thead>
                  <tbody>
                    {filiereStats.map(f => {
                      const pub = f.publications || {};
                      return (
                        <tr key={f.filiere_id} className="rt-plain">
                          <td><span className="rt-name">{f.filiere_nom}</span></td>
                          <td><span className="rt-big">{pub.notes_s1 || 0}/{f.total_groupes}</span><span className="rt-small">groupes</span></td>
                          <td><span className="rt-big">{pub.notes_s2 || 0}/{f.total_groupes}</span><span className="rt-small">groupes</span></td>
                          <td><span className="rt-big">{pub.bulletin || 0}/{f.total_groupes}</span><span className="rt-small">groupes</span></td>
                        </tr>
                      );
                    })}
                  </tbody>
                </table>
              </div>
              <div className="pend-foot">
                <span className="pend-foot-note">
                  {fmt(ov.publications_by_type?.notes_s1 || 0)} Notes S1 · {fmt(ov.publications_by_type?.notes_s2 || 0)} Notes S2 · {fmt(ov.publications_by_type?.bulletin || 0)} bulletins enregistrés
                </span>
                <button className="link-action" onClick={() => navigate('/admin/publications')}>
                  Gérer les publications
                </button>
              </div>
            </>
          )}
        </div>

        {/* RIGHT — YEAR OVERVIEW */}
        <div className="panel">
          <div className="panel-head">
            <span className="panel-title">Synthèse de l’année</span>
          </div>
          <div className="summ">
            <div className="summ-row">
              <span className="summ-label">Notes attendues</span>
              <span className="summ-value">{fmt(ov.notes_expected)}<small>{fmt(ov.notes_missing)} encore manquantes</small></span>
            </div>
            <div className="summ-row">
              <span className="summ-label">Notes saisies</span>
              <span className="summ-value">{fmt(ov.notes_entered)}<small>{Number(ov.notes_pct || 0).toFixed(1).replace('.', ',')} % du prévisionnel</small></span>
            </div>
            <div className="summ-row">
              <span className="summ-label">Contrôles</span>
              <span className="summ-value">{fmt(ov.total_controles)}<small>{fmt(ov.controles_avec_notes)} complets · {fmt(ov.controles_sans_notes)} sans note</small></span>
            </div>
            <div className="summ-row">
              <span className="summ-label">Unités / séquences</span>
              <span className="summ-value">{fmt(ov.total_unites)}<small>{fmt(ov.total_sequences)} séquences</small></span>
            </div>
            <div className="summ-row">
              <span className="summ-label">Étudiants</span>
              <span className="summ-value">{fmt(ov.total_students)}<small>{fmt(ov.active_students)} actifs · {fmt(ov.graduate_students)} diplômés · {fmt(ov.dropped_students)} abandonnés</small></span>
            </div>
            <div className="summ-row">
              <span className="summ-label">Diplômes édités</span>
              <span className="summ-value">{fmt(ov.total_diplomes)}<small>{fmt(ov.diplomes_printed)} imprimés · {fmt(n(ov.total_diplomes) - n(ov.diplomes_printed))} à imprimer</small></span>
            </div>
            <div className="summ-row">
              <span className="summ-label">Groupes</span>
              <span className="summ-value">{fmt(ov.total_groupes)}<small>{fmt(ov.groupes_publies)} avec au moins une publication</small></span>
            </div>
            <div className="summ-row">
              <span className="summ-label">Formateurs</span>
              <span className="summ-value">{fmt(ov.total_formateurs)}<small>équipe pédagogique</small></span>
            </div>
          </div>
        </div>
      </div>

      {/* ── 5. QUICK ACTIONS ── */}
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