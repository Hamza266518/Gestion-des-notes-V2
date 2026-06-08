import { useState, useEffect } from 'react';
import { adminApi } from '../../api/admin';
import { useToast } from '../../context/ToastContext';
import handleApiError from '../../utils/errorHandler';
import ActivityLog from '../../components/common/ActivityLog';
import Spinner from '../../components/common/Spinner';
import { FiFilter, FiX, FiSearch, FiChevronLeft, FiChevronRight } from 'react-icons/fi';
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
  const [filters, setFilters] = useState({
    action_type: '',
    search: '',
    date_from: '',
    date_to: '',
  });
  const [showFilters, setShowFilters] = useState(false);
  const [page, setPage] = useState(1);
  const [lastPage, setLastPage] = useState(1);
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

  const activeFilters = Object.entries(filters).filter(([k, v]) => v !== '').length;

  return (
    <div className="page">
      <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 20 }}>
        <h2 className="page-title" style={{ margin: 0 }}>Journal d'activité</h2>
        <button
          className={`btn btn-sm ${showFilters ? 'btn-primary' : 'btn-outline'}`}
          onClick={() => setShowFilters(!showFilters)}
        >
          <FiFilter size={14} style={{ marginRight: 6 }} />
          Filtres {activeFilters > 0 && `(${activeFilters})`}
        </button>
      </div>

      {showFilters && (
        <div className="card" style={{ marginBottom: 20, padding: '14px 18px' }}>
          <div style={{ display: 'flex', gap: 12, flexWrap: 'wrap', alignItems: 'flex-end' }}>
            <div>
              <label style={{ fontSize: 11, color: 'var(--gray-500)', display: 'block', marginBottom: 2 }}>Type d'action</label>
              <select
                className="form-select form-select-sm"
                value={filters.action_type}
                onChange={e => setFilters(f => ({ ...f, action_type: e.target.value }))}
                style={{ minWidth: 150 }}
              >
                {ACTION_TYPES.map(a => (
                  <option key={a.value} value={a.value}>{a.label}</option>
                ))}
              </select>
            </div>
            <div>
              <label style={{ fontSize: 11, color: 'var(--gray-500)', display: 'block', marginBottom: 2 }}>Recherche</label>
              <div style={{ position: 'relative' }}>
                <FiSearch size={14} style={{ position: 'absolute', left: 8, top: '50%', transform: 'translateY(-50%)', color: 'var(--gray-400)' }} />
                <input
                  type="text"
                  className="form-input form-input-sm"
                  placeholder="Admin ou description..."
                  value={filters.search}
                  onChange={e => setFilters(f => ({ ...f, search: e.target.value }))}
                  style={{ paddingLeft: 28, width: 200 }}
                />
                {filters.search && (
                  <FiX size={14} style={{ position: 'absolute', right: 8, top: '50%', transform: 'translateY(-50%)', cursor: 'pointer', color: 'var(--gray-400)' }} onClick={() => setFilters(f => ({ ...f, search: '' }))} />
                )}
              </div>
            </div>
            <div>
              <label style={{ fontSize: 11, color: 'var(--gray-500)', display: 'block', marginBottom: 2 }}>Du</label>
              <input
                type="date"
                className="form-input form-input-sm"
                value={filters.date_from}
                onChange={e => setFilters(f => ({ ...f, date_from: e.target.value }))}
                style={{ width: 150 }}
              />
            </div>
            <div>
              <label style={{ fontSize: 11, color: 'var(--gray-500)', display: 'block', marginBottom: 2 }}>Au</label>
              <input
                type="date"
                className="form-input form-input-sm"
                value={filters.date_to}
                onChange={e => setFilters(f => ({ ...f, date_to: e.target.value }))}
                style={{ width: 150 }}
              />
            </div>
            {activeFilters > 0 && (
              <button className="btn btn-sm btn-outline" onClick={() => setFilters({ action_type: '', search: '', date_from: '', date_to: '' })}>
                <FiX size={14} style={{ marginRight: 4 }} /> Réinitialiser
              </button>
            )}
          </div>
        </div>
      )}

      {loading ? (
        <div style={{ display: 'flex', justifyContent: 'center', padding: 40 }}><Spinner /></div>
      ) : error ? (
        <div className="alert alert-error">{error}</div>
      ) : (
        <>
          <div className="card">
            <div className="card-body">
              <ActivityLog entries={entries} limit={entries.length} />
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
