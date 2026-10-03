import '../../css/components.css';

const colorMap = {
  green:  'badge-green',
  red:    'badge-red',
  blue:   'badge-blue',
  yellow: 'badge-yellow',
  orange: 'badge-orange',
  teal:   'badge-teal',
  gray:   'badge-gray',
  info:   'badge-blue',
  danger: 'badge-red',
};

// `label` is the documented prop, but children are accepted too: several call
// sites passed children and, because only `label` was rendered, those badges
// came out as empty coloured pills with no visible text.
export default function Badge({ label, children, color = 'blue', className = '', style }) {
  const text = label ?? children;
  if (text === null || text === undefined || text === '') return null;

  return (
    <span
      className={`badge ${colorMap[color] ?? 'badge-blue'}${className ? ` ${className}` : ''}`}
      style={style}
    >
      {text}
    </span>
  );
}