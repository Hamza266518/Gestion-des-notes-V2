import '../../css/components.css';

export function SkeletonBlock({ width, height, style }) {
  return <div className="skeleton" style={{ width: width || '100%', height: height || 16, ...style }} />;
}

export function SkeletonCard({ children, style }) {
  return <div className="skeleton-card" style={style}>{children}</div>;
}

export function SkeletonBulletin() {
  return (
    <div className="page-container">
      <div className="page-header">
        <div className="skeleton" style={{ width: 180, height: 28 }} />
      </div>
      <SkeletonCard>
        <div className="skeleton-header-bar" />
        <div className="skeleton-grid skeleton-grid-2">
          {[...Array(6)].map((_, i) => (
            <div key={i} className="skeleton" style={{ height: 14, width: i % 2 === 0 ? '70%' : '55%' }} />
          ))}
        </div>
        <div>
          {[...Array(4)].map((_, i) => (
            <div key={i} className="skeleton-row">
              <div className="skeleton skeleton-cell" style={{ height: 14 }} />
              <div className="skeleton skeleton-cell-sm" style={{ height: 14 }} />
              <div className="skeleton skeleton-cell-sm" style={{ height: 14 }} />
              <div className="skeleton skeleton-cell-sm" style={{ height: 14 }} />
              <div className="skeleton skeleton-cell-sm" style={{ height: 14 }} />
              <div className="skeleton skeleton-cell-sm" style={{ height: 14 }} />
            </div>
          ))}
        </div>
        <div className="skeleton-grid skeleton-grid-3" style={{ borderTop: '1px solid var(--gray-200)', margin: '0 24px', padding: '20px 0' }}>
          {[...Array(6)].map((_, i) => (
            <div key={i} className="skeleton" style={{ height: 36, borderRadius: 8 }} />
          ))}
        </div>
      </SkeletonCard>
    </div>
  );
}

export function SkeletonTable({ rows = 4, cols = 4 }) {
  return (
    <div className="page">
      <div className="page-header">
        <div className="skeleton" style={{ width: 200, height: 28 }} />
      </div>
      <div className="card">
        <div className="card-header">
          <div className="skeleton" style={{ width: 180, height: 20 }} />
        </div>
        <div className="card-body p-0">
          <div className="table-wrap">
            <table className="table mb-0">
              <thead>
                <tr>
                  {[...Array(cols)].map((_, i) => (
                    <th key={i}>
                      <div className="skeleton" style={{ width: i === 0 ? 120 : 60, height: 14 }} />
                    </th>
                  ))}
                </tr>
              </thead>
              <tbody>
                {[...Array(rows)].map((_, r) => (
                  <tr key={r}>
                    {[...Array(cols)].map((_, c) => (
                      <td key={c}>
                        <div className="skeleton" style={{ width: c === 0 ? '70%' : '50%', height: 14 }} />
                      </td>
                    ))}
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </div>
  );
}

export function SkeletonDashboard() {
  return (
    <div className="page">
      <div className="page-header">
        <div className="skeleton" style={{ width: 280, height: 28 }} />
      </div>
      <div className="stats-grid">
        {[...Array(4)].map((_, i) => (
          <div key={i} className="stat-card">
            <div className="skeleton" style={{ width: 80, height: 14, marginBottom: 8 }} />
            <div className="skeleton" style={{ width: 40, height: 32, borderRadius: 6 }} />
          </div>
        ))}
      </div>
      <div className="card" style={{ marginTop: 24 }}>
        <div className="card-header">
          <div className="skeleton" style={{ width: 160, height: 20 }} />
        </div>
        <div className="card-body p-0">
          <div className="table-wrap">
            <table className="table mb-0">
              <thead>
                <tr>
                  <th><div className="skeleton" style={{ width: 100, height: 14 }} /></th>
                  <th><div className="skeleton" style={{ width: 80, height: 14 }} /></th>
                  <th><div className="skeleton" style={{ width: 80, height: 14 }} /></th>
                </tr>
              </thead>
              <tbody>
                {[...Array(5)].map((_, r) => (
                  <tr key={r}>
                    {[...Array(3)].map((_, c) => (
                      <td key={c}>
                        <div className="skeleton" style={{ width: c === 0 ? '60%' : '40%', height: 14 }} />
                      </td>
                    ))}
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </div>
  );
}
