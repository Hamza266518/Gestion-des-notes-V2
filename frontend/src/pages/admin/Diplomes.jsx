import { useEffect, useState, useCallback, useRef } from 'react';
import { diplomesApi } from '../../api/diplomes';
import { useToast } from '../../context/ToastContext';
import { useAnneeAcademique } from '../../context/AnneeAcademiqueContext';
import Spinner from '../../components/common/Spinner';
import Badge from '../../components/common/Badge';
import '../../css/components.css';
import '../../css/layout.css';

const mentionColor = (m) => {
  if (m === 'Très Bien') return 'green';
  if (m === 'Bien')      return 'teal';
  if (m === 'Assez Bien')return 'yellow';
  if (m === 'Passable')  return 'orange';
  return 'red';
};

const FIELDS = [
  { key: 'nom_prenom', x: 0.135, y: 0.489, size: 11, bold: true, group: 'fr' },
  { key: 'date_naissance', x: 0.106, y: 0.515, size: 11, bold: false, group: 'fr' },
  { key: 'lieu_naissance', x: 0.359, y: 0.508, size: 11, bold: false, group: 'fr' },
  { key: 'cin', x: 0.178, y: 0.541, size: 11, bold: false, group: 'fr' },
  { key: 'nationalite', x: 0.352, y: 0.541, size: 11, bold: false, group: 'fr' },
  { key: 'numero_inscription', x: 0.246, y: 0.561, size: 11, bold: false, group: 'fr' },
  { key: 'date_inscription', x: 0.436, y: 0.563, size: 11, bold: false, group: 'fr' },
  { key: 'type_formation', x: 0.415, y: 0.585, size: 11, bold: true, group: 'fr' },
  { key: 'filiere', x: 0.079, y: 0.611, size: 11, bold: true, group: 'fr' },
  { key: 'promotion', x: 0.520, y: 0.632, size: 11, bold: true, group: 'fr' },
  { key: 'date_delivrance', x: 0.472, y: 0.690, size: 11, bold: false, group: 'fr' },
  { key: 'nom_ar', x: 0.872, y: 0.490, size: 11, bold: true, group: 'ar' },
  { key: 'date_naissance_ar', x: 0.803, y: 0.515, size: 11, bold: false, group: 'ar' },
  { key: 'lieu_naissance_ar', x: 0.568, y: 0.516, size: 11, bold: false, group: 'ar' },
  { key: 'cin_ar', x: 0.789, y: 0.539, size: 11, bold: false, group: 'ar' },
  { key: 'nationalite_ar', x: 0.569, y: 0.541, size: 11, bold: false, group: 'ar' },
  { key: 'numero_inscription_ar', x: 0.750, y: 0.561, size: 11, bold: false, group: 'ar' },
  { key: 'date_inscription_ar', x: 0.569, y: 0.566, size: 11, bold: false, group: 'ar' },
  { key: 'type_formation_ar', x: 0.627, y: 0.586, size: 11, bold: true, group: 'ar' },
  { key: 'filiere_ar', x: 0.875, y: 0.610, size: 11, bold: true, group: 'ar' }
];

let arabicFontLoaded = false;
let arabicFontB64 = null;

async function ensureArabicFont() {
  if (arabicFontLoaded) return;

  if (!document.getElementById('amiri-font-link')) {
    const link = document.createElement('link');
    link.id = 'amiri-font-link';
    link.rel = 'stylesheet';
    link.href = 'https://fonts.googleapis.com/css2?family=Amiri:ital,wght@0,400;0,700;1,400;1,700&display=swap';
    document.head.appendChild(link);
  }

  await document.fonts.ready;
  await document.fonts.load('bold 16px Amiri');
  await document.fonts.load('normal 16px Amiri');

  try {
    const fontUrl = 'https://cdn.jsdelivr.net/gh/google/fonts@main/ofl/amiri/Amiri-Regular.ttf';
    const resp = await fetch(fontUrl);
    if (resp.ok) {
      const buffer = await resp.arrayBuffer();
      const bytes = new Uint8Array(buffer);
      let binary = '';
      for (let i = 0; i < bytes.length; i++) {
        binary += String.fromCharCode(bytes[i]);
      }
      arabicFontB64 = btoa(binary);
    }
  } catch {}

  arabicFontLoaded = true;
}

function renderArabicToImage(text, fontSizePx, bold) {
  const font = `${bold ? 'bold ' : ''}${fontSizePx}px 'Amiri', 'Arial', sans-serif`;

  const testCanvas = document.createElement('canvas');
  const testCtx = testCanvas.getContext('2d');
  testCtx.font = font;
  testCtx.direction = 'rtl';
  const metrics = testCtx.measureText(text);
  const textW = metrics.width;
  const ascent = metrics.actualBoundingBoxAscent || fontSizePx * 0.85;
  const descent = metrics.actualBoundingBoxDescent || fontSizePx * 0.15;

  const w = Math.max(Math.ceil(textW), 2);
  const h = Math.ceil(ascent + descent);

  const canvas = document.createElement('canvas');
  canvas.width = w;
  canvas.height = h;
  const ctx = canvas.getContext('2d');

  ctx.font = font;
  ctx.direction = 'rtl';
  ctx.textBaseline = 'top';
  ctx.textAlign = 'right';
  ctx.fillStyle = '#000000';
  ctx.fillText(text, w, 0);

  return { dataUrl: canvas.toDataURL('image/png'), width: w, height: h, ascent };
}

function CalibrationOverlay({ templateSrc, onFieldsChange }) {
  const canvasRef = useRef(null);
  const fieldsRef = useRef(FIELDS.map(f => ({ ...f })));
  const draggingRef = useRef(null);
  const imgRef = useRef(null);
  const [, forceRerender] = useState(0);

  const draw = useCallback(() => {
    const canvas = canvasRef.current;
    const img = imgRef.current;
    if (!canvas || !img) return;

    canvas.width = img.naturalWidth;
    canvas.height = img.naturalHeight;
    const ctx = canvas.getContext('2d');

    ctx.drawImage(img, 0, 0);

    fieldsRef.current.forEach(({ key, x, y, group }) => {
      const pxX = x * canvas.width;
      const pxY = y * canvas.height;
      const color = group === 'ar' ? '#2563eb' : '#dc2626';

      ctx.strokeStyle = color;
      ctx.lineWidth = 2;
      ctx.beginPath();
      ctx.moveTo(pxX - 12, pxY);
      ctx.lineTo(pxX + 12, pxY);
      ctx.moveTo(pxX, pxY - 12);
      ctx.lineTo(pxX, pxY + 12);
      ctx.stroke();

      ctx.beginPath();
      ctx.arc(pxX, pxY, 5, 0, Math.PI * 2);
      ctx.stroke();

      ctx.fillStyle = color;
      ctx.font = 'bold 12px Arial';
      ctx.fillText(key, pxX + 8, pxY - 4);

      const mmX = (x * 297).toFixed(1);
      const mmY = (y * 210).toFixed(1);
      ctx.fillStyle = '#000';
      ctx.font = '10px Arial';
      ctx.fillText(`(${mmX}, ${mmY})`, pxX + 8, pxY + 8);
    });
  }, []);

  useEffect(() => {
    const canvas = canvasRef.current;
    if (!canvas || !templateSrc) return;

    const img = new Image();
    img.crossOrigin = 'anonymous';
    img.src = templateSrc;
    img.onload = () => {
      imgRef.current = img;
      draw();
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

  const findNearestField = (coords) => {
    const THRESHOLD = 0.03;
    let best = null;
    let bestDist = THRESHOLD;
    fieldsRef.current.forEach((f, idx) => {
      const dist = Math.sqrt((f.x - coords.x) ** 2 + (f.y - coords.y) ** 2);
      if (dist < bestDist) {
        bestDist = dist;
        best = idx;
      }
    });
    return best;
  };

  const handleMouseDown = (e) => {
    const coords = getCanvasCoords(e);
    const idx = findNearestField(coords);
    if (idx !== null) {
      e.preventDefault();
      draggingRef.current = idx;
    }
  };

  const handleMouseMove = (e) => {
    if (draggingRef.current === null) return;
    e.preventDefault();
    const coords = getCanvasCoords(e);
    const idx = draggingRef.current;
    fieldsRef.current[idx].x = Math.max(0, Math.min(1, coords.x));
    fieldsRef.current[idx].y = Math.max(0, Math.min(1, coords.y));
    draw();
    forceRerender(n => n + 1);
    if (onFieldsChange) onFieldsChange(fieldsRef.current);
  };

  const handleMouseUp = () => {
    draggingRef.current = null;
  };

  return (
    <div style={{ pointerEvents: 'none' }}>
      <canvas
        ref={canvasRef}
        onMouseDown={handleMouseDown}
        onMouseMove={handleMouseMove}
        onMouseUp={handleMouseUp}
        onMouseLeave={handleMouseUp}
        style={{ width: '100%', height: 540, borderRadius: 6, border: '2px solid #666', cursor: 'crosshair', pointerEvents: 'auto' }}
      />
      <div style={{ marginTop: 6, fontSize: 12, color: '#666', display: 'flex', gap: 20 }}>
        <span><span style={{ color: '#dc2626', fontWeight: 600 }}>●</span> Champs français (rouge)</span>
        <span><span style={{ color: '#2563eb', fontWeight: 600 }}>●</span> Champs arabes (bleu)</span>
      </div>
      <div style={{ fontSize: 12, color: '#888', marginTop: 2 }}>Cliquez sur un repère et glissez-le vers la bonne position</div>
    </div>
  );
}

export default function Diplomes() {
  const [diplomes,   setDiplomes]   = useState([]);
  const [loading,    setLoading]    = useState(true);
  const [generating, setGenerating] = useState(null);
  const [previewing, setPreviewing] = useState(null);
  const [genAllLoading, setGenAllLoading] = useState(false);
  const [calibrating, setCalibrating] = useState(false);
  const [calibFields, setCalibFields] = useState(FIELDS.map(f => ({ ...f })));
  const toast = useToast();
  const { currentAnnee, loading: anneeLoading } = useAnneeAcademique();

  const load = useCallback(() => {
    if (anneeLoading) return;
    setLoading(true);
    diplomesApi.getDiplomes({ annee_academique_id: currentAnnee?.id })
      .then(res => setDiplomes(res.data.data || []))
      .catch(() => toast.error('Erreur chargement des diplômes'))
      .finally(() => setLoading(false));
  }, [currentAnnee, anneeLoading]);

  useEffect(() => { load(); }, [load]);

  const handleGenerate = async (diplome) => {
    setGenerating(diplome.id);
    try {
      await ensureArabicFont();

      const { jsPDF } = await import('jspdf');
      const diplomaTemplate = (await import('../../assets/diplome_template.jpg')).default;

      const res  = await diplomesApi.downloadDiplome(diplome.id);
      const data = res.data.data;
      data._templateSrc = diplomaTemplate;

      const img = await new Promise((resolve, reject) => {
        const i = new Image();
        i.crossOrigin = 'anonymous';
        i.src = diplomaTemplate;
        i.onload = () => resolve(i);
        i.onerror = reject;
      });

      const p = new jsPDF({ orientation: 'landscape', unit: 'mm', format: 'a4' });
      const pdfW = p.internal.pageSize.getWidth();
      const pdfH = p.internal.pageSize.getHeight();

      const canvas = document.createElement('canvas');
      canvas.width  = img.naturalWidth;
      canvas.height = img.naturalHeight;
      canvas.getContext('2d').drawImage(img, 0, 0);
      const imgData = canvas.toDataURL('image/jpeg', 0.95);

      p.addImage(imgData, 'JPEG', 0, 0, pdfW, pdfH);

      if (arabicFontB64) {
        p.addFileToVFS('Amiri.ttf', arabicFontB64);
        p.addFont('Amiri.ttf', 'Amiri', 'normal');
      }

      for (const field of FIELDS) {
        const { key, x, y, size, bold, group } = field;
        const value = data[key];
        if (!value) continue;

        const mmX = (x * pdfW).toFixed(1);
        const mmY = (y * pdfH).toFixed(1);

        if (group === 'fr') {
          if (key === 'filiere') {
            // Combine type_formation + filiere: "Qualification Professionnelle En Aide-Soignant(e)"
            const typeFormation = data.type_formation || '';
            const filiereName = String(value);
            const fullText = [typeFormation, filiereName].filter(Boolean).join(' ');
            const words = fullText.split(' ');
            let curX = x * pdfW;
            const yPos = y * pdfH;
            words.forEach((word) => {
              if (word === 'En') {
                p.setTextColor(0, 0, 0);
              } else {
                p.setTextColor(0, 150, 255);
              }
              p.setFont('helvetica', bold ? 'bold' : 'normal');
              p.setFontSize(size);
              p.text(word, curX, yPos);
              curX += p.getTextWidth(word + ' ');
            });
          } else if (key === 'type_formation') {
            // Skip - already handled via filiere
          } else {
            p.setFont('helvetica', bold ? 'bold' : 'normal');
            p.setFontSize(size);
            p.setTextColor(0, 0, 0);
            p.text(String(value), x * pdfW, y * pdfH);
          }
        } else {
          let textToRender;
          if (key === 'filiere_ar') {
            const typeFormationAr = data.type_formation_ar || '';
            const filiereNameAr = String(value);
            textToRender = [typeFormationAr, filiereNameAr].filter(Boolean).join(' ');
          } else if (key === 'type_formation_ar') {
            continue;
          } else {
            textToRender = String(value);
          }

          const pxSize = size * 1.333;
          const rendered = renderArabicToImage(textToRender, pxSize, bold);
          const mmPerPx = 25.4 / 96;
          const imgW = rendered.width * mmPerPx;
          const imgH = rendered.height * mmPerPx;
          const imgX = x * pdfW - imgW;
          const imgY = y * pdfH - rendered.ascent * mmPerPx;
          p.addImage(rendered.dataUrl, 'PNG', imgX, imgY, imgW, imgH);
        }
      }

      const blob = p.output('blob');
      const url  = URL.createObjectURL(blob);
      setCalibFields(FIELDS.map(f => ({ ...f })));
      setCalibrating(false);
      setPreviewing({ id: diplome.id, url, pdf: p, data });
    } catch (err) {
      console.error('PDF generation error:', err);
      toast.error('Erreur lors de la génération du diplôme');
    } finally {
      setGenerating(null);
    }
  };

  const handleDownload = () => {
    if (!previewing) return;
    previewing.pdf.save(`Diplome_${previewing.data.nom_prenom.replace(/\s+/g, '_')}.pdf`);
    diplomesApi.markPrinted(previewing.id)
      .then(load)
      .catch(() => {});
    URL.revokeObjectURL(previewing.url);
    setPreviewing(null);
    setCalibrating(false);
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
    if (!currentAnnee) return;
    if (!window.confirm(`Générer les diplômes pour tous les étudiants admis de la promotion ${currentAnnee.label} ?`)) return;

    setGenAllLoading(true);
    try {
      const res = await diplomesApi.generateAllDiplomes({
        annee_academique_id: currentAnnee.id
      });
      const { created, skipped, total } = res.data.data;
      toast.success(`${created} diplôme(s) généré(s) pour les admis, ${skipped} non-admis`);
      load();
    } catch {
      toast.error('Erreur lors de la génération des diplômes');
    } finally {
      setGenAllLoading(false);
    }
  };

  const copyFieldsCode = () => {
    const code = `const FIELDS = [\n${calibFields.map(f =>
      `  { key: '${f.key}', x: ${f.x.toFixed(3)}, y: ${f.y.toFixed(3)}, size: ${f.size}, bold: ${f.bold}, group: '${f.group}' }`
    ).join(',\n')}\n];`;
    navigator.clipboard.writeText(code).then(() => toast.success('Coordonnées copiées !'));
  };

  if (loading) return <Spinner />;

  return (
    <div className="page">
      <div className="page-header">
        <h2 className="page-title">Diplômes</h2>
        <div style={{ display: 'flex', gap: 12, alignItems: 'center' }}>
          {currentAnnee && (
            <span style={{ padding: '8px 14px', background: '#e8f5e9', color: '#2e7d32', fontWeight: 'bold', borderRadius: 6, fontSize: 14 }}>
              {currentAnnee.label}
            </span>
          )}
          <button className="btn btn-primary" onClick={handleGenerateAll} disabled={genAllLoading || !currentAnnee}>
            {genAllLoading ? 'Génération...' : 'Générer tous les diplômes'}
          </button>
        </div>
      </div>

      {previewing && (
        <div style={{ position: 'fixed', inset: 0, background: 'rgba(0,0,0,0.75)', zIndex: 1000, display: 'flex', alignItems: 'center', justifyContent: 'center', padding: 24 }}>
          <div style={{ background: '#fff', borderRadius: 10, padding: 20, width: '100%', maxWidth: 1100, maxHeight: '90vh', display: 'flex', flexDirection: 'column', gap: 12, overflowY: 'auto' }}>
            <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
              <h3 style={{ margin: 0, fontSize: 18 }}>Aperçu du diplôme</h3>
              <div style={{ display: 'flex', gap: 8, alignItems: 'center' }}>
                <button
                  className="btn btn-sm"
                  style={{ background: calibrating ? '#dc2626' : '#f59e0b', color: '#fff' }}
                  onClick={() => setCalibrating(!calibrating)}
                >
                  {calibrating ? 'Quitter calibrage' : 'Mode calibrage'}
                </button>
                <button className="btn btn-outline" onClick={() => { URL.revokeObjectURL(previewing.url); setPreviewing(null); setCalibrating(false); }}>Fermer</button>
              </div>
            </div>

            <div style={{ position: 'relative', width: '100%' }}>
              {!calibrating ? (
                <iframe
                  src={previewing.url}
                  style={{ width: '100%', height: 540, border: 'none', borderRadius: 6 }}
                  title="Aperçu diplôme"
                />
              ) : (
                <CalibrationOverlay
                  templateSrc={previewing.data._templateSrc}
                  onFieldsChange={(fields) => setCalibFields(fields.map(f => ({ ...f })))}
                />
              )}
            </div>

            {calibrating && (
              <div style={{ background: '#f0f9ff', border: '1px solid #3b82f6', borderRadius: 6, padding: 12 }}>
                <div style={{ fontWeight: 600, marginBottom: 8, fontSize: 14 }}>Coordonnées actuelles</div>
                <div style={{ background: '#1e293b', color: '#22c55e', fontFamily: 'monospace', fontSize: 11, padding: 12, borderRadius: 4, whiteSpace: 'pre', maxHeight: 220, overflow: 'auto', lineHeight: 1.6 }}>
{`const FIELDS = [\n${calibFields.map(f =>
  `  { key: '${f.key}', x: ${f.x.toFixed(3)}, y: ${f.y.toFixed(3)}, size: ${f.size}, bold: ${f.bold}, group: '${f.group}' }`
).join(',\n')}\n];`}
                </div>
                <button
                  className="btn btn-sm"
                  style={{ marginTop: 8, background: '#3b82f6', color: '#fff' }}
                  onClick={copyFieldsCode}
                >
                  Copier les coordonnées
                </button>
              </div>
            )}

            <div style={{ display: 'flex', gap: 10, justifyContent: 'flex-end' }}>
              <button className="btn btn-outline" onClick={() => { URL.revokeObjectURL(previewing.url); setPreviewing(null); setCalibrating(false); }}>
                Annuler
              </button>
              <button className="btn btn-primary" onClick={handleDownload}>
                Télécharger & Marquer imprimé
              </button>
            </div>
          </div>
        </div>
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
                    onClick={() => handleGenerate(d)}
                    disabled={generating === d.id}
                  >
                    {generating === d.id ? 'Génération...' : 'Générer PDF'}
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
