import { useEffect, useState, useCallback, useRef, useMemo } from 'react';
import { diplomesApi } from '../../api/diplomes';
import { useToast } from '../../context/ToastContext';
import { useAnneeAcademique } from '../../context/AnneeAcademiqueContext';
import Spinner from '../../components/common/Spinner';
import Badge from '../../components/common/Badge';
import FilterBar from '../../components/common/FilterBar';
import FilterSelect from '../../components/common/FilterSelect';
import useBatchGenerate from '../../hooks/useBatchGenerate';
import usePersistedState from '../../hooks/usePersistedState';
import '../../css/components.css';
import '../../css/layout.css';

// Mirrors BulletinService::getMention so the palette matches the backend's own
// labels. The previous version mapped 'Très Bien' to green and 'Bien' to teal,
// inverting the two best grades relative to the server.
const MENTION_COLORS = {
  'Très Bien': 'teal',
  'Bien': 'blue',
  'Assez Bien': 'green',
  'Passable': 'yellow',
  'Insuffisant': 'red',
};

const mentionColor = (m) => MENTION_COLORS[m] ?? 'gray';

// A download failure arrives as a Blob (the request uses responseType: 'blob'),
// so the server's JSON message has to be read back out of it. Without this the
// list only ever showed the generic line, hiding the real reason (for example a
// diploma whose student was deleted).
async function downloadErrorMessage(err) {
  const fallback = 'Erreur lors de la génération du diplôme';
  try {
    const data = err?.response?.data;
    if (data instanceof Blob) {
      const parsed = JSON.parse(await data.text());
      return parsed?.message || fallback;
    }
    return data?.message || fallback;
  } catch {
    return fallback;
  }
}

// Fallback calibration: the source of truth is config/diplomes.php, exposed by
// /admin/diplomes/templates-meta. Kept here only to render before the meta
// request resolves (and as a safety net if it ever fails). Each type has its
// own coordinates, mirroring config.
const DEFAULT_FIELDS_QUALIFICATION = [
  { key: 'nom_prenom', x: 0.164, y: 0.483, size: 16, bold: true, group: 'fr' },
  { key: 'date_naissance', x: 0.109, y: 0.512, size: 13, bold: true, group: 'fr' },
  { key: 'lieu_naissance', x: 0.222, y: 0.513, size: 12, bold: true, group: 'fr' },
  { key: 'cin', x: 0.189, y: 0.540, size: 13, bold: true, group: 'fr' },
  { key: 'nationalite', x: 0.362, y: 0.541, size: 13, bold: true, group: 'fr' },
  { key: 'numero_inscription', x: 0.268, y: 0.568, size: 13, bold: true, group: 'fr' },
  { key: 'date_inscription', x: 0.487, y: 0.568, size: 13, bold: true, group: 'fr' },
  { key: 'filiere', x: 0.335, y: 0.625, size: 13, bold: true, group: 'fr' },
  { key: 'promotion', x: 0.488, y: 0.647, size: 13, bold: true, group: 'fr' },
  { key: 'date_delivrance', x: 0.465, y: 0.698, size: 13, bold: true, group: 'fr' },
  { key: 'nom_ar', x: 0.875, y: 0.487, size: 16, bold: true, group: 'ar' },
  { key: 'date_naissance_ar', x: 0.848, y: 0.519, size: 9, bold: true, group: 'ar' },
  { key: 'lieu_naissance_ar', x: 0.716, y: 0.516, size: 12, bold: true, group: 'ar' },
  { key: 'cin_ar', x: 0.802, y: 0.540, size: 13, bold: true, group: 'ar' },
  { key: 'nationalite_ar', x: 0.656, y: 0.543, size: 13, bold: true, group: 'ar' },
  { key: 'numero_inscription_ar', x: 0.774, y: 0.568, size: 13, bold: true, group: 'ar' },
  { key: 'filiere_ar', x: 0.799, y: 0.627, size: 13, bold: true, group: 'ar' },
];

const DEFAULT_FIELDS_TECHNICIEN = [
  { key: 'nom_prenom', x: 0.164, y: 0.483, size: 16, bold: true, group: 'fr' },
  { key: 'date_naissance', x: 0.109, y: 0.512, size: 13, bold: true, group: 'fr' },
  { key: 'lieu_naissance', x: 0.222, y: 0.513, size: 12, bold: true, group: 'fr' },
  { key: 'cin', x: 0.189, y: 0.540, size: 13, bold: true, group: 'fr' },
  { key: 'nationalite', x: 0.362, y: 0.541, size: 13, bold: true, group: 'fr' },
  { key: 'numero_inscription', x: 0.268, y: 0.568, size: 13, bold: true, group: 'fr' },
  { key: 'date_inscription', x: 0.487, y: 0.568, size: 13, bold: true, group: 'fr' },
  { key: 'filiere', x: 0.238, y: 0.625, size: 13, bold: true, group: 'fr' },
  { key: 'promotion', x: 0.488, y: 0.647, size: 13, bold: true, group: 'fr' },
  { key: 'date_delivrance', x: 0.465, y: 0.698, size: 13, bold: true, group: 'fr' },
  { key: 'nom_ar', x: 0.875, y: 0.487, size: 16, bold: true, group: 'ar' },
  { key: 'date_naissance_ar', x: 0.848, y: 0.519, size: 9, bold: true, group: 'ar' },
  { key: 'lieu_naissance_ar', x: 0.716, y: 0.516, size: 12, bold: true, group: 'ar' },
  { key: 'cin_ar', x: 0.802, y: 0.540, size: 13, bold: true, group: 'ar' },
  { key: 'nationalite_ar', x: 0.656, y: 0.543, size: 13, bold: true, group: 'ar' },
  { key: 'numero_inscription_ar', x: 0.774, y: 0.568, size: 13, bold: true, group: 'ar' },
  { key: 'filiere_ar', x: 0.817, y: 0.629, size: 13, bold: true, group: 'ar' },
];

const DEFAULT_FIELDS_SPECIALISATION = [
  { key: 'nom_prenom', x: 0.164, y: 0.483, size: 16, bold: true, group: 'fr' },
  { key: 'date_naissance', x: 0.109, y: 0.512, size: 13, bold: true, group: 'fr' },
  { key: 'lieu_naissance', x: 0.222, y: 0.513, size: 12, bold: true, group: 'fr' },
  { key: 'cin', x: 0.189, y: 0.540, size: 13, bold: true, group: 'fr' },
  { key: 'nationalite', x: 0.362, y: 0.541, size: 13, bold: true, group: 'fr' },
  { key: 'numero_inscription', x: 0.268, y: 0.568, size: 13, bold: true, group: 'fr' },
  { key: 'date_inscription', x: 0.487, y: 0.568, size: 13, bold: true, group: 'fr' },
  { key: 'filiere', x: 0.292, y: 0.624, size: 13, bold: true, group: 'fr' },
  { key: 'promotion', x: 0.488, y: 0.647, size: 13, bold: true, group: 'fr' },
  { key: 'date_delivrance', x: 0.465, y: 0.698, size: 13, bold: true, group: 'fr' },
  { key: 'nom_ar', x: 0.875, y: 0.487, size: 16, bold: true, group: 'ar' },
  { key: 'date_naissance_ar', x: 0.848, y: 0.519, size: 9, bold: true, group: 'ar' },
  { key: 'lieu_naissance_ar', x: 0.716, y: 0.516, size: 12, bold: true, group: 'ar' },
  { key: 'cin_ar', x: 0.802, y: 0.540, size: 13, bold: true, group: 'ar' },
  { key: 'nationalite_ar', x: 0.656, y: 0.543, size: 13, bold: true, group: 'ar' },
  { key: 'numero_inscription_ar', x: 0.774, y: 0.568, size: 13, bold: true, group: 'ar' },
  // The Arabic filière is far longer than the other two types', so it gets a
  // wider box and stays one point below them to fit on a single line.
  { key: 'filiere_ar', x: 0.790, y: 0.626, size: 13, bold: true, group: 'ar', width: 300 },
];

const DEFAULT_FIELDS_BY_TYPE = {
  Qualification: DEFAULT_FIELDS_QUALIFICATION,
  Technicien: DEFAULT_FIELDS_TECHNICIEN,
  Specialisation: DEFAULT_FIELDS_SPECIALISATION,
};

const TYPE_LABELS = {
  Qualification: 'Qualification',
  Technicien: 'Technicien',
  Specialisation: 'Spécialisation',
};

const DOCX_MIME = 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';

function fieldsToPhp(fields) {
  return fields.map(f => {
    const width = f.width != null ? `, 'width' => ${f.width}` : '';
    return `      [ 'key' => '${f.key}', 'x' => ${f.x.toFixed(3)}, 'y' => ${f.y.toFixed(3)}, 'size' => ${f.size}, 'bold' => ${f.bold ? 'true' : 'false'}, 'group' => '${f.group}'${width} ]`;
  }).join(',\n');
}

function buildPhpSnippet(fieldsByType) {
  const blocks = Object.entries(fieldsByType).map(([type, fields]) =>
    `    '${type}' => [\n${fieldsToPhp(fields)}\n    ],`
  );
  return `'fields' => [\n${blocks.join('\n')}\n],`;
}

function saveBlob(blob, filename) {
  const url = URL.createObjectURL(blob);
  const a = document.createElement('a');
  a.href = url;
  a.download = filename;
  document.body.appendChild(a);
  a.click();
  a.remove();
  URL.revokeObjectURL(url);
}

function downloadFilename(diplome) {
  const name = (diplome.etudiant?.nom_prenom || 'diplome').replace(/[^\p{L}\p{N}]+/gu, '_');
  return `Diplome_${name}.docx`;
}

function CalibrationOverlay({ templateSrc, initialFields, initialInfoFields, onFieldsChange, onInfoChange }) {
  const canvasRef = useRef(null);
  const fieldsRef = useRef((initialFields || DEFAULT_FIELDS_BY_TYPE.Technicien).map(f => ({ ...f })));
  const infoRef = useRef((initialInfoFields || []).map(f => ({ ...f })));
  const draggingRef = useRef(null);
  const imgRef = useRef(null);
  const [imgError, setImgError] = useState(null);
  const [, forceRerender] = useState(0);

  const draw = useCallback(() => {
    const canvas = canvasRef.current;
    const img = imgRef.current;
    if (!canvas || !img) return;

    canvas.width = img.naturalWidth;
    canvas.height = img.naturalHeight;
    const ctx = canvas.getContext('2d');

    ctx.drawImage(img, 0, 0);

    // The template is ~3508 px wide and is displayed scaled down, so every
    // dimension below is derived from the canvas width: absolute pixel sizes
    // would collapse to 2–3 px on screen and be unreadable.
    const cross = Math.round(canvas.width / 220);
    const dot = Math.max(3, Math.round(canvas.width / 500));
    const labelFont = Math.round(canvas.width / 90);
    const coordFont = Math.round(canvas.width / 120);
    const gap = Math.round(canvas.width / 200);

    const drawMarker = ({ x, y }, color, label) => {
      const pxX = x * canvas.width;
      const pxY = y * canvas.height;

      ctx.strokeStyle = color;
      ctx.lineWidth = Math.max(2, Math.round(canvas.width / 900));
      ctx.beginPath();
      ctx.moveTo(pxX - cross, pxY);
      ctx.lineTo(pxX + cross, pxY);
      ctx.moveTo(pxX, pxY - cross);
      ctx.lineTo(pxX, pxY + cross);
      ctx.stroke();

      ctx.beginPath();
      ctx.arc(pxX, pxY, dot, 0, Math.PI * 2);
      ctx.stroke();

      ctx.fillStyle = color;
      ctx.font = `bold ${labelFont}px Arial`;
      ctx.fillText(label, pxX + gap, pxY - gap / 2);

      const mmX = (x * 297).toFixed(1);
      const mmY = (y * 210).toFixed(1);
      ctx.fillStyle = '#000';
      ctx.font = `${coordFont}px Arial`;
      ctx.fillText(`(${mmX}, ${mmY})`, pxX + gap, pxY + gap + coordFont / 2);
    };

    fieldsRef.current.forEach(f => drawMarker(f, f.group === 'ar' ? '#2563eb' : '#dc2626', f.key));
    infoRef.current.forEach(f => drawMarker(f, '#16a34a', `${f.key} [${f.group}]`));
  }, []);

  useEffect(() => {
    const canvas = canvasRef.current;
    if (!canvas || !templateSrc) return;

    const img = new Image();
    // No crossOrigin on purpose: the template lives on the backend and its static
    // route is outside the CORS-enabled api/* paths. The canvas never has its
    // pixels read back (no toDataURL/getImageData), so a tainted canvas is fine.
    img.src = templateSrc;
    img.onload = () => {
      setImgError(null);
      imgRef.current = img;
      draw();
    };
    img.onerror = () => {
      imgRef.current = null;
      setImgError(`Impossible de charger le template : ${templateSrc}`);
    };
  }, [templateSrc, draw]);

  const getCanvasCoords = (e) => {
    const canvas = canvasRef.current;
    const rect = canvas.getBoundingClientRect();
    const scaleX = canvas.width / rect.width;
    const scaleY = canvas.height / rect.height;
    return {
      x: ((e.clientX - rect.left) * scaleX) / canvas.width,
      y: ((e.clientY - rect.top) * scaleY) / canvas.height,
    };
  };

  // Regular fields (config/diplomes.php) and the information markers share
  // the canvas; the hit test returns which list the nearest marker belongs to.
  const findNearest = (coords) => {
    const THRESHOLD = 0.03;
    let best = null;
    let bestDist = THRESHOLD;
    const scan = (list, kind) => {
      list.forEach((f, idx) => {
        const dist = Math.sqrt((f.x - coords.x) ** 2 + (f.y - coords.y) ** 2);
        if (dist < bestDist) {
          bestDist = dist;
          best = { kind, idx };
        }
      });
    };
    scan(fieldsRef.current, 'field');
    scan(infoRef.current, 'info');
    return best;
  };

  const handleMouseDown = (e) => {
    const coords = getCanvasCoords(e);
    const hit = findNearest(coords);
    if (hit) {
      e.preventDefault();
      draggingRef.current = hit;
    }
  };

  const handleMouseMove = (e) => {
    if (!draggingRef.current) return;
    e.preventDefault();
    const coords = getCanvasCoords(e);
    const { kind, idx } = draggingRef.current;
    const list = kind === 'info' ? infoRef.current : fieldsRef.current;
    list[idx].x = Math.max(0, Math.min(1, coords.x));
    list[idx].y = Math.max(0, Math.min(1, coords.y));
    draw();
    forceRerender(n => n + 1);
    if (kind === 'info') {
      if (onInfoChange) onInfoChange(infoRef.current);
    } else if (onFieldsChange) {
      onFieldsChange(fieldsRef.current);
    }
  };

  const handleMouseUp = () => {
    draggingRef.current = null;
  };

  if (imgError) {
    return (
      <div style={{ padding: 24, borderRadius: 6, border: '2px solid #dc2626', background: '#fef2f2', color: '#dc2626', fontSize: 14 }}>
        {imgError}
      </div>
    );
  }

  return (
    <div>
      <canvas
        ref={canvasRef}
        onMouseDown={handleMouseDown}
        onMouseMove={handleMouseMove}
        onMouseUp={handleMouseUp}
        onMouseLeave={handleMouseUp}
        style={{ maxWidth: '100%', maxHeight: '68vh', width: 'auto', height: 'auto', display: 'block', borderRadius: 6, border: '2px solid #666', cursor: 'crosshair' }}
      />
      <div style={{ marginTop: 6, fontSize: 12, color: '#666', display: 'flex', gap: 20, flexWrap: 'wrap' }}>
        <span><span style={{ color: '#dc2626', fontWeight: 600 }}>●</span> Champs français (rouge)</span>
        <span><span style={{ color: '#2563eb', fontWeight: 600 }}>●</span> Champs arabes (bleu)</span>
        <span><span style={{ color: '#16a34a', fontWeight: 600 }}>●</span> Informations du diplôme (vert)</span>
      </div>
      <div style={{ fontSize: 12, color: '#888', marginTop: 2 }}>Cliquez sur un repère et glissez-le vers la bonne position</div>
    </div>
  );
}

// Flatten { type: { key: { fr, ar } } } into per-type marker lists for the
// overlay, and rebuild the nested shape before saving.
function flattenInfoPositions(positionsByType) {
  const out = {};
  Object.entries(positionsByType || {}).forEach(([type, keys]) => {
    const list = [];
    Object.entries(keys || {}).forEach(([key, groups]) => {
      ['fr', 'ar'].forEach(group => {
        const g = groups?.[group];
        if (!g) return;
        list.push({ id: `${key}:${group}`, key, group, x: g.x, y: g.y });
      });
    });
    out[type] = list;
  });
  return out;
}

function nestInfoPositions(infoByType) {
  const out = {};
  Object.entries(infoByType || {}).forEach(([type, list]) => {
    const keys = {};
    (list || []).forEach(({ key, group, x, y }) => {
      keys[key] = keys[key] || {};
      keys[key][group] = { x: Number(x.toFixed(4)), y: Number(y.toFixed(4)) };
    });
    out[type] = keys;
  });
  return out;
}

// The information markers live in the database (not in config/diplomes.php),
// so the copyable dump mirrors the exact payload of saveInformationsPositions.
function buildInfoSnippet(infoByType) {
  return JSON.stringify(nestInfoPositions(infoByType), null, 2);
}

function InformationsModal({ data, onClose, onSaved }) {
  const toast = useToast();
  const infos = data?.infos || [];
  const [valeurs, setValeurs] = useState(() => ({ ...(data?.valeurs || {}) }));
  const [saving, setSaving] = useState(false);

  const save = async () => {
    setSaving(true);
    try {
      await diplomesApi.saveInformations({ valeurs });
      toast.success('Informations du diplôme enregistrées');
      onSaved?.();
      onClose();
    } catch {
      toast.error("Erreur lors de l'enregistrement");
    } finally {
      setSaving(false);
    }
  };

  return (
    <div style={{ position: 'fixed', inset: 0, background: 'rgba(0,0,0,0.75)', zIndex: 1000, display: 'flex', alignItems: 'center', justifyContent: 'center', padding: 24 }}>
      <div style={{ background: '#fff', borderRadius: 10, padding: 24, width: '100%', maxWidth: 560, maxHeight: '90vh', overflowY: 'auto', boxShadow: '0 20px 60px rgba(0,0,0,0.3)' }}>
        <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 8 }}>
          <h3 style={{ margin: 0, fontSize: 18 }}>Informations du diplôme</h3>
          <button className="btn btn-outline" onClick={onClose}>Fermer</button>
        </div>
        <p style={{ margin: '0 0 16px', color: '#6b7280', fontSize: 14, lineHeight: 1.5 }}>
          Ces valeurs sont identiques pour tous les diplômes (génération normale, manuelle et « tout générer »).
          Elles remplissent le texte du diplôme, en français et en arabe. Positionnez chaque repère via
          «&nbsp;Calibrer les templates&nbsp;».
        </p>

        {infos.map(info => (
          <label key={info.key} style={{ display: 'block', marginBottom: 12 }}>
            <span style={{ display: 'block', fontSize: 13, color: '#374151', marginBottom: 4 }}>{info.label}</span>
            <input
              type={info.type === 'date' ? 'date' : 'text'}
              className="form-input"
              style={{ width: '100%', padding: 8, borderRadius: 6, border: '1px solid #d1d5db' }}
              value={valeurs[info.key] ?? ''}
              onChange={(e) => setValeurs(prev => ({ ...prev, [info.key]: e.target.value }))}
              placeholder={info.type === 'date' ? undefined : '—'}
            />
          </label>
        ))}

        <div style={{ display: 'flex', gap: 10, justifyContent: 'flex-end', marginTop: 16 }}>
          <button className="btn btn-outline" onClick={onClose}>Annuler</button>
          <button className="btn btn-primary" onClick={save} disabled={saving}>
            {saving ? 'Enregistrement...' : 'Enregistrer'}
          </button>
        </div>
      </div>
    </div>
  );
}

function CalibrationModal({ meta, onClose }) {
  const toast = useToast();
  const types = useMemo(() => meta?.templates?.map(t => t.type) || [], [meta]);
  const backendOrigin = new URL(import.meta.env.VITE_API_URL || 'http://localhost:8000/api').origin;
  const [selectedType, setSelectedType] = useState(types[0] || 'Technicien');
  // Each template type carries its own calibration: switching tabs swaps the
  // background artwork together with that type's marker positions.
  const [fieldsByType, setFieldsByType] = useState(() =>
    Object.fromEntries(types.map(t => {
      const entry = meta?.templates?.find(x => x.type === t);
      const base = entry?.fields || DEFAULT_FIELDS_BY_TYPE[t] || [];
      return [t, base.map(f => ({ ...f }))];
    }))
  );
  // The establishment-level markers, calibratable per type and saved
  // straight to the database (unlike the config fields above, which are copied
  // into config/diplomes.php).
  const [infoByType, setInfoByType] = useState(() => flattenInfoPositions(meta?.info_positions || {}));
  const [savingPositions, setSavingPositions] = useState(false);

  const templateUrl = meta?.templates?.find(t => t.type === selectedType)?.template_url || '';

  const copyCode = () => {
    const snippet = buildPhpSnippet(fieldsByType);
    navigator.clipboard.writeText(snippet).then(() => toast.success('Coordonnées copiées !'));
  };

  const copyInfo = () => {
    navigator.clipboard
      .writeText(buildInfoSnippet(infoByType))
      .then(() => toast.success('Coordonnées des informations copiées !'));
  };

  const savePositions = async () => {
    setSavingPositions(true);
    try {
      await diplomesApi.saveInformationsPositions({ positions: nestInfoPositions(infoByType) });
      toast.success('Positions des informations enregistrées');
    } catch {
      toast.error("Erreur lors de l'enregistrement des positions");
    } finally {
      setSavingPositions(false);
    }
  };

  const resetInfos = () => {
    const defaults = flattenInfoPositions(meta?.info_positions || {});
    setInfoByType(prev => ({
      ...prev,
      [selectedType]: (defaults[selectedType] || []).map(f => ({ ...f })),
    }));
  };

  return (
    <div style={{ position: 'fixed', inset: 0, background: 'rgba(0,0,0,0.75)', zIndex: 1000, display: 'flex', alignItems: 'center', justifyContent: 'center', padding: 24 }}>
      <div style={{ background: '#fff', borderRadius: 10, padding: 20, width: '100%', maxWidth: 1100, maxHeight: '92vh', display: 'flex', flexDirection: 'column', gap: 12, overflowY: 'auto' }}>
        <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
          <h3 style={{ margin: 0, fontSize: 18 }}>Calibrage des templates</h3>
          <button className="btn btn-outline" onClick={onClose}>Fermer</button>
        </div>

        <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap', alignItems: 'center' }}>
          <span style={{ fontSize: 13, color: '#6b7280' }}>Template :</span>
          {types.map(t => (
            <button
              key={t}
              className="btn btn-sm"
              style={t === selectedType ? { background: '#3b82f6', color: '#fff' } : {}}
              onClick={() => setSelectedType(t)}
            >
              {TYPE_LABELS[t] || t}
            </button>
          ))}
          <span style={{ marginLeft: 'auto', fontSize: 12, color: '#6b7280' }}>
            {meta?.page_width_mm ?? '—'} × {meta?.page_height_mm ?? '—'} mm (A4 paysage)
          </span>
        </div>

        <CalibrationOverlay
          key={selectedType}
          templateSrc={backendOrigin + templateUrl}
          initialFields={fieldsByType[selectedType]}
          initialInfoFields={infoByType[selectedType]}
          onFieldsChange={(next) =>
            setFieldsByType(prev => ({ ...prev, [selectedType]: next.map(f => ({ ...f })) }))
          }
          onInfoChange={(next) =>
            setInfoByType(prev => ({ ...prev, [selectedType]: next.map(f => ({ ...f })) }))
          }
        />

        <div style={{ background: '#f0f9ff', border: '1px solid #3b82f6', borderRadius: 6, padding: 12 }}>
          <div style={{ fontWeight: 600, marginBottom: 8, fontSize: 14 }}>
            Coordonnées actuelles
            <span style={{ fontWeight: 400, fontSize: 12, color: '#6b7280', marginLeft: 8 }}>
              Calibrage propre à chaque type de template
            </span>
          </div>
          <div style={{ background: '#1e293b', color: '#22c55e', fontFamily: 'monospace', fontSize: 11, padding: 12, borderRadius: 4, whiteSpace: 'pre', maxHeight: 220, overflow: 'auto', lineHeight: 1.6 }}>
            {buildPhpSnippet(fieldsByType)}
          </div>
          <button
            className="btn btn-sm"
            style={{ marginTop: 8, background: '#3b82f6', color: '#fff' }}
            onClick={copyCode}
          >
            Copier les coordonnées
          </button>
          <button
            className="btn btn-sm"
            style={{ marginTop: 8, marginLeft: 8, background: '#ef4444', color: '#fff' }}
            onClick={() => setFieldsByType(prev => ({
              ...prev,
              [selectedType]: (DEFAULT_FIELDS_BY_TYPE[selectedType] || []).map(f => ({ ...f })),
            }))}
          >
            Réinitialiser ({TYPE_LABELS[selectedType] || selectedType})
          </button>
          <div style={{ marginTop: 8, fontSize: 12, color: '#6b7280', lineHeight: 1.5 }}>
            Collez le code copié dans <code>backend/config/diplomes.php</code> (section <code>'fields'</code>).
          </div>
        </div>

        <div style={{ background: '#f0fdf4', border: '1px solid #16a34a', borderRadius: 6, padding: 12 }}>
          <div style={{ fontWeight: 600, marginBottom: 8, fontSize: 14 }}>
            Informations du diplôme
            <span style={{ fontWeight: 400, fontSize: 12, color: '#6b7280', marginLeft: 8 }}>
              Repères verts — les valeurs se saisissent via « Informations du diplôme »
            </span>
          </div>
          <button
            className="btn btn-sm"
            style={{ background: '#16a34a', color: '#fff' }}
            onClick={savePositions}
            disabled={savingPositions}
          >
            {savingPositions ? 'Enregistrement...' : 'Enregistrer les positions (tous les templates)'}
          </button>
          <button
            className="btn btn-sm btn-outline"
            style={{ marginLeft: 8 }}
            onClick={resetInfos}
          >
            Réinitialiser ({TYPE_LABELS[selectedType] || selectedType})
          </button>
          <div style={{ marginTop: 10, fontWeight: 600, fontSize: 14 }}>Coordonnées actuelles des informations</div>
          <div style={{ background: '#1e293b', color: '#22c55e', fontFamily: 'monospace', fontSize: 11, padding: 12, borderRadius: 4, whiteSpace: 'pre', maxHeight: 220, overflow: 'auto', lineHeight: 1.6, marginTop: 6 }}>
            {buildInfoSnippet(infoByType)}
          </div>
          <button
            className="btn btn-sm"
            style={{ marginTop: 8, background: '#16a34a', color: '#fff' }}
            onClick={copyInfo}
          >
            Copier les coordonnées des informations
          </button>
          <div style={{ marginTop: 8, fontSize: 12, color: '#6b7280', lineHeight: 1.5 }}>
            Positionnez les repères verts (français à gauche, arabe à droite) puis enregistrez. Ils sont ensuite
            imprimés sur tous les diplômes, manuels comme générés.
          </div>
        </div>
      </div>
    </div>
  );
}

function ManualGenerateModal({ anneeId, onClose, onGenerated, onStartBatch }) {
  const toast = useToast();
  const [candidates, setCandidates] = useState([]);
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
  const [selectedId, setSelectedId] = useState('');

  useEffect(() => {
    if (!anneeId) return;
    setLoading(true);
    diplomesApi.manualCandidates({ annee_academique_id: anneeId })
      .then(res => {
        setCandidates(res.data.data || []);
      })
      .catch(() => toast.error('Erreur de chargement des étudiants'))
      .finally(() => setLoading(false));
    // toast.error is stable but the toast object identity changes with every
    // toast, so it must stay out of the dependency array.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [anneeId]);

  const handleConfirm = async () => {
    if (!selectedId) return;
    setSaving(true);
    try {
      await diplomesApi.manualGenerate({
        etudiant_id: selectedId,
        annee_academique_id: anneeId,
      });
      toast.success('Diplôme généré manuellement');
      onGenerated();
      onClose();
    } catch (err) {
      toast.error(err.response?.data?.message || 'Erreur lors de la génération manuelle');
    } finally {
      setSaving(false);
    }
  };

  return (
    <div style={{ position: 'fixed', inset: 0, background: 'rgba(0,0,0,0.75)', zIndex: 1000, display: 'flex', alignItems: 'center', justifyContent: 'center', padding: 24 }}>
      <div style={{ background: '#fff', borderRadius: 10, padding: 24, width: '100%', maxWidth: 560, boxShadow: '0 20px 60px rgba(0,0,0,0.3)' }}>
        <h3 style={{ margin: '0 0 8px', fontSize: 18 }}>Génération manuelle</h3>
        <p style={{ margin: '0 0 16px', color: '#6b7280', fontSize: 14, lineHeight: 1.5 }}>
          Délivre un diplôme <strong>sans attendre</strong> la publication du bulletin ni la saisie complète des notes.
          <br /><br />
          <span style={{ color: '#dc2626', fontSize: 13 }}>
            ⚠ Tous les étudiants en <strong>année diplômante</strong> (dernière année de leur filière) sont listés, même
            si un diplôme existe déjà (il sera régénéré) — les années précédentes ne peuvent pas obtenir de diplôme.
          </span>
        </p>

        {loading ? (
          <div className="text-center"><Spinner /></div>
        ) : candidates.length === 0 ? (
          <div className="table-empty" style={{ marginBottom: 16 }}>
            Aucun étudiant en année diplômante pour cette année.
          </div>
        ) : (
          <>
            <label style={{ display: 'block', fontSize: 13, color: '#6b7280', marginBottom: 6 }}>
              Étudiant ({candidates.length} — année diplômante)
            </label>
            <select
              className="form-select"
              style={{ width: '100%', padding: 8, borderRadius: 6, border: '1px solid #d1d5db', marginBottom: 8 }}
              value={selectedId}
              onChange={(e) => setSelectedId(e.target.value)}
            >
              <option value="">Sélectionner un étudiant…</option>
              {candidates.map(c => (
                <option key={c.id} value={c.id}>
                  {c.nom_prenom} — {c.cin || 'sans CIN'} ({c.groupe} · {c.filiere}
                  {c.annee_diplomante ? ' · ⭐ dernière année' : (c.niveau_numero && c.nombre_annees ? ` · année ${c.niveau_numero}/${c.nombre_annees}` : '')})
                </option>
              ))}
            </select>
          </>
        )}

        <div style={{ marginBottom: 16, fontSize: 12, color: '#6b7280', lineHeight: 1.5 }}>
          « Tout générer » crée uniquement les fichiers .docx (un dossier par filière, le tout dans un .rar) —
          aucun diplôme n'est enregistré en base. La progression s'affiche en haut de l'écran&nbsp;: fermez cette
          fenêtre et continuez à travailler pendant que ça tourne.
        </div>

        <div style={{ display: 'flex', gap: 10, justifyContent: 'flex-end', flexWrap: 'wrap' }}>
          <button className="btn btn-outline" onClick={onClose}>
            Annuler
          </button>
          <button
            className="btn btn-outline"
            onClick={() => onStartBatch(candidates)}
            disabled={saving || loading || !candidates.length}
          >
            Tout générer et télécharger le .rar
          </button>
          <button
            className="btn btn-primary"
            onClick={handleConfirm}
            disabled={!selectedId || saving || loading}
          >
            {saving ? 'Génération...' : 'Générer manuellement'}
          </button>
        </div>
      </div>
    </div>
  );
}

export default function Diplomes() {
  const [diplomes,   setDiplomes]   = useState([]);
  const [loading,    setLoading]    = useState(true);
  const [downloading, setDownloading] = useState(null);
  const [genAllLoading, setGenAllLoading] = useState(false);
  const [manualOpen, setManualOpen] = useState(false);
  const [calibrating, setCalibrating] = useState(false);
  const [templatesMeta, setTemplatesMeta] = useState(null);
  const [infosOpen, setInfosOpen] = useState(false);
  const [infosData, setInfosData] = useState(null);
  const [showConfirmModal, setShowConfirmModal] = useState(false);
  const toast = useToast();
  const { currentAnnee, annees, loading: anneeLoading } = useAnneeAcademique();

  // Diplomas must be generated while the cohort's year is still selectable: an
  // archived year is the only place its graduates live, and promoteAdmis leaves
  // them in it. Pinning this to currentAnnee (as this did) meant that once
  // setCurrent had run, the graduating class could never be awarded.
  const [saved, setSaved] = usePersistedState('diplomes', { selectedYearId: '' });
  const [selectedYearId, setSelectedYearId] = useState(() => (saved.selectedYearId ? Number(saved.selectedYearId) : null));
  useEffect(() => {
    setSaved({ selectedYearId: selectedYearId ?? '' });
  }, [selectedYearId, setSaved]);

  useEffect(() => {
    if (selectedYearId === null && currentAnnee) setSelectedYearId(currentAnnee.id);
  }, [currentAnnee, selectedYearId]);

  const selectedYear = useMemo(
    () => annees.find(a => a.id === selectedYearId) ?? currentAnnee ?? null,
    [annees, selectedYearId, currentAnnee]
  );

  const load = useCallback(() => {
    if (anneeLoading) return;
    setLoading(true);
    // Guarded: without a year the request omitted the filter and the table listed
    // every year's diplomas interleaved whenever no year was current.
    if (!selectedYearId) {
      setDiplomes([]);
      setLoading(false);
      return;
    }
    diplomesApi.getDiplomes({ annee_academique_id: selectedYearId })
      .then(res => setDiplomes(res.data.data || []))
      .catch(() => toast.error('Erreur chargement des diplômes'))
      .finally(() => setLoading(false));
    // toast.error is stable but the toast object identity changes with every
    // toast, so it must stay out of the dependency array.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [selectedYearId, anneeLoading]);

  const batch = useBatchGenerate({
    anneeId: selectedYear?.id,
    onDone: load,
  });

  const startBatch = (candidates) => {
    batch.start(candidates);
    setManualOpen(false);
  };

  useEffect(() => { load(); }, [load]);

  const handleDownload = async (diplome) => {
    setDownloading(diplome.id);
    try {
      const res = await diplomesApi.downloadDiplome(diplome.id);
      const blob = new Blob([res.data], { type: DOCX_MIME });
      saveBlob(blob, downloadFilename(diplome));
      if (!diplome.is_printed) {
        diplomesApi.markPrinted(diplome.id)
          .then(load)
          .catch(() => {});
      }
    } catch (err) {
      toast.error(await downloadErrorMessage(err));
    } finally {
      setDownloading(null);
    }
  };

  const handleMarkPrinted = async (id) => {
    try {
      await diplomesApi.markPrinted(id);
      toast.success('Marqué comme imprimé');
      load();
    } catch {
      toast.error('Erreur');
    }
  };

  const handleGenerateAll = async () => {
    if (!selectedYear) return;
    try {
      const res = await diplomesApi.checkReadiness({ annee_academique_id: selectedYear.id });
      const data = res.data.data;
      if (data.orphans > 0) {
        toast.warning(
          `${data.orphans} étudiant(s) sans groupe exploitable sur ${selectedYear.label}. Corrigez-les avant de générer.`
        );
        return;
      }
      if (data.blocked) {
        toast.warning(data.message || 'Génération impossible');
        return;
      }
      if (data.ready === 0) {
        toast.warning('Aucun étudiant admissible pour un diplôme.');
        return;
      }
    } catch {
      toast.error('Erreur de vérification');
      return;
    }
    setShowConfirmModal(true);
  };

  const confirmGenerateAll = async () => {
    setShowConfirmModal(false);
    setGenAllLoading(true);
    try {
      const res = await diplomesApi.generateAllDiplomes({
        annee_academique_id: selectedYear.id
      });
      const { created, skipped, total, errors } = res.data.data;
      toast.success(`${created} diplôme(s) généré(s), ${skipped} non-diplômables`);
      // Per-student failures used to be counted as plain "skipped", hiding real
      // faults behind a tidy summary.
      if (errors?.length) {
        toast.error(`${errors.length} erreur(s) détaillée(s) : ${errors[0]}`);
      }
      load();
    } catch (err) {
      const msg = err.response?.data?.message || 'Erreur lors de la génération des diplômes';
      toast.error(msg);
    } finally {
      setGenAllLoading(false);
    }
  };

  const openCalibration = async () => {
    try {
      const res = await diplomesApi.getTemplatesMeta();
      setTemplatesMeta(res.data.data);
      setCalibrating(true);
    } catch {
      toast.error('Erreur de chargement des templates');
    }
  };

  const openInformations = async () => {
    try {
      const res = await diplomesApi.getInformations();
      setInfosData(res.data.data);
      setInfosOpen(true);
    } catch {
      toast.error('Erreur de chargement des informations');
    }
  };

  if (loading) return <div className="text-center mt-5"><Spinner /></div>;

  return (
    <div className="page">
      <FilterBar
        showSearch={false}
        value={{ annee_id: selectedYearId ?? '' }}
        onChange={() => {}}
        actions={(
          <>
            <button className="btn btn-outline" onClick={() => setManualOpen(true)} disabled={!selectedYear}>
              Générer manuellement
            </button>
            <button className="btn btn-outline" onClick={openInformations}>
              Informations du diplôme
            </button>
            <button className="btn btn-outline" onClick={openCalibration}>
              Calibrer les templates
            </button>
            <button className="btn btn-primary" onClick={handleGenerateAll} disabled={genAllLoading || !selectedYear}>
              {genAllLoading ? 'Génération...' : 'Générer tous les diplômes'}
            </button>
          </>
        )}
      >
        <FilterSelect
          label="Année"
          value={selectedYearId ?? ''}
          options={annees.map(a => ({
            id: a.id,
            label: `${a.label}${a.is_current ? ' (en cours)' : a.is_archived ? ' (archivée)' : ''}`,
          }))}
          onChange={(v) => setSelectedYearId(v ? Number(v) : null)}
          placeholder="Sélectionner une année"
          allLabel="Sélectionner une année"
        />
      </FilterBar>

      {selectedYear && !selectedYear.is_current && (
        <div className="alert alert-warning" style={{ marginBottom: 16 }}>
          Année archivée : les diplômés de {selectedYear.label} restent à generating avant toute nouvelle année. Le diplôme doit
          être généré pendant cette année.
        </div>
      )}

      {showConfirmModal && (
        <div style={{ position: 'fixed', inset: 0, background: 'rgba(0,0,0,0.75)', zIndex: 1000, display: 'flex', alignItems: 'center', justifyContent: 'center', padding: 24 }}>
          <div style={{ background: '#fff', borderRadius: 10, padding: 24, width: '100%', maxWidth: 480, boxShadow: '0 20px 60px rgba(0,0,0,0.3)' }}>
            <h3 style={{ margin: '0 0 8px', fontSize: 18 }}>Générer tous les diplômes</h3>
            <p style={{ margin: '0 0 16px', color: '#6b7280', fontSize: 14, lineHeight: 1.5 }}>
              Générer les diplômes pour tous les étudiants admis de la promotion <strong>{selectedYear?.label}</strong>&nbsp;?
              <br /><br />
              <span style={{ color: '#dc2626', fontSize: 13 }}>
                ⚠ Seuls les étudiants en année diplômante avec bulletin publié, toutes notes saisies, et moyenne ≥ 10 recevront un diplôme.
              </span>
            </p>
            <div style={{ display: 'flex', gap: 10, justifyContent: 'flex-end' }}>
              <button className="btn btn-outline" onClick={() => setShowConfirmModal(false)}>
                Annuler
              </button>
              <button className="btn btn-primary" onClick={confirmGenerateAll}>
                Confirmer
              </button>
            </div>
          </div>
        </div>
      )}

      {calibrating && templatesMeta && (
        <CalibrationModal meta={templatesMeta} onClose={() => setCalibrating(false)} />
      )}

      {infosOpen && infosData && (
        <InformationsModal
          data={infosData}
          onClose={() => setInfosOpen(false)}
          onSaved={() => {}}
        />
      )}

      {manualOpen && (
        <ManualGenerateModal
          anneeId={selectedYear?.id}
          onClose={() => setManualOpen(false)}
          onGenerated={load}
          onStartBatch={startBatch}
        />
      )}

      <div className="table-wrap">
        <table>
          <thead>
            <tr>
              <th>#</th>
              <th>Stagiaire</th>
              <th>CIN</th>
              <th>Filière</th>
              <th>Moyenne</th>
              <th>Mention</th>
              <th>Statut</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody>
            {diplomes.length === 0 ? (
              <tr><td colSpan={8} className="table-empty">Aucun diplôme généré pour cette année</td></tr>
            ) : diplomes.map((d, i) => (
              <tr key={d.id}>
                <td>{i + 1}</td>
                <td><strong>{d.etudiant?.nom_prenom}</strong></td>
                <td style={{ fontSize: 13, color: '#6b7280' }}>{d.etudiant?.cin ?? '—'}</td>
                <td>{d.etudiant?.groupe?.niveau?.filiere?.nom ?? '—'}</td>
                <td><strong>{d.moyenne_generale}/20</strong></td>
                <td><Badge label={d.mention} color={mentionColor(d.mention)} /></td>
                <td>
                  <Badge
                    label={d.is_printed ? 'Imprimé' : 'Non imprimé'}
                    color={d.is_printed ? 'green' : 'gray'}
                  />
                </td>
                <td style={{ display: 'flex', gap: 8, flexWrap: 'wrap' }}>
                  <button
                    className="btn btn-sm btn-primary"
                    onClick={() => handleDownload(d)}
                    disabled={downloading === d.id}
                  >
                    {downloading === d.id ? 'Génération...' : 'Télécharger .docx'}
                  </button>
                  {!d.is_printed && (
                    <button
                      className="btn btn-sm btn-outline"
                      onClick={() => handleMarkPrinted(d.id)}
                    >
                      Marquer imprimé
                    </button>
                  )}
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </div>
  );
}