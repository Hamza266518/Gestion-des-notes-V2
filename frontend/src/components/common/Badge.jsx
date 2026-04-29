import '../../css/components.css';

const colorMap = {
  green:  'badge-green',
  red:    'badge-red',
  blue:   'badge-blue',
  yellow: 'badge-yellow',
  orange: 'badge-orange',
  teal:   'badge-teal',
  gray:   'badge-gray',
};

export default function Badge({ label, color = 'blue' }) {
  return (
    <span className={`badge ${colorMap[color] ?? 'badge-blue'}`}>
      {label}
    </span>
  );
}