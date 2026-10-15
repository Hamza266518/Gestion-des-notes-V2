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
    .replace(/[\u0660-\u0669]/g, c => String.fromCharCode(c.charCodeAt(0) - 0x660 + 0x30))
    .replace(/[\u06F0-\u06F9]/g, c => String.fromCharCode(c.charCodeAt(0) - 0x6F0 + 0x30));
}

/**
 * Most Moroccan cities in their common French spelling. Unknown places return
 * an empty string so the admin can type the Arabic by hand (exceptions exist
 * and cannot be translated automatically).
 */
const LIEU_FR_TO_AR = {
  'casablanca': 'الدار البيضاء',
  'rabat': 'الرباط',
  'marrakech': 'مراكش',
  'fes': 'فاس',
  'tanger': 'طنجة',
  'agadir': 'أكادير',
  'meknes': 'مكناس',
  'oujda': 'وجدة',
  'kenitra': 'القنيطرة',
  'tetouan': 'تطوان',
  'safi': 'آسفي',
  'sale': 'سلا',
  'beni mellal': 'بني ملال',
  'nador': 'الناظور',
  'el jadida': 'الجديدة',
  'berkane': 'بركان',
  'mohammedia': 'المحمدية',
  'ksar el kebir': 'القصر الكبير',
  'settat': 'سطات',
  'berrechid': 'برشيد',
  'benslimane': 'بن سليمان',
  'khouribga': 'خريبكة',
  'errachidia': 'الرشيدية',
  'ouarzazate': 'ورزازات',
  'guelmim': 'كلميم',
  'taza': 'تازة',
  'larache': 'العرائش',
  'tiflet': 'تيفلت',
  'khemisset': 'الخميسات',
  'el hoceima': 'الحسيمة',
  'al hoceima': 'الحسيمة',
  'dakhla': 'الداخلة',
  'laayoune': 'العيون',
  'laayoune bira': 'العيون',
  'essaouira': 'الصويرة',
  'figuig': 'فجيج',
  'sidi kacem': 'سيدي قاسم',
  'sidi slimane': 'سيدي سليمان',
  'taourirt': 'تاوريرت',
  'jerada': 'جرادة',
  'tan-tan': 'طانطان',
  'tan tan': 'طانطان',
  'ifrane': 'إفران',
  'chefchaouen': 'شفشاون',
  'bouznika': 'بوزنيقة',
  'temara': 'تمارة',
  'skhirat': 'الصخيرات',
  'youssoufia': 'اليوسفية',
  'sidi bennour': 'سيدي بنور',
  'oued zem': 'واد زم',
  'ben guerir': 'بن غيرير',
  'kelaa des sraghna': 'قلعة السراغنة',
  'chichaoua': 'شيشاوة',
  'sidi ifni': 'سيدي إفني',
  'tarfaya': 'طرفاية',
  'boujdour': 'بوجدور',
  'saidia': 'السعيدية',
  'martil': 'مرتيل',
  'ouled teima': 'أولاد تايمة',
  'taroudant': 'تارودانت',
  'ait melloul': 'أيت ملول',
  'inezgane': 'انزكان',
  'tiznit': 'تيزنيت',
  'zagora': 'زاكورة',
  'tinghir': 'تينغير',
  'souss': 'سوس',
  'sefrou': 'صفرو',
  'melilla': 'مليلية',
};

function foldLatin(text) {
  return String(text)
    .toLowerCase()
    .normalize('NFD')
    .replace(/[\u0300-\u036f]/g, '')
    .replace(/\s+/g, ' ')
    .trim();
}

/**
 * French place of birth → Arabic. Returns '' for unknown places so the field
 * stays manual (the existing Arabic value is never overwritten with a guess).
 */
export function lieuToArabic(french) {
  if (!french) return '';
  return LIEU_FR_TO_AR[foldLatin(french)] || '';
}

function isMoroccanNationality(text) {
  if (!text) return false;
  if (/[\u0600-\u06FF]/.test(text)) return /مغرب/.test(text);
  return /maroc|moroc/.test(foldLatin(text));
}

/**
 * Corrects an obvious Moroccan spelling ("maroccain", "Marocaine ",
 * "moroccan", even the Arabic word) back to the canonical Marocaine so the
 * stored French value is always clean. Other values pass through unchanged.
 */
export function normalizeNationality(french) {
  const value = String(french || '').trim();
  if (!value) return '';
  return isMoroccanNationality(value) ? 'Marocaine' : value;
}

/**
 * French nationality → Arabic. The student body is almost entirely Moroccan,
 * so "Marocaine" (any casing, accents or stray letters) maps to مغربية;
 * anything else returns '' and must be filled in by hand.
 */
export function nationaliteToArabic(french) {
  if (!french) return '';
  return isMoroccanNationality(french) ? 'مغربية' : '';
}

export function hasArabicScript(text) {
  return /[\u0600-\u06FF]/.test(text || '');
}

/**
 * Keeps the Arabic nationality box in Arabic script. When the French side is
 * Moroccan, an empty, Latin-only, or stale-auto Arabic value becomes مغربية;
 * a real handwritten Arabic exception is preserved.
 */
export function autoNationaliteAr(prevFrench, nextFrench, arabic) {
  const ar = arabic || '';
  const auto = nationaliteToArabic(nextFrench);
  const useAuto = !ar
    || ar === nationaliteToArabic(prevFrench)
    || ar === auto
    || !hasArabicScript(ar);
  if (!useAuto) return ar;
  return auto || (nextFrench ? '' : ar);
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

/**
 * Display name of a control. Controls carry a `nom` column ("Contrôle 3" for a
 * regular control, the service name for a Stage unit), so it must win over the
 * generated fallback or the Stage grid would show "Contrôle 1..N" while the
 * grille select shows the real services.
 */
export function controlLabel(controle) {
  if (!controle) return '';
  if (controle.nom) return controle.nom;
  const n = controle.numero ?? '';
  return `Contrôle ${n}`;
}

export function controlCellKey(controle) {
  return String(controle.numero);
}

/**
 * Column heading for a grade cell. Every control is a plain "Contrôle N", so
 * the numero alone identifies it.
 */
export function controlColumnLabel(numero) {
  return `Contrôle ${numero}`;
}

export function controlColumnKey(numero) {
  return String(numero);
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
 * True when a value is a valid quarter-step grade (0, 0.25, 0.5, 0.75, ...).
 * Mirrors the backend rule in NoteParserService: a scanned 14.7 or 14.9 is a
 * misread and must be corrected by hand, never silently accepted.
 */
export function isQuarterStep(value) {
  const n = Number(String(value ?? '').replace(',', '.'));
  if (!Number.isFinite(n)) return false;
  const steps = Math.abs(n) * 4;
  return Math.abs(steps - Math.round(steps)) < 1e-4;
}

/**
 * Explanation shown under an unreadable scanned grade. Names the quarter-step
 * rule when the raw read was numeric but not a quarter step, so the instructor
 * knows which values to type.
 */
export function invalidNoteHint(raw) {
  const brut = raw === null || raw === undefined ? '' : String(raw).trim();
  const suffix = brut ? ` (« ${brut} »)` : '';

  if (brut !== '' && Number.isFinite(Number(brut.replace(',', '.'))) && !isQuarterStep(brut)) {
    return `Note non multiple de 0,25${suffix} — corriger (ex. 14,5 / 14,75)`;
  }

  return `Illisible${suffix} — saisissez la note`;
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
