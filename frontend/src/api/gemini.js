const GEMINI_URL = (key) =>
  `https://generativelanguage.googleapis.com/v1/models/gemini-2.5-flash:generateContent?key=${key}`;

const KEYS = {
  formateur: import.meta.env.VITE_GEMINI_KEY_FORMATEURS,
  students:  import.meta.env.VITE_GEMINI_KEY_STUDENTS,
  unites:    import.meta.env.VITE_GEMINI_KEY_UNITES,
  notes:     '',
};

const toBase64 = (file) =>
  new Promise((resolve, reject) => {
    const reader = new FileReader();
    reader.onload = () => resolve(reader.result.split(',')[1]);
    reader.onerror = reject;
    reader.readAsDataURL(file);
  });

const callGemini = async (key, base64Data, prompt, mimeType = 'image/jpeg') => {
  if (!key) {
    throw new Error('Missing Gemini API key');
  }

  const controller = new AbortController();
  const timeoutId = setTimeout(() => controller.abort(), mimeType === 'application/pdf' ? 60000 : 30000);

  try {
    const response = await fetch(GEMINI_URL(key), {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        contents: [{
          parts: [
            { text: prompt },
            { inline_data: { mime_type: mimeType, data: base64Data } }
          ]
        }]
      }),
      signal: controller.signal
    });

    clearTimeout(timeoutId);

    if (!response.ok) {
      throw new Error(`HTTP ${response.status}`);
    }

    const data = await response.json();

    if (data.error) {
      throw new Error(data.error.message || 'Gemini API error');
    }

    return data.candidates?.[0]?.content?.parts?.[0]?.text ?? '';
  } catch (error) {
    clearTimeout(timeoutId);
    throw error;
  }
};

const parseJson = (raw) => {
  const clean = raw.replace(/```json/g, '').replace(/```/g, '').trim();
  try {
    return JSON.parse(clean);
  } catch {
    return null;
  }
};

export const scanNotesPaper = async (file) => {
  const base64 = await toBase64(file);
  const isPdf = file.type === 'application/pdf' || file.name.toLowerCase().endsWith('.pdf');
  const prompt = `You are reading a PDF document containing student grades from a nursing school in Morocco (Institut de Formation aux Professions Paramédicales).
Extract ALL students and their grades from this PDF.
Return ONLY a valid JSON array: [{"nom":"FATIMA ZAHRA IDRISSI","nom_ar":"فاطمة الزهراء الإدريسي","note":14}]
Rules:
- nom: full name in French/Latin script, exactly as written (CAPITAL LETTERS preferred)
- nom_ar: full name in Arabic script as written in the PDF. If no Arabic name is present, use empty string ""
- note: numeric score only (0-20), extract just the number without /20
- If the grade is written as 14/20 extract only 14
- If multiple students appear, extract ALL of them
- Read carefully both French and Arabic sections of the document
- If the PDF contains a table, extract each row as a student with their grade
- If some fields are missing or unclear, use empty strings ""
- Return ONLY the JSON array, nothing else, no markdown, no code fences, no explanation`;

  const raw = await callGemini(KEYS.formateur, base64, prompt, isPdf ? 'application/pdf' : 'image/jpeg');
  const result = parseJson(raw);
  return Array.isArray(result) ? result.filter(r => r.nom && r.note !== undefined) : [];
};

export const scanStudentList = async (file) => {
  const base64 = await toBase64(file);
  const isPdf = file.type === 'application/pdf' || file.name.toLowerCase().endsWith('.pdf');
  const prompt = `You are reading a PDF document containing a list of students from a Moroccan nursing school (Institut de Formation aux Professions Paramédicales).
Extract ALL students from this document.
Return ONLY a valid JSON array: [{"nom_prenom":"FATIMA ZAHRA IDRISSI","nom_ar":"فاطمة الزهراء الإدريسي","cin":"AB123456","date_naissance":"1998-05-15","lieu_naissance":"CASABLANCA","nationalite":"MAROCAINE","cin_ar":"AB123456","date_naissance_ar":"15 مايو 1998","lieu_naissance_ar":"الدار البيضاء","nationalite_ar":"مغربية","numero_inscription_ar":"12345","date_inscription_ar":"15 سبتمبر 2023"}]
Rules:
- nom_prenom: full name in French/Latin script, CAPITAL LETTERS exactly as written
- nom_ar: full name in Arabic script as written. If no Arabic name is present, use empty string ""
- cin: alphanumeric CIN code exactly as written. If not found use ""
- date_naissance: date of birth in YYYY-MM-DD format if found. If not found use ""
- lieu_naissance: place of birth in Latin script. If not found use ""
- nationalite: nationality in Latin script. If not found use ""
- cin_ar: CIN code in Arabic script if present, otherwise same as cin
- date_naissance_ar: date of birth in Arabic script if found, otherwise empty string ""
- lieu_naissance_ar: place of birth in Arabic script if found, otherwise empty string ""
- nationalite_ar: nationality in Arabic script if found, otherwise empty string ""
- numero_inscription_ar: registration number in Arabic if found, otherwise empty string ""
- date_inscription_ar: registration date in Arabic if found, otherwise empty string ""
- If multiple students appear in the PDF, extract ALL of them
- Read carefully both French and Arabic sections of the document
- If the PDF contains a table, extract each row as a student
- If some fields are missing or unclear, leave them as empty strings ""
- Return ONLY the JSON array, nothing else, no markdown, no code fences, no explanation`;

  const raw = await callGemini(KEYS.students, base64, prompt, isPdf ? 'application/pdf' : 'image/jpeg');
  const result = parseJson(raw);
  return Array.isArray(result) ? result.filter(r => r.nom_prenom) : [];
};

export const scanUnitesDocument = async (file) => {
  const base64 = await toBase64(file);
  const prompt = `This is a curriculum document from a Moroccan nursing school listing teaching units and sequences with coefficients.
Extract all units and their sequences.
Return ONLY a valid JSON array:
[
  {
    "unite_nom": "Hygiène et confort",
    "unite_coefficient": 4,
    "unite_annee": 1,
    "unite_semestre": 1,
    "sequences": [
      {"sequence_nom": "Hygiène individuelle", "sequence_coefficient": 2, "sequence_controles": 2}
    ]
  }
]
Rules:
- unite_nom: exact unit name as written
- unite_coefficient: number 2 3 or 4, use 2 if not visible
- unite_annee: year 1 2 or 3, use 1 if not clear
- unite_semestre: 1 or 2, use 1 if not clear
- sequence_nom: exact sequence name as written
- sequence_coefficient: number, use 2 if not visible
- sequence_controles: 1 2 or 3, use 2 if not visible
- If unit has no sequences create one with same name as unit
- Return ONLY the JSON array, nothing else`;

  const raw = await callGemini(KEYS.unites, base64, prompt);
  const result = parseJson(raw);
  return Array.isArray(result) ? result.filter(r => r.unite_nom) : [];
};

export const scanFilieresDocument = async (file) => {
  const base64 = await toBase64(file);
  const prompt = `This is a document listing filieres (study programs) from a Moroccan nursing school.
Return ONLY a valid JSON array: [{"nom":"Aide-Soignant","code":"AS","section":"Qualification","nombre_annees":1}]
Rules:
- nom: exact name as written
- code: abbreviation (2-3 letters)
- section: section name
- nombre_annees: number 1 2 or 3
- If information not clear use reasonable defaults
- Return ONLY the JSON array, nothing else`;

  const raw = await callGemini(KEYS.students, base64, prompt);
  const result = parseJson(raw);
  return Array.isArray(result) ? result.filter(r => r.nom) : [];
};

export const scanGroupesDocument = async (file) => {
  const base64 = await toBase64(file);
  const prompt = `This is a document listing groupes (student groups) from a Moroccan nursing school.
Return ONLY a valid JSON array: [{"nom":"Groupe A","promotion":"2025/2026"}]
Rules:
- nom: group name exactly as written
- promotion: academic year like 2025/2026
- If promotion not found use empty string
- Return ONLY the JSON array, nothing else`;

  const raw = await callGemini(KEYS.students, base64, prompt);
  const result = parseJson(raw);
  return Array.isArray(result) ? result.filter(r => r.nom) : [];
};

export const scanFormateurUnitesDocument = async (file) => {
  const base64 = await toBase64(file);
  const prompt = `This is a curriculum document from a Moroccan nursing school listing teaching units (unités) and sequences with coefficients.
Extract all units with their details.
Return ONLY a valid JSON array:
[
  {
    "nom": "Hygiène et confort",
    "numero_annee": 1,
    "semestre": 1,
    "coefficient": 4,
    "sequences": [
      {"nom": "Hygiène individuelle", "coefficient": 2, "nombre_controles": 2}
    ]
  }
]
Rules:
- nom: exact unit name as written
- numero_annee: year 1 2 or 3
- semestre: 1 or 2
- coefficient: number for the unit
- sequences: list of sequences within the unit
- If a field is unclear use reasonable defaults
- Return ONLY the JSON array, nothing else`;

  const raw = await callGemini(KEYS.formateur, base64, prompt);
  const result = parseJson(raw);
  return Array.isArray(result) ? result.filter(r => r.nom) : [];
};

export const scanFormateursList = async (file) => {
  const base64 = await toBase64(file);
  const prompt = `This is a document or screenshot listing teachers/formateurs from a Moroccan nursing school.
Extract every formateur name and email if available.
Return ONLY a valid JSON array: [{"name":"Mohammed Alami","email":"m.alami@example.com"}]
Rules:
- name: full name exactly as written
- email: email if visible, otherwise use empty string ""
- Skip rows with no readable name
- Return ONLY the JSON array, nothing else`;

  const raw = await callGemini(KEYS.formateur, base64, prompt);
  const result = parseJson(raw);
  return Array.isArray(result) ? result.filter(r => r.name) : [];
};

export const scanFormateurSequencesDocument = async (file) => {
  const base64 = await toBase64(file);
  // The prompt will be passed dynamically from the backend
  const prompt = `This is the official pedagogical tracking document (Cahier de Suivi Pedagogique) from a Moroccan nursing school. It shows a table with formateur names and the sequences or modules they teach.
Extract ALL sequence names exactly as written in the document.
Return ONLY a JSON array: ["Sequence 1", "Sequence 2"]
Rules:
- Extract sequence/module names exactly as written
- Return ONLY the JSON array, nothing else, no markdown, no explanation`;

  const raw = await callGemini(KEYS.unites, base64, prompt);
  const result = parseJson(raw);
  return Array.isArray(result) ? result : [];
};
