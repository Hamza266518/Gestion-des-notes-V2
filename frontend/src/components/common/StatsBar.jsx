import '../css/components.css';

export default function StatsBar({ average, highest, lowest, passing, total }) {
  return (
    <div className="stats-bar">
      <span>Moyenne: <strong>{average}</strong></span>
      <span>Max: <strong>{highest}</strong></span>
      <span>Min: <strong>{lowest}</strong></span>
      <span>Reussis: <strong>{passing}/{total}</strong></span>
    </div>
  );
}
