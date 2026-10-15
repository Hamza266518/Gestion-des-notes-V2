import { useState, useRef, useEffect, useMemo } from 'react';
import { FiChevronDown, FiCheck, FiSearch, FiX } from 'react-icons/fi';

// Modern searchable combobox used inside the smart filter bar.
// props:
//   label        – field label shown on the trigger when a value is active
//   value        – selected option id (string/number) or '' for "all"
//   options      – [{ id, label }]
//   onChange(id) – selection handler ('' = all)
//   placeholder  – placeholder for "all" state (default "Tous/Toutes")
//   disabled     – disabled state (cascading filters)
//   allLabel     – custom label for the "all" row
export default function FilterSelect({
  label,
  value,
  options = [],
  onChange,
  placeholder = 'Tous',
  disabled = false,
  allLabel,
  block = false,
}) {
  const [open, setOpen] = useState(false);
  const [q, setQ] = useState('');
  const rootRef = useRef(null);
  const searchRef = useRef(null);

  const isSet = value !== '' && value !== null && value !== undefined;
  const selected = useMemo(() => options.find(o => String(o.id) === String(value)), [options, value]);

  const filtered = useMemo(() => {
    const s = q.trim().toLowerCase();
    if (!s) return options;
    return options.filter(o => String(o.label).toLowerCase().includes(s));
  }, [options, q]);

  useEffect(() => {
    if (!open) return;
    const onDoc = (e) => {
      if (rootRef.current && !rootRef.current.contains(e.target)) setOpen(false);
    };
    const onKey = (e) => { if (e.key === 'Escape') setOpen(false); };
    document.addEventListener('mousedown', onDoc);
    document.addEventListener('keydown', onKey);
    const t = setTimeout(() => searchRef.current?.focus(), 30);
    return () => {
      document.removeEventListener('mousedown', onDoc);
      document.removeEventListener('keydown', onKey);
      clearTimeout(t);
    };
  }, [open]);

  useEffect(() => { if (open) setQ(''); }, [open]);

  const pick = (id) => { onChange(id); setOpen(false); };

  return (
    <div className={`smart-filter-select${block ? ' smart-filter-select--block' : ''}${disabled ? ' is-disabled' : ''}${open ? ' is-open' : ''}`} ref={rootRef}>
      <button
        type="button"
        className={`sf-trigger${isSet ? ' sf-active' : ''}`}
        onClick={() => !disabled && setOpen(o => !o)}
        disabled={disabled}
      >
        <span className="sf-trigger-inner">
          <span className="sf-label">{label}</span>
          <span className="sf-value">{selected ? selected.label : (allLabel || placeholder)}</span>
        </span>
        <FiChevronDown size={13} className="sf-chevron" />
      </button>

      {open && (
        <div className="sf-popover">
          <div className="sf-search">
            <FiSearch size={13} />
            <input
              ref={searchRef}
              value={q}
              onChange={e => setQ(e.target.value)}
              placeholder="Rechercher..."
            />
            {q && <button type="button" onClick={() => setQ('')}><FiX size={12} /></button>}
          </div>

          <div className="sf-list">
            <button
              type="button"
              className={`sf-option${!isSet ? ' selected' : ''}`}
              onClick={() => pick('')}
            >
              <span>{allLabel || placeholder}</span>
              {!isSet && <FiCheck size={13} />}
            </button>

            {filtered.map(o => (
              <button
                key={o.id}
                type="button"
                className={`sf-option${String(o.id) === String(value) ? ' selected' : ''}`}
                onClick={() => pick(o.id)}
              >
                <span>{o.label}</span>
                {String(o.id) === String(value) && <FiCheck size={13} />}
              </button>
            ))}

            {filtered.length === 0 && <div className="sf-empty">Aucun résultat</div>}
          </div>
        </div>
      )}
    </div>
  );
}
