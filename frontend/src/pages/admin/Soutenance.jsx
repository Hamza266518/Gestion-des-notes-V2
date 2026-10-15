import { useState, useEffect, useCallback } from 'react';
import { FiUploadCloud, FiSave } from 'react-icons/fi';
import { adminApi } from '../../api/admin';
import { etudiantsApi } from '../../api/etudiants';
import { notesApi } from '../../api/notes';
import { soutenanceApi } from '../../api/soutenance';
import { publicationsApi } from '../../api/publications';
import { useAnneeAcademique } from '../../context/AnneeAcademiqueContext';
import { useToast } from '../../context/ToastContext';
import { handleApiError, showSuccess } from '../../utils/errorHandler';
import { formatNiveau, estSemestrePublique, MESSAGE_SEMESTRE_PUBLIE, invalidNoteHint } from '../../utils/helpers';
import Spinner from '../../components/common/Spinner';
import EmptyState from '../../components/common/EmptyState';
import FilterBar from '../../components/common/FilterBar';
import FilterSelect from '../../components/common/FilterSelect';
import usePersistedState from '../../hooks/usePersistedState';
import '../../css/components.css';
import '../../css/layout.css';

// One cell of the grid, keyed unambiguously so a student and a criterion can
// never collide.
const cellKey = (etudiantId, controleId) => `${etudiantId}:${controleId}`;

export default function Soutenance() {
  const toast = useToast();
  const { currentAnnee } = useAnneeAcademique();

  const [saved, setSaved] = usePersistedState('soutenance', {
    filiere_id: '',
    niveau_id: '',
    groupe_id: '',
  });
  const [filiereId, setFiliereId] = useState(() => saved.filiere_id);
  const [niveauId, setNiveauId] = useState(() => saved.niveau_id);
  const [groupeId, setGroupeId] = useState(() => saved.groupe_id);

  useEffect(() => {
    setSaved({ filiere_id: filiereId, niveau_id: niveauId, groupe_id: groupeId });
  }, [filiereId, niveauId, groupeId, setSaved]);

  const [filieres, setFilieres] = useState([]);
  const [niveaux, setNiveaux] = useState([]);
  const [groupes, setGroupes] = useState([]);

  const [status, setStatus] = useState(null);
  const [loadingStatus, setLoadingStatus] = useState(false);

  const [etudiants, setEtudiants] = useState([]);
  const [values, setValues] = useState({});
  const [invalidCells, setInvalidCells] = useState({});
  const [publications, setPublications] = useState([]);
  const [loadingData, setLoadingData] = useState(false);

  const [pdfs, setPdfs] = useState([]);
  const [scanning, setScanning] = useState(false);
  const [saving, setSaving] = useState(false);

  const criteres = status?.criteres ?? [];
  const unite = status?.unite ?? null;
  const hasSoutenance = !!status?.has_soutenance;

  useEffect(() => {
    adminApi.getFilieres()
      .then(res => setFilieres(res.data.data || []))
      .catch(() => setFilieres([]));
  }, []);

  useEffect(() => {
    if (!filiereId) {
      setNiveaux([]);
      setNiveauId('');
      setGroupeId('');
      return;
    }
    adminApi.getNiveaux(filiereId)
      .then(res => {
        const data = res.data.data || [];
        setNiveaux(data);
        setNiveauId(cur => (data.some(n => String(n.id) === String(cur)) ? cur : ''));
      })
      .catch(() => setNiveaux([]));
  }, [filiereId]);

  // The Soutenance unit decides whether this programme is eligible at all, and
  // it carries the exact criteria to display.
  useEffect(() => {
    if (!filiereId || !niveauId) {
      setStatus(null);
      setGroupes([]);
      setGroupeId('');
      return;
    }
    setLoadingStatus(true);
    soutenanceApi.status({ filiere_id: filiereId, niveau_id: niveauId })
      .then(res => setStatus(res.data?.data || null))
      .catch(() => setStatus(null))
      .finally(() => setLoadingStatus(false));

    adminApi.getGroupes({ niveau_id: niveauId, annee_academique_id: currentAnnee?.id })
      .then(res => {
        const data = res.data.data || [];
        setGroupes(data);
        setGroupeId(cur => (data.some(g => String(g.id) === String(cur)) ? cur : ''));
      })
      .catch(() => setGroupes([]));
  }, [filiereId, niveauId, currentAnnee]);

  const loadData = useCallback(async () => {
    if (!hasSoutenance || !unite || !groupeId || !currentAnnee?.id) return;
    setLoadingData(true);
    try {
      const [rosterRes, notesRes, pubsRes] = await Promise.all([
        etudiantsApi.getEtudiants({ groupe_id: groupeId, annee_academique_id: currentAnnee.id }),
        notesApi.getNotes({ unite_id: unite.id, groupe_id: groupeId }),
        publicationsApi.getPublications({ groupe_id: groupeId, annee_academique_id: currentAnnee.id }),
      ]);
      const roster = rosterRes.data?.data || [];
      const notes = notesRes.data?.data || [];
      setEtudiants(roster);
      setPublications(pubsRes.data?.data || []);

      const next = {};
      roster.forEach(e => criteres.forEach(c => {
        const n = notes.find(x => x.etudiant_id === e.id && x.controle_id === c.id);
        next[cellKey(e.id, c.id)] = n ? (n.est_absent ? 'AB' : String(n.valeur ?? '')) : '';
      }));
      setValues(next);
      setInvalidCells({});
    } catch (err) {
      handleApiError(err, toast);
      setEtudiants([]);
      setValues({});
    } finally {
      setLoadingData(false);
    }
  }, [hasSoutenance, unite, groupeId, currentAnnee, criteres, toast]);

  useEffect(() => { loadData(); }, [loadData]);

  const estVerrouille = estSemestrePublique(publications, {
    groupeId,
    anneeId: currentAnnee?.id,
    semestre: unite?.semestre ?? 2,
  });

  const setCell = (etudiantId, controleId, text) => {
    const key = cellKey(etudiantId, controleId);
    setValues(prev => ({ ...prev, [key]: text }));
    // A hand correction clears the "misread" flag once the text changes.
    setInvalidCells(prev => {
      if (!prev[key]) return prev;
      const next = { ...prev };
      delete next[key];
      return next;
    });
  };

  const handleScan = async () => {
    if (pdfs.length === 0 || !unite || !groupeId || !currentAnnee?.id) {
      toast.warning('Sélectionnez un groupe et ajoutez au moins un PDF.');
      return;
    }
    if (etudiants.length > 0) {
      const ok = window.confirm('Un nouveau scan remplace les notes affichées, y compris celles saisies à la main. Continuer ?');
      if (!ok) return;
    }
    setScanning(true);
    try {
      const fd = new FormData();
      pdfs.forEach(p => fd.append('pdfs[]', p));
      fd.append('unite_id', unite.id);
      fd.append('annee_academique_id', currentAnnee.id);
      fd.append('filiere_id', filiereId);
      fd.append('groupe_id', groupeId);

      const res = await soutenanceApi.scan(fd);
      const data = res.data;
      if (!data.success) {
        toast.error(data.message || 'Erreur lors du scan');
        return;
      }

      const resultats = data.data?.resultats || [];
      if (resultats.length === 0) {
        toast.error('Aucun résultat. Vérifiez que les PDF sont lisibles.');
        return;
      }

      const next = { ...values };
      const flags = { ...invalidCells };
      let nonReconnu = 0;

      resultats.forEach(r => {
        if (!r.etudiant_id) { nonReconnu++; return; }
        (r.notes || []).forEach((cell, i) => {
          const critere = criteres[i];
          if (!critere) return;
          const key = cellKey(r.etudiant_id, critere.id);
          if (cell.est_absent) {
            next[key] = 'AB';
          } else if (cell.invalid) {
            next[key] = cell.raw ?? '';
            flags[key] = { invalid: true, raw: cell.raw ?? null };
          } else {
            next[key] = cell.valeur != null ? String(cell.valeur) : '';
          }
        });
      });

      setValues(next);
      setInvalidCells(flags);

      let msg = `${resultats.length} étudiant(s) lu(s)`;
      if (nonReconnu > 0) msg += `, ${nonReconnu} non reconnu(s) (nom absent de ce groupe)`;
      showSuccess(toast, msg);

      const flagged = Object.keys(flags).length;
      if (flagged > 0) {
        toast.warning(`${flagged} note(s) illisible(s) : corrigez-les avant d'enregistrer.`);
      }
    } catch (err) {
      handleApiError(err, toast);
    } finally {
      setScanning(false);
    }
  };

  const handleConfirm = async () => {
    if (!unite || !groupeId || !currentAnnee?.id) return;
    if (estVerrouille) {
      toast.warning(MESSAGE_SEMESTRE_PUBLIE);
      return;
    }
    const flagged = Object.keys(invalidCells).filter(k => (values[k] ?? '').trim() !== '');
    if (flagged.length > 0) {
      toast.warning(`${flagged.length} note(s) illisible(s). Corrigez-les (ou videz la case) avant d'enregistrer.`);
      return;
    }

    const notes = [];
    etudiants.forEach(e => criteres.forEach(c => {
      const text = (values[cellKey(e.id, c.id)] ?? '').trim();
      if (text === '') return;
      notes.push({ etudiant_id: e.id, controle_id: c.id, valeur: text });
    }));

    if (notes.length === 0) {
      toast.warning('Aucune note à enregistrer.');
      return;
    }

    setSaving(true);
    try {
      const res = await soutenanceApi.confirm({
        unite_id: unite.id,
        annee_academique_id: currentAnnee.id,
        groupe_id: groupeId,
        notes,
      });
      showSuccess(toast, res.data.message || 'Notes de soutenance enregistrées');
      const skipped = res.data.skipped || [];
      if (skipped.length > 0) {
        const raisons = [...new Set(skipped.map(s => s.raison).filter(Boolean))];
        toast.warning(`${skipped.length} ligne(s) non enregistrée(s)${raisons.length ? ' — ' + raisons.join(' · ') : ''}`);
      }
      setPdfs([]);
      loadData();
    } catch (err) {
      handleApiError(err, toast);
    } finally {
      setSaving(false);
    }
  };

  const critereLabel = (c) => `${c.numero}. ${c.nom}`;

  return (
    <div className="page">
      <div className="page-header">
        <h2 className="page-title">Soutenance</h2>
        <p style={{ margin: '4px 0 0', fontSize: 13, color: 'var(--gray-500)' }}>
          Notes du jury (NSTI). Chaque critère est une note sur 20 ; saisissez-les ou scannez la feuille du jury.
        </p>
      </div>

      <div className="card" style={{ marginBottom: 16 }}>
        <div className="card-body">
          <FilterBar
            showSearch={false}
            value={{ filiere_id: filiereId, niveau_id: niveauId, groupe_id: groupeId }}
            onChange={() => {}}
            onClear={() => { setFiliereId(''); setNiveauId(''); setGroupeId(''); }}
            chips={[
              filiereId && {
                key: 'filiere',
                label: `Filière: ${filieres.find(f => String(f.id) === String(filiereId))?.nom ?? ''}`,
                onRemove: () => { setFiliereId(''); setNiveauId(''); setGroupeId(''); },
              },
              niveauId && {
                key: 'niveau',
                label: `Niveau: ${formatNiveau(niveaux.find(n => String(n.id) === String(niveauId))?.numero)}`,
                onRemove: () => { setNiveauId(''); setGroupeId(''); },
              },
              groupeId && {
                key: 'groupe',
                label: `Groupe: ${groupes.find(g => String(g.id) === String(groupeId))?.nom ?? ''}`,
                onRemove: () => setGroupeId(''),
              },
            ].filter(Boolean)}
          >
            <FilterSelect
              label="Filière"
              value={filiereId}
              options={filieres.map(f => ({ id: f.id, label: f.nom }))}
              onChange={(v) => { setFiliereId(v); setNiveauId(''); setGroupeId(''); }}
              placeholder="Toutes les filières"
              allLabel="Toutes les filières"
            />
            <FilterSelect
              label="Niveau"
              value={niveauId}
              options={niveaux.map(n => ({ id: n.id, label: formatNiveau(n.numero) }))}
              onChange={(v) => { setNiveauId(v); setGroupeId(''); }}
              disabled={!filiereId}
              placeholder="Tous les niveaux"
              allLabel="Tous les niveaux"
            />
            <FilterSelect
              label="Groupe"
              value={groupeId}
              options={groupes.map(g => ({ id: g.id, label: g.nom }))}
              onChange={setGroupeId}
              disabled={!niveauId || !hasSoutenance}
              placeholder="Tous les groupes"
              allLabel="Tous les groupes"
            />
          </FilterBar>
        </div>
      </div>

      {loadingStatus && (
        <div style={{ textAlign: 'center', padding: 40 }}><Spinner /></div>
      )}

      {!loadingStatus && niveauId && !hasSoutenance && (
        <EmptyState message="Cette filière / année n'a pas de soutenance : les notes se calculent normalement." />
      )}

      {hasSoutenance && (
        <>
          <div className="card" style={{ marginBottom: 16 }}>
            <div className="card-body">
              <div style={{ display: 'flex', alignItems: 'center', gap: 12, flexWrap: 'wrap' }}>
                <strong style={{ fontSize: 13 }}>Feuille du jury :</strong>
                <input
                  type="file"
                  accept=".pdf,application/pdf"
                  multiple
                  onChange={e => setPdfs(Array.from(e.target.files || []))}
                  disabled={scanning || estVerrouille}
                />
                <button
                  className="btn btn-outline"
                  onClick={handleScan}
                  disabled={scanning || pdfs.length === 0 || estVerrouille || !groupeId}
                >
                  <FiUploadCloud style={{ marginRight: 6 }} />
                  {scanning ? 'Lecture en cours...' : 'Scanner'}
                </button>
                {pdfs.length > 0 && (
                  <span style={{ fontSize: 13, color: 'var(--gray-500)' }}>
                    {pdfs.length} fichier(s) sélectionné(s)
                  </span>
                )}
              </div>
            </div>
          </div>

          {estVerrouille && (
            <div className="alert alert-warning" style={{ marginBottom: 16 }}>{MESSAGE_SEMESTRE_PUBLIE}</div>
          )}

          {!groupeId ? (
            <EmptyState message="Sélectionnez un groupe pour saisir ou scanner ses notes de soutenance." />
          ) : loadingData ? (
            <div style={{ textAlign: 'center', padding: 40 }}><Spinner /></div>
          ) : etudiants.length === 0 ? (
            <EmptyState message="Aucun étudiant dans ce groupe." />
          ) : (
            <>
              <div className="table-wrap" style={{ overflowX: 'auto' }}>
                <table className="table mb-0" style={{ minWidth: criteres.length * 132 + 220 }}>
                  <thead>
                    <tr>
                      <th style={{ minWidth: 220 }}>Étudiant</th>
                      {criteres.map(c => (
                        <th key={c.id} style={{ minWidth: 120 }} title={c.nom}>{critereLabel(c)}</th>
                      ))}
                    </tr>
                  </thead>
                  <tbody>
                    {etudiants.map(e => (
                      <tr key={e.id}>
                        <td>
                          <strong>{e.nom_prenom}</strong>
                          {e.numero_inscription && (
                            <div style={{ fontSize: 12, color: 'var(--gray-500)' }}>#{e.numero_inscription}</div>
                          )}
                        </td>
                        {criteres.map(c => {
                          const key = cellKey(e.id, c.id);
                          const flag = invalidCells[key];
                          const hint = flag ? invalidNoteHint(flag.raw) : '';
                          return (
                            <td key={c.id}>
                              <input
                                type="text"
                                className="form-input"
                                value={values[key] ?? ''}
                                onChange={ev => setCell(e.id, c.id, ev.target.value)}
                                placeholder="—"
                                disabled={estVerrouille}
                                style={{ width: 96, textAlign: 'center' }}
                              />
                              {hint && (
                                <div style={{ fontSize: 11, color: 'var(--danger)', marginTop: 4, maxWidth: 120 }}>
                                  {hint}
                                </div>
                              )}
                            </td>
                          );
                        })}
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>

              <div style={{ display: 'flex', justifyContent: 'flex-end', gap: 12, marginTop: 16 }}>
                <button
                  className="btn btn-primary"
                  onClick={handleConfirm}
                  disabled={saving || estVerrouille || !groupeId}
                >
                  <FiSave style={{ marginRight: 6 }} />
                  {saving ? 'Enregistrement...' : 'Enregistrer les notes'}
                </button>
              </div>
            </>
          )}
        </>
      )}
    </div>
  );
}
