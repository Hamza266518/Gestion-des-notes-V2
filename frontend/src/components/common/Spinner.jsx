import '../../css/components.css';

export default function Spinner({ message = 'Chargement...' }) {
  return (
    <div className="spinner-wrap">
      <div className="spinner" />
      {message && <p className="spinner-message">{message}</p>}
    </div>
  );
}