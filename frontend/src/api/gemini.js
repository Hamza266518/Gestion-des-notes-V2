const GEMINI_URL = (key) =>
  `https://generativelanguage.googleapis.com/v1/models/gemini-2.5-flash:generateContent?key=${key}`;

const KEYS = {
  formateur: import.meta.env.VITE_GEMINI_KEY_FORMATEURS,  // Scanner.jsx (formateur notes)
  students:  import.meta.env.VITE_GEMINI_KEY_STUDENTS,     // ScanCin.jsx (admin CIN)
  unites:    import.meta.env.VITE_GEMINI_KEY_UNITES,       // Unites.jsx (admin unites)
  notes:     '',                                         // Notes scan (API to be provided later)
};

// Debug: verify keys are loaded
console.log('KEY_FORMATEUR:', import.meta.env.VITE_GEMINI_KEY_FORMATEURS ? '✓ Loaded' : '✗ Missing');
console.log('KEY_STUDENTS:', import.meta.env.VITE_GEMINI_KEY_STUDENTS ? '✓ Loaded' : '✗ Missing');
console.log('KEY_UNITES:', import.meta.env.VITE_GEMINI_KEY_UNITES ? '✓ Loaded' : '✗ Missing');
console.log('KEY_NOTES_TWO:', import.meta.env.VITE_GEMINI_KEY_NOTES_TWO ? '✓ Loaded' : '✗ Missing (to be provided)');

const toBase64 = (file) =>
  new Promise((resolve, reject) => {
    const reader = new FileReader();
    reader.onload = () => resolve(reader.result.split(',')[1]);
    reader.onerror = reject;
    reader.readAsDataURL(file);
  });

const callGemini = async (key, base64Image, prompt) => {
  if (!key) {
    throw new Error('Missing Gemini API key');
  }

  console.log('Calling Gemini API...');

  const controller = new AbortController();
  const timeoutId = setTimeout(() => controller.abort(), 30000);

  try {
    const response = await fetch(GEMINI_URL(key), {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        contents: [{
          parts: [
            { text: prompt },
            { inline_data: { mime_type: 'image/jpeg', data: base64Image } }
          ]
        }]
      }),
      signal: controller.signal
    });

    clearTimeout(timeoutId);

    const data = await response.json();
    console.log('Gemini response:', data);

    if (data.error) {
      throw new Error(data.error.message || 'Gemini API error');
    }

    if (!response.ok) {
      throw new Error(`HTTP ${response.status}`);
    }

    return data.candidates?.[0]?.content?.parts?.[0]?.text ?? '';
  } catch (error) {
    clearTimeout(timeoutId);
    console.error('Gemini call failed:', error);
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
  const prompt = `This is a student grade paper from a Moroccan nursing school.
Extract every student name and their numeric score.
Return ONLY a valid JSON array: [{"nom":"Fatima Zahra Idrissi","note":14}]
Rules:
- nom: full name exactly as written
- note: number between 0 and 20 only, no /20 symbol
- If score written as 14/20 extract only 14
- Skip unreadable rows
- Return ONLY the JSON array, nothing else`;

  const raw = await callGemini(KEYS.formateur, base64, prompt);
  const result = parseJson(raw);
  return Array.isArray(result) ? result.filter(r => r.nom && r.note !== undefined) : [];
};

export const scanStudentList = async (file) => {
  const base64 = await toBase64(file);
  const prompt = `This is a student list from a Moroccan nursing school.
Extract every student full name and CIN number.
Return ONLY a valid JSON array: [{"nom_prenom":"FATIMA ZAHRA IDRISSI","cin":"AB123456"}]
Rules:
- nom_prenom: full name in CAPITAL letters exactly as written
- cin: alphanumeric CIN code exactly as written
- If CIN missing include student with cin as empty string ""
- Skip rows with no readable name
- Return ONLY the JSON array, nothing else`;

  const raw = await callGemini(KEYS.students, base64, prompt);
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
