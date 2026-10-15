import { useEffect, useRef, useState, useSyncExternalStore } from 'react';
import { batchRunStore } from '../../stores/batchRunStore';

const COLORS = {
  generating: '#1B5E7D',
  compressing: '#2a7fa3',
  done: '#16A34A',
  error: '#DC2626',
};

const Icon = {
  pin: (
    <svg viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
      <path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z" />
      <circle cx="12" cy="10" r="3" />
    </svg>
  ),
  close: (
    <svg viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" aria-hidden="true">
      <path d="M18 6L6 18M6 6l12 12" />
    </svg>
  ),
  minus: (
    <svg viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" aria-hidden="true">
      <path d="M5 12h14" />
    </svg>
  ),
  up: (
    <svg viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
      <path d="M18 15l-6-6-6 6" />
    </svg>
  ),
  download: (
    <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
      <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4" />
      <polyline points="7 10 12 15 17 10" />
      <line x1="12" y1="15" x2="12" y2="3" />
    </svg>
  ),
  check: (
    <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" strokeWidth="2.5" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
      <path d="M20 6L9 17l-5-5" />
    </svg>
  ),
};

/**
 * Floating notification for the batch generation, rendered once at the app root.
 * Light, compact, matches the rest of the app. The download opens the browser's
 * Save As dialog (File System Access API) when available and falls back to a
 * plain same-tab download otherwise; a `<a href>` keeps the classic fallback.
 */
export default function BatchProgressNotif() {
  const run = useSyncExternalStore(batchRunStore.subscribe, batchRunStore.getSnapshot);
  const [saved, setSaved] = useState(false);
  const lastUrl = useRef(null);

  useEffect(() => {
    if (run?.downloadUrl && run.downloadUrl !== lastUrl.current) {
      lastUrl.current = run.downloadUrl;
      setSaved(false);
    }
  }, [run]);
  useEffect(() => {
    if (run?.phase === 'done' && !run.pin) {
      const t = setTimeout(batchRunStore.close, 60000);
      return () => clearTimeout(t);
    }
  }, [run]);

  const handleDownload = async () => {
    const url = run.downloadUrl;
    const filename = run.filename || 'Diplomes.rar';
    if (window.showSaveFilePicker) {
      let streamStarted = false;
      try {
        const handle = await window.showSaveFilePicker({
          suggestedName: filename,
          types: [{ description: 'Archive RAR', accept: { 'application/vnd.rar': ['.rar'] } }],
        });
        const res = await fetch(url);
        if (!res.ok || !res.body) throw new Error('unreachable');
        const writable = await handle.createWritable();
        streamStarted = true;
        await res.body.pipeTo(writable);
        setSaved(true);
        return;
      } catch (err) {
        if (err?.name === 'AbortError') return;
        if (streamStarted) return; // stream failed mid-way: do not launch a second download
      }
    }
    window.location.assign(url);
  };

  if (!run) return null;

  const pct = run.total ? Math.round((run.done / run.total) * 100) : 0;
  const color = COLORS[run.phase] || COLORS.generating;
  const canMinimize = run.phase === 'generating' || run.phase === 'compressing';

  return (
    <div
      style={{
        position: 'fixed',
        top: 12,
        left: '50%',
        transform: 'translateX(-50%)',
        zIndex: 2000,
        width: 360,
        maxWidth: 'calc(100vw - 24px)',
        fontFamily: 'inherit',
        textAlign: 'left',
        fontSize: 13,
      }}
    >
      {run.minimized ? (
        <div
          onClick={batchRunStore.toggleMin}
          role="button"
          title="Voir le détail"
          style={{
            display: 'flex',
            alignItems: 'center',
            gap: 8,
            background: '#fff',
            border: '1px solid #e5e7eb',
            borderRadius: 999,
            padding: '7px 14px',
            color: '#374151',
            fontSize: 12.5,
            fontWeight: 600,
            cursor: 'pointer',
            boxShadow: '0 6px 24px rgba(15,23,42,0.12)',
          }}
        >
          <span
            style={{ width: 7, height: 7, borderRadius: '50%', background: color, flexShrink: 0 }}
          />
          <span style={{ whiteSpace: 'nowrap', overflow: 'hidden', textOverflow: 'ellipsis' }}>
            Génération —{' '}
            {run.phase === 'done' ? `Terminé (${run.generated})` : run.phase === 'error' ? 'Erreur' : `${run.generated} / ${run.total}`}
          </span>
          <span style={{ marginLeft: 'auto', color: '#9ca3af', display: 'flex' }}>{Icon.up}</span>
        </div>
      ) : (
        <div
          style={{
            background: '#fff',
            border: '1px solid #e5e7eb',
            borderRadius: 12,
            overflow: 'hidden',
            color: '#374151',
            boxShadow: '0 10px 36px rgba(15,23,42,0.14)',
          }}
        >
          <div style={{ height: 3, background: '#f3f4f6', position: 'relative' }}>
            {run.phase === 'generating' && (
              <div style={{ position: 'absolute', inset: 0, background: color, width: `${pct}%`, transition: 'width .25s ease' }} />
            )}
            {run.phase === 'compressing' && (
              <div className="bnotif-shimmer" style={{ position: 'absolute', inset: 0, background: color }} />
            )}
          </div>

          <div style={{ padding: '9px 12px 0' }}>
            <div style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
              <span
                style={{
                  width: 7,
                  height: 7,
                  borderRadius: '50%',
                  background: color,
                  flexShrink: 0,
                  animation: (run.phase === 'generating' || run.phase === 'compressing') ? 'bnotif-pulse 1.2s ease-in-out infinite' : undefined,
                }}
              />
              <span style={{ fontSize: 12.5, fontWeight: 700, color: '#143d52', letterSpacing: '.01em' }}>
                Génération des diplômes
              </span>
              <span style={{ flex: 1 }} />
              <div style={{ display: 'flex', gap: 2 }}>
                {canMinimize && (
                  <button style={styles.iconBtn} onClick={batchRunStore.toggleMin} title="Réduire">
                    {Icon.minus}
                  </button>
                )}
                <button
                  style={{ ...styles.iconBtn, color: run.pin ? color : '#9ca3af' }}
                  onClick={batchRunStore.togglePin}
                  title={run.pin ? 'Dépingler (se fermera tout seul)' : 'Épingler (rester affiché)'}
                >
                  {Icon.pin}
                </button>
                <button style={styles.iconBtn} onClick={batchRunStore.close} title="Arrêter et fermer">
                  {Icon.close}
                </button>
              </div>
            </div>
          </div>

          <div style={{ padding: '8px 12px 10px' }}>
            {run.phase === 'generating' && (
              <>
                <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'baseline' }}>
                  <span style={{ fontSize: 12, color: '#6b7280' }}>
                    {run.done} / {run.total} étudiant{run.total > 1 ? 's' : ''}
                    {run.failed > 0 && <span style={{ color: '#DC2626' }}> — {run.failed} en erreur</span>}
                  </span>
                  <span style={{ fontSize: 12, fontWeight: 700, color: '#143d52', fontVariantNumeric: 'tabular-nums' }}>{pct}%</span>
                </div>
                <div style={styles.track}>
                  <div style={{ ...styles.fill, width: `${pct}%`, background: color }} />
                </div>
                <div style={{ marginTop: 6, fontSize: 11.5, color: '#9ca3af' }}>
                  {run.stopping ? (
                    'Arrêt après l’étudiant en cours…'
                  ) : run.current ? (
                    <span style={{ display: 'block', whiteSpace: 'nowrap', overflow: 'hidden', textOverflow: 'ellipsis' }}>
                      En cours : <strong style={{ color: '#4b5563', fontWeight: 600 }}>{run.current}</strong>
                    </span>
                  ) : (
                    'Préparation…'
                  )}
                </div>
                {!run.stopping && (
                  <button
                    onClick={batchRunStore.cancel}
                    style={{
                      marginTop: 8,
                      width: '100%',
                      padding: '6px 0',
                      fontSize: 12,
                      fontWeight: 600,
                      background: '#fff',
                      border: '1px solid #d1d5db',
                      color: '#4b5563',
                      borderRadius: 7,
                      cursor: 'pointer',
                    }}
                  >
                    Arrêter
                  </button>
                )}
              </>
            )}

            {run.phase === 'compressing' && (
              <div style={{ display: 'flex', alignItems: 'center', gap: 10 }}>
                <span className="bnotif-spinner" style={{ width: 20, height: 20, flexShrink: 0 }} />
                <div>
                  <div style={{ fontSize: 12.5, fontWeight: 600 }}>Compression de l’archive…</div>
                  <div style={{ fontSize: 11.5, color: '#9ca3af' }}>
                    {run.generated} diplôme{run.generated > 1 ? 's' : ''} regroupé{run.generated > 1 ? 's' : ''} par filière
                  </div>
                </div>
              </div>
            )}

            {run.phase === 'done' && (
              <div>
                <div style={{ display: 'flex', alignItems: 'center', gap: 6, color }}>
                  {Icon.check}
                  <span style={{ fontSize: 13, fontWeight: 700 }}>
                    Terminé — {run.generated} diplôme{run.generated > 1 ? 's' : ''}
                    {run.failed > 0 && <span style={{ color: '#DC2626' }}> ({run.failed} en erreur)</span>}
                  </span>
                </div>
                <div style={{ marginTop: 3, fontSize: 11.5, color: '#9ca3af' }}>
                  Fichiers livrés uniquement : aucun diplôme n’est enregistré en base.
                </div>
                {run.downloadUrl && (
                  <div>
                    <a
                      href={run.downloadUrl}
                      onClick={(e) => { e.preventDefault(); handleDownload(); }}
                      style={{
                        marginTop: 8,
                        display: 'flex',
                        alignItems: 'center',
                        justifyContent: 'center',
                        gap: 8,
                        width: '100%',
                        padding: '8px 0',
                        fontSize: 13,
                        fontWeight: 700,
                        textDecoration: 'none',
                        background: '#1B5E7D',
                        color: '#fff',
                        borderRadius: 7,
                      }}
                    >
                      {Icon.download}
                      Télécharger les diplômes (.rar)
                    </a>
                    {saved && (
                      <div style={{ marginTop: 6, fontSize: 11.5, color: '#16A34A', fontWeight: 600 }}>
                        Enregistrement terminé.
                      </div>
                    )}
                    <div style={{ marginTop: 6, fontSize: 10.5, color: '#9ca3af', lineHeight: 1.4 }}>
                      Cliquez pour choisir l’emplacement. Sinon, le fichier part dans vos Téléchargements.
                    </div>
                  </div>
                )}
                {!run.pin && (
                  <div style={{ marginTop: 4, fontSize: 10.5, color: '#c4c9d1' }}>
                    Se ferme dans une minute — cliquez sur l’épingle pour le garder.
                  </div>
                )}
              </div>
            )}

            {run.phase === 'error' && (
              <div>
                <div style={{ fontSize: 13, fontWeight: 700, color }}>{run.message}</div>
                {run.errors?.length > 0 && (
                  <ul style={{ margin: '6px 0 0', padding: '0 0 0 16px', fontSize: 11.5, color: '#DC2626', maxHeight: 90, overflow: 'auto' }}>
                    {run.errors.slice(0, 10).map((e, i) => <li key={i}>{e}</li>)}
                  </ul>
                )}
              </div>
            )}
          </div>

          <style>{`
            .bnotif-pulse { animation: bnotif-pulse 1.2s ease-in-out infinite; }
            @keyframes bnotif-pulse {
              0%, 100% { box-shadow: 0 0 0 0 ${color}, 0 0 0 0 rgba(27,94,125,0); }
              50% { box-shadow: 0 0 0 0 ${color}, 0 0 0 4px rgba(27,94,125,0.12); }
            }
            .bnotif-shimmer {
              background-image: linear-gradient(90deg, rgba(255,255,255,0) 0%, rgba(255,255,255,0.8) 50%, rgba(255,255,255,0) 100%);
              background-size: 200% 100%;
              animation: bnotif-shimmer 1.1s linear infinite;
            }
            @keyframes bnotif-shimmer {
              from { background-position: 200% 0; }
              to { background-position: -200% 0; }
            }
            .bnotif-spinner {
              border-radius: 50%;
              border: 2.5px solid #e5e7eb;
              border-top-color: #1B5E7D;
              animation: bnotif-rotate .8s linear infinite;
            }
            @keyframes bnotif-rotate { to { transform: rotate(360deg); } }
          `}</style>
        </div>
      )}
    </div>
  );
}

const styles = {
  iconBtn: {
    display: 'flex',
    alignItems: 'center',
    justifyContent: 'center',
    width: 24,
    height: 24,
    border: 'none',
    borderRadius: 5,
    background: 'transparent',
    color: '#9ca3af',
    cursor: 'pointer',
  },
  track: {
    height: 6,
    background: '#e5e7eb',
    borderRadius: 999,
    overflow: 'hidden',
    marginTop: 6,
  },
  fill: {
    height: '100%',
    borderRadius: 999,
    transition: 'width .25s ease',
  },
};