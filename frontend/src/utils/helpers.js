export function formatNiveau(numero) {
  if (!numero) return '—';
  if (numero === 1) return '1ère année';
  return `${numero}ème année`;
}

const MENTION_THRESHOLDS = [
  { min: 16, label: 'Tres Bien', color: 'green' },
  { min: 14, label: 'Bien', color: 'teal' },
  { min: 12, label: 'Assez Bien', color: 'yellow' },
  { min: 10, label: 'Passable', color: 'orange' },
  { min: 0, label: 'Insuffisant', color: 'red' },
];

export function getMention(score) {
  if (score === null || score === undefined || isNaN(score)) {
    return { label: '', color: 'gray' };
  }
  const match = MENTION_THRESHOLDS.find(t => score >= t.min);
  return match || { label: 'Insuffisant', color: 'red' };
}

export function formatTimeAgo(date) {
  const d = new Date(date);
  const now = new Date();
  const seconds = Math.floor((now - d) / 1000);
  if (seconds < 60) return `il y a ${seconds}s`;
  const minutes = Math.floor(seconds / 60);
  if (minutes < 60) return `il y a ${minutes}min`;
  const hours = Math.floor(minutes / 60);
  if (hours < 24) return `il y a ${hours}h`;
  const days = Math.floor(hours / 24);
  if (days < 7) return `il y a ${days}j`;
  return d.toLocaleDateString('fr-FR', { day: '2-digit', month: '2-digit', year: 'numeric' }) +
    ' a ' + d.toLocaleTimeString('fr-FR', { hour: '2-digit', minute: '2-digit' });
}

export function calculateStats(notes) {
  const valid = notes.filter(n => n.valeur !== null && n.valeur !== undefined);
  if (valid.length === 0) {
    return { average: '—', highest: '—', lowest: '—', passing: 0, total: 0 };
  }
  const sum = valid.reduce((s, n) => s + n.valeur, 0);
  return {
    average: (sum / valid.length).toFixed(2),
    highest: Math.max(...valid.map(n => n.valeur)).toFixed(2),
    lowest: Math.min(...valid.map(n => n.valeur)).toFixed(2),
    passing: valid.filter(n => n.valeur >= 10).length,
    total: valid.length,
  };
}
