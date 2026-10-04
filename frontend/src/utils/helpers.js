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

export const CONTROL_TYPE_LABELS = {
  cc: 'CC',
  theorique: 'Théorique',
  pratique: 'Pratique',
};

export function controlTypeLabel(type) {
  return CONTROL_TYPE_LABELS[type] || 'CC';
}

export function controlLabel(controle) {
  if (!controle) return '';
  const n = controle.numero ?? '';
  return `Contrôle ${n} (${controlTypeLabel(controle.type)})`;
}

export function controlCellKey(controle) {
  return `${controle.numero}-${controle.type || 'cc'}`;
}

/**
 * Column heading for a grade cell. The numero restarts for every type, so
 * "numero" alone is not a unique identifier: a sequence can hold cc:1,
 * theorique:1 and pratique:1 all at once. Columns are therefore labelled
 * CC1 / Pratique1 / Theorie1 and always ordered CC, Pratique, Theorie.
 */
export const CONTROL_COLUMN_ORDER = ['cc', 'pratique', 'theorique'];

export function controlColumnLabel(type, numero) {
  return `${controlTypeLabel(type)}${numero}`;
}

export function controlColumnKey(type, numero) {
  return `${numero}-${type || 'cc'}`;
}

/**
 * An absent student is stored with valeur 0 so the average stays correct, but
 * it must never be shown or printed as a real zero. Every place that renders a
 * grade has to go through this so "Absent" is used instead of 0.
 */
export function noteDisplay(note) {
  if (!note) return { absent: false, text: '—' };
  if (note.est_absent) return { absent: true, text: 'Absent' };
  return { absent: false, text: note.valeur ?? '—' };
}

export function noteDisplayStyle(compact = false) {
  return compact
    ? { color: '#b91c1c', fontWeight: 600, fontSize: 12 }
    : { color: '#b91c1c', fontWeight: 600, fontSize: 13 };
}

/**
 * Colour tone for a grade chip, as a share of the control's own scale so a
 * control marked out of /10 is judged on the same footing as one out of /20.
 */
export function gradeValueClass(valeur, noteMax = 20) {
  const max = Number(noteMax) || 20;
  const ratio = Number(valeur) / max;
  if (!Number.isFinite(ratio)) return '';
  if (ratio >= 0.8) return 'grade-excellent';
  if (ratio >= 0.7) return 'grade-good';
  if (ratio >= 0.6) return 'grade-average';
  if (ratio >= 0.5) return 'grade-pass';
  return 'grade-fail';
}

/**
 * Publication types that close a semester, mirroring
 * PublicationLockService::typesPour() on the backend. The two must agree:
 * if the UI thinks a group is open while the server thinks it is published,
 * the admin sees an editable cell that only fails after they submit.
 */
export function publicationTypesForSemestre(semestre) {
  return Number(semestre) === 1 ? ['notes_s1', 'bulletin'] : ['notes_s2', 'bulletin'];
}

/**
 * Has this group already published the given semester?
 *
 * Reading only, so this decides what the UI disables. The server re-checks
 * every write: this is a courtesy to the admin, not the guarantee.
 */
export function estSemestrePublique(publications, { groupeId, anneeId, semestre }) {
  if (!Array.isArray(publications) || !groupeId || !anneeId) return false;

  const types = publicationTypesForSemestre(semestre);

  return publications.some(p =>
    Number(p.is_published) === 1 &&
    String(p.groupe_id) === String(groupeId) &&
    String(p.annee_academique_id) === String(anneeId) &&
    types.includes(p.type)
  );
}

export const MESSAGE_SEMESTRE_PUBLIE =
  'Résultats publiés : la modification est désactivée. Dépubliez pour modifier.';

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
