import { useState, useEffect, useRef } from 'react';
import { FiSearch, FiX, FiRotateCcw } from 'react-icons/fi';

// Smart filter bar: debounced search input + FilterSelect pills + active chips.
// props:
//   value        – { search: string, ...filterFields }
//   onChange(next)          – called with full filter object (search debounced)
//   chips        – [{ key, label, onRemove }] – active filter chips (excl. search)
//   onClear      – clears everything (called by "Réinitialiser")
//   placeholder  – search placeholder
export default function FilterBar({
  value,
  onChange,
  chips = [],
  onClear,
  placeholder = 'Rechercher...',
  debounceMs = 300,
  showSearch = true,
  center = false,
  actions,
  children,
}) {
  const [search, setSearch] = useState(value.search ?? '');
  const first = useRef(true);
  const valueRef = useRef(value);

  useEffect(() => { valueRef.current = value; });

  // Debounce search -> parent (1 request instead of one per keystroke)
  useEffect(() => {
    if (first.current) { first.current = false; return; }
    const push = () => {
      const v = valueRef.current;
      if ((v.search ?? '') !== search) onChange({ ...v, search });
    };
    if (debounceMs <= 0) { push(); return; }
    const t = setTimeout(push, debounceMs);
    return () => clearTimeout(t);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [search]);

  // Reset local input when parent clears (e.g. "Réinitialiser")
  useEffect(() => {
    if ((value.search ?? '') === '' && search !== '') setSearch('');
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [value.search]);

  const hasActive = (showSearch && search.trim() !== '') || chips.length > 0;

  return (
    <div className="smart-filter">
      <div className="sf-row" style={center ? { justifyContent: 'center' } : undefined}>
        {showSearch && (
          <div className="sf-searchbox">
            <FiSearch size={14} />
            <input
              value={search}
              onChange={e => setSearch(e.target.value)}
              placeholder={placeholder}
            />
            {search && (
              <button type="button" onClick={() => setSearch('')} aria-label="Effacer">
                <FiX size={13} />
              </button>
            )}
          </div>
        )}

        {children}

        {hasActive && (
          <button type="button" className="sf-reset" onClick={() => { setSearch(''); onClear?.(); }}>
            <FiRotateCcw size={13} /> Réinitialiser
          </button>
        )}

        {actions && <div className="sf-actions">{actions}</div>}
      </div>

      {chips.length > 0 && (
        <div className="sf-chips">
          {chips.map(c => (
            <span key={c.key} className="sf-chip">
              {c.label}
              <button type="button" onClick={c.onRemove} aria-label="Retirer">
                <FiX size={11} />
              </button>
            </span>
          ))}
        </div>
      )}
    </div>
  );
}
