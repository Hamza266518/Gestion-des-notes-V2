export function formatNiveau(numero) {
  if (!numero) return '—';
  if (numero === 1) return '1ère année';
  return `${numero}ème année`;
}

const MENTION_THRESHOLDS = [
  { min: 16, label: 'Très Bien', color: 'green', class: 'mention-tres-bien' },
  { min: 14, label: 'Bien', color: 'teal', class: 'mention-bien' },
  { min: 12, label: 'Assez Bien', color: 'yellow', class: 'mention-assez-bien' },
  { min: 10, label: 'Passable', color: 'orange', class: 'mention-passable' },
  { min: 0, label: 'Insuffisant', color: 'red', class: 'mention-insuffisant' },
];

export function getMention(score) {
  if (score === null || score === undefined || isNaN(score)) {
    return { label: '', color: 'gray', class: '' };
  }
  const match = MENTION_THRESHOLDS.find(t => score >= t.min);
  return match || { label: 'Insuffisant', color: 'red', class: 'mention-insuffisant' };
}

const ARABIC_MONTHS = [
  'يانفبر', 'فبراير', 'مارس', 'أبريل', 'ماي', 'يونيو',
  'يوليوز', 'غشت', 'شتنبر', 'أكتوبر', 'نونبر', 'دجنبر'
];

const ARABIC_MONTH_LOOKUP = [
  ['يانفبر', 'يناير'],
  ['فبراير'],
  ['مارس'],
  ['أبريل', 'ابريل'],
  ['ماي', 'مايو'],
  ['يونيو', 'يونية'],
  ['يوليوز', 'يوليو'],
  ['غشت', 'أغسطس'],
  ['شتنبر', 'سبتمبر'],
  ['أكتوبر'],
  ['نونبر', 'نوفمبر'],
  ['دجنبر', 'ديسمبر'],
].flatMap((names, index) => names.map(name => [name, index + 1]));

function foldArabic(text) {
  return String(text).replace(/[أإآ]/g, 'ا').replace(/ى/g, 'ي');
}

export function toWesternDigits(text) {
  return String(text)
    .replace(/[\u0660-\u0669]/g, c => String.fromCharCode(c.charCodeAt(0) - 0x0660 + 0x30))
    .replace(/[\u06F0-\u06F9]/g, c => String.fromCharCode(c.charCodeAt(0) - 0x06F0 + 0x30));
}

export function arabicMonthToNumber(name) {
  const clean = foldArabic(name);
  const hit = ARABIC_MONTH_LOOKUP.find(([candidate]) => clean.includes(foldArabic(candidate)));
  return hit ? hit[1] : null;
}

export function parseArabicDateToIso(text) {
  if (!text) return '';
  const match = toWesternDigits(text).match(/(\d{1,2})\s*([^\d\s]{2,})\s*(\d{4})/);
  if (!match) return '';
  const month = arabicMonthToNumber(match[2]);
  if (!month) return '';
  return `${match[3]}-${String(month).padStart(2, '0')}-${match[1].padStart(2, '0')}`;
}

export function isoToArabicDate(iso) {
  if (!iso) return '';
  const match = String(iso).match(/^(\d{4})-(\d{2})-(\d{2})/);
  if (!match) return '';
  const month = Number(match[2]) - 1;
  if (month < 0 || month > 11) return '';
  return `${Number(match[3])} ${ARABIC_MONTHS[month]} ${match[1]}`;
}

export function toArabicDate(date) {
  const d = new Date(date);
  return `${d.getDate()} ${ARABIC_MONTHS[d.getMonth()]} ${d.getFullYear()}`;
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
