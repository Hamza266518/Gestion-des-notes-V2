import { useState, useEffect, useRef } from 'react';
import { adminApi } from '../../api/admin';
import { useToast } from '../../context/ToastContext';
import handleApiError from '../../utils/errorHandler';
import ActivityLog from '../../components/common/ActivityLog';
import Spinner from '../../components/common/Spinner';
import FilterBar from '../../components/common/FilterBar';
import FilterSelect from '../../components/common/FilterSelect';
import { FiChevronLeft, FiChevronRight, FiRefreshCw } from 'react-icons/fi';
import usePersistedState from '../../hooks/usePersistedState';
import '../../css/components.css';
import '../../css/layout.css';

const ACTION_TYPES = [
  { value: '', label: 'Tous les types' },
  { value: 'create', label: 'Créations' },
  { value: 'update', label: 'Modifications' },
  { value: 'delete', label: 'Suppressions' },
  { value: 'publish', label: 'Publications' },
  { value: 'unpublish', label: 'Dépublications' },
];

export default function Journal() {
  const [entries, setEntries] = useState([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);
  const [filters, setFilters, resetFilters] = usePersistedState('journal', {
    action_type: '',
    search: '',
    date_from: '',
    date_to: '',
  });
  const [page, setPage] = useState(1);
  const [lastPage, setLastPage] = useState(1);
  const [newSince, setNewSince] = useState(null);
  const [unreadOnLoad, setUnreadOnLoad] = useState(0);
  const metaCaptured = useRef(false);
  const toast = useToast();

  const fetchLogs = async (p = page) => {
    setLoading(true);
    setError(null);
    try {
      const params = {
        paginate: true,
        per_page: 20,
        page: p,
      };
      if (filters.action_type) params.action_type = filters.action_type;
      if (filters.search) params.search = filters.search;
      if (filters.date_from) params.date_from = filters.date_from;
      if (filters.date_to) params.date_to = filters.date_to;

      const res = await adminApi.getActivityLogs(params);
      const data = res.data.data;

      // Capture the marker of the previous visit once, before marking the
      // journal as read: these entries stay highlighted for this session.
      if (!metaCaptured.current) {
        metaCaptured.current = true;
        setNewSince(res.data.meta?.last_seen_at ?? null);
        setUnreadOnLoad(res.data.meta?.unseen_count ?? 0);
        adminApi.markActivitySeen()
          .then(() => window.dispatchEvent(new Event('activity-seen')))
          .catch(() => {});
      }

      if (Array.isArray(data)) {
        setEntries(data);
        setLastPage(1);
      } else if (data.data) {
        setEntries(data.data);
        setLastPage(data.last_page || 1);
      }
    } catch (err) {
      handleApiError(err, toast);
      setError('Erreur lors du chargement du journal');
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    fetchLogs(1);
    setPage(1);
  }, [filters]);

  useEffect(() => {
    fetchLogs(page);
  }, [page]);

  return (
    <div className="page">
      <div style={{ marginBottom: 16, display: 'flex', alignItems: 'center', justifyContent: 'space-between', gap: 12, flexWrap: 'wrap' }}>
        <div>
          <h2 className="page-title" style={{ margin: 0 }}>Journal d'activité</h2>
          <p style={{ margin: '4px 0 0', fontSize: 13, color: 'var(--gray-500)' }}>
            Tout ce que les administrateurs ont modifié, du plus récent au plus ancien.
          </p>
        </div>
        <button
          className="btn btn-sm btn-outline"
          onClick={() => fetchLogs(page)}
          disabled={loading}
          title="Actualiser le journal"
        >
          <FiRefreshCw size={14} style={{ marginRight: 5 }} /> Actualiser
        </button>
      </div>

      {unreadOnLoad > 0 && (
        <div className="alert alert-info" style={{ marginBottom: 16 }}>
          <strong>{unreadOnLoad}</strong> nouvelle{unreadOnLoad > 1 ? 's' : ''} activité{unreadOnLoad > 1 ? 's' : ''} depuis votre dernière visite.
        </div>
      )}

      <FilterBar
        value={filters}
        onChange={setFilters}
        placeholder="Admin ou description..."
        onClear={resetFilters}
        chips={[
          filters.action_type && {
            key: 'action_type',
            label: ACTION_TYPES.find(a => a.value === filters.action_type)?.label ?? '',
            onRemove: () => setFilters(f => ({ ...f, action_type: '' })),
          },
          filters.date_from && {
            key: 'date_from',
            label: `Du: ${filters.date_from}`,
            onRemove: () => setFilters(f => ({ ...f, date_from: '' })),
          },
          filters.date_to && {
            key: 'date_to',
            label: `Au: ${filters.date_to}`,
            onRemove: () => setFilters(f => ({ ...f, date_to: '' })),
          },
        ].filter(Boolean)}
      >
        <FilterSelect
          label="Type"
          value={filters.action_type}
          options={ACTION_TYPES.filter(a => a.value !== '').map(a => ({ id: a.value, label: a.label }))}
          onChange={(v) => setFilters(f => ({ ...f, action_type: v }))}
          placeholder="Tous les types"
          allLabel="Tous les types"
        />
        <div style={{ display: 'flex', flexDirection: 'column', gap: 2, justifyContent: 'center' }}>
          <span style={{ fontSize: 10, fontWeight: 700, textTransform: 'uppercase', letterSpacing: '0.06em', color: 'var(--gray-400)', lineHeight: 1.2 }}>Du</span>
          <input
            type="date"
            className="form-input form-input-sm"
            value={filters.date_from}
            onChange={e => setFilters(f => ({ ...f, date_from: e.target.value }))}
            style={{ width: 150, border: 'none', background: 'transparent', padding: 0, height: 'auto', fontSize: 13.5 }}
          />
        </div>
        <div style={{ display: 'flex', flexDirection: 'column', gap: 2, justifyContent: 'center' }}>
          <span style={{ fontSize: 10, fontWeight: 700, textTransform: 'uppercase', letterSpacing: '0.06em', color: 'var(--gray-400)', lineHeight: 1.2 }}>Au</span>
          <input
            type="date"
            className="form-input form-input-sm"
            value={filters.date_to}
            onChange={e => setFilters(f => ({ ...f, date_to: e.target.value }))}
            style={{ width: 150, border: 'none', background: 'transparent', padding: 0, height: 'auto', fontSize: 13.5 }}
          />
        </div>
      </FilterBar>

      {loading ? (
        <div style={{ display: 'flex', justifyContent: 'center', padding: 40 }}><Spinner /></div>
      ) : error ? (
        <div className="alert alert-error">{error}</div>
      ) : (
        <>
          <div className="card">
            <div className="card-body">
              <ActivityLog entries={entries} limit={entries.length} newSince={newSince} />
            </div>
          </div>

          {lastPage > 1 && (
            <div style={{ display: 'flex', justifyContent: 'center', gap: 10, marginTop: 16, alignItems: 'center' }}>
              <button
                className="btn btn-sm btn-outline"
                disabled={page <= 1}
                onClick={() => setPage(p => Math.max(1, p - 1))}
              >
                <FiChevronLeft size={14} style={{ marginRight: 4 }} /> Précédent
              </button>
              <span style={{ fontSize: 13, color: 'var(--gray-500)' }}>Page {page} / {lastPage}</span>
              <button
                className="btn btn-sm btn-outline"
                disabled={page >= lastPage}
                onClick={() => setPage(p => Math.min(lastPage, p + 1))}
              >
                Suivant <FiChevronRight size={14} style={{ marginLeft: 4 }} />
              </button>
            </div>
          )}
        </>
      )}
    </div>
  );
}
