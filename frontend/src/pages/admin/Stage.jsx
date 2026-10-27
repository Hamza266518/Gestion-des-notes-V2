import { useState, useEffect, useCallback } from 'react';
import { FiSearch, FiX, FiDownload } from 'react-icons/fi';
import { adminApi } from '../../api/admin';
import { etudiantsApi } from '../../api/etudiants';
import { stagesApi } from '../../api/stages';
import { useAnneeAcademique } from '../../context/AnneeAcademiqueContext';
import { useToast } from '../../context/ToastContext';
import { handleApiError } from '../../utils/errorHandler';
import { formatNiveau } from '../../utils/helpers';
import GardeDeNuit from '../../components/stage/GardeDeNuit';
import GrilleEvaluationStage from '../../components/stage/GrilleEvaluationStage';
import GrilleExamenStage from '../../components/stage/GrilleExamenStage';
import Spinner from '../../components/common/Spinner';
import Modal from '../../components/common/Modal';
import { DOCX_MIME, saveBlob, filenameFromDisposition } from '../../components/admin/RecapTable';
import FilterBar from '../../components/common/FilterBar';
import FilterSelect from '../../components/common/FilterSelect';
import usePersistedState from '../../hooks/usePersistedState';
import '../../css/components.css';
import '../../css/layout.css';

const TABS = [
  { key: 'garde_nuit', label: 'Garde de Nuit' },
  { key: 'grille_evaluation', label: "Grille d'Évaluation" },
  { key: 'grille_examen', label: "Grille d'Examen" },
];

export default function Stage() {
  const toast = useToast();
  const { currentAnnee } = useAnneeAcademique();

  const [filieres, setFilieres] = useState([]);
  const [niveaux, setNiveaux] = useState([]);
  const [groupes, setGroupes] = useState([]);
  const [etudiants, setEtudiants] = useState([]);

  const [saved, setSaved] = usePersistedState('stage', { filiereId: '', niveauId: '', groupeId: '', etudiantId: '' });
  const [filiereId, setFiliereId] = useState(() => saved.filiereId);
  const [niveauId, setNiveauId] = useState(() => saved.niveauId);
  const [groupeId, setGroupeId] = useState(() => saved.groupeId);
  const [etudiantId, setEtudiantId] = useState(() => saved.etudiantId);

  useEffect(() => {
    setSaved({ filiereId, niveauId, groupeId, etudiantId });
  }, [filiereId, niveauId, groupeId, etudiantId, setSaved]);

  const [selectedStudent, setSelectedStudent] = useState(null);
  const [activeTab, setActiveTab] = useState('garde_nuit');
  const [loadingEtudiants, setLoadingEtudiants] = useState(false);
  const [moyennes, setMoyennes] = useState({});

  // Re-read what the grids pushed into the notes tables, so the effect on the
  // average is visible right after a save without reloading the page.
  useEffect(() => {
    if (!selectedStudent?.id || !currentAnnee?.id) {
      setMoyennes({});
      return;
    }
    let cancelled = false;
    setMoyennes({});
    stagesApi.load({
      etudiant_id: selectedStudent.id,
      annee_academique_id: currentAnnee.id,
      type: 'grille_evaluation',
    })
      .then(res => { if (!cancelled) setMoyennes(res.data.moyennes || {}); })
      .catch(() => { if (!cancelled) setMoyennes({}); });
    return () => { cancelled = true; };
  }, [selectedStudent?.id, currentAnnee?.id]);

  // Called by a grid right after it saves, to show the notes it just wrote.
  // Not memoized on purpose: it is only invoked from a child's click handler,
  // never from a dependency array.
  const refreshMoyennes = () => {
    if (!selectedStudent?.id || !currentAnnee?.id) return;
    stagesApi.load({
      etudiant_id: selectedStudent.id,
      annee_academique_id: currentAnnee.id,
      type: 'grille_evaluation',
    })
      .then(res => setMoyennes(res.data.moyennes || {}))
      .catch(() => setMoyennes({}));
  };

  // Word download of the grids: a dialog picks the document, who it is for, the
  // content (blank sheet / saved marks), the Durée and, for the exam sheet, the
  // technique being examined. The stage place and service come from the calendar,
  // so there is nothing to choose there.
  const [downloadOpen, setDownloadOpen] = useState(false);
  const [downloading, setDownloading] = useState(false);
  const [savedServices, setSavedServices] = useState([]);
  const [dl, setDl] = useState({ type: 'grille_evaluation', scope: 'etudiant', mode: 'filled', service: '', duree: '', pratique_executee: '', dureeDebut: '', dureeFin: '' });

  const openDownload = async () => {
    const baseType = activeTab === 'grille_examen' ? 'grille_examen' : 'grille_evaluation';
    const scope = selectedStudent ? 'etudiant' : 'groupe';
    setDl({ type: baseType, scope, mode: 'filled', service: '', duree: '', pratique_executee: '', dureeDebut: '', dureeFin: '' });
    setSavedServices([]);
    setDownloadOpen(true);
    if (selectedStudent?.id) {
      try {
        const res = await stagesApi.getSavedServices({ etudiant_id: selectedStudent.id, type: baseType });
        setSavedServices(res.data?.data || []);
      } catch {
        setSavedServices([]);
      }
    }
  };

  const formatDateFR = (value) => {
    if (!value) return '';
    const parts = value.split('-');
    if (parts.length !== 3) return value;
    return `${parts[2]}/${parts[1]}/${parts[0]}`;
  };

  const buildDuree = (d) => {
    const debut = formatDateFR(d.dureeDebut);
    const fin = formatDateFR(d.dureeFin);
    if (debut && fin) {
      return `du ${debut} au ${fin}`;
    }
    if (debut) {
      return `du ${debut}`;
    }
    if (fin) {
      return `au ${fin}`;
    }
    return (d.duree || '').toString().trim();
  };

  const runDownload = async () => {
    setDownloading(true);
    try {
      const base = {
        type: dl.type,
        mode: dl.mode,
        duree: buildDuree(dl) || undefined,
        pratique_executee: dl.pratique_executee || undefined,
        service: dl.service || undefined,
      };
      const isGroup = dl.scope === 'groupe';
      const res = isGroup
        ? await stagesApi.downloadGrilleGroupe({ ...base, groupe_id: groupeId })
        : await stagesApi.downloadGrille({ ...base, etudiant_id: selectedStudent?.id });

      const blob = new Blob([res.data], {
        type: isGroup ? 'application/zip' : DOCX_MIME,
      });
      saveBlob(blob, filenameFromDisposition(
        res.headers?.['content-disposition'],
        isGroup ? 'Grilles.zip' : 'Grille.docx'
      ));
      setDownloadOpen(false);
    } catch (err) {
      handleApiError(err, toast);
    } finally {
      setDownloading(false);
    }
  };

  // Text search fallback
  const [search, setSearch] = useState('');
  const [searchResults, setSearchResults] = useState([]);
  const [searching, setSearching] = useState(false);

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
        const niveauxData = res.data.data || [];
        setNiveaux(niveauxData);
        setNiveauId(cur => (niveauxData.some(n => String(n.id) === String(cur)) ? cur : ''));
      })
      .catch(() => setNiveaux([]));
  }, [filiereId]);

  useEffect(() => {
    if (!niveauId || !currentAnnee) {
      setGroupes([]);
      setGroupeId('');
      return;
    }
    adminApi.getGroupes({ niveau_id: niveauId, annee_academique_id: currentAnnee.id })
      .then(res => {
        const groupesData = res.data.data || [];
        setGroupes(groupesData);
        setGroupeId(cur => (groupesData.some(g => String(g.id) === String(cur)) ? cur : ''));
      })
      .catch(() => setGroupes([]));
  }, [niveauId, currentAnnee]);

  useEffect(() => {
    if (!groupeId) {
      setEtudiants([]);
      setEtudiantId('');
      return;
    }
    setLoadingEtudiants(true);
    etudiantsApi.getEtudiants({ groupe_id: groupeId })
      .then(res => {
        const etudiantsData = res.data.data || [];
        setEtudiants(etudiantsData);
        setEtudiantId(cur => (etudiantsData.some(e => String(e.id) === String(cur)) ? cur : ''));
      })
      .catch(() => setEtudiants([]))
      .finally(() => setLoadingEtudiants(false));
  }, [groupeId]);

  useEffect(() => {
    if (etudiantId) {
      const s = etudiants.find(e => e.id == etudiantId);
      if (s) setSelectedStudent(formatStudent(s));
    } else {
      setSelectedStudent(null);
    }
  }, [etudiantId, etudiants]);

  const searchStudents = useCallback(async (q) => {
    if (!q || q.length < 1) { setSearchResults([]); return; }
    setSearching(true);
    try {
      const res = await stagesApi.searchStudents(q);
      setSearchResults(res.data.data || []);
    } catch { setSearchResults([]); } finally { setSearching(false); }
  }, []);

  useEffect(() => {
    if (!search.trim()) { setSearchResults([]); return; }
    const timer = setTimeout(() => searchStudents(search.trim()), 300);
    return () => clearTimeout(timer);
  }, [search, searchStudents]);

  const formatStudent = (s) => ({
    id: s.id,
    nom_prenom: s.nom_prenom,
    cin: s.cin,
    numero_inscription: s.numero_inscription,
    filiere: s.groupe?.niveau?.filiere?.nom ?? s.filiere ?? '',
    section: s.groupe?.niveau?.filiere?.section ?? s.section ?? '',
    type_formation: s.groupe?.niveau?.filiere?.type_formation ?? s.type_formation ?? '',
    niveau_numero: s.groupe?.niveau?.numero ?? s.niveau_numero ?? '',
    groupe_nom: s.groupe?.nom ?? s.groupe_nom ?? '',
  });

  const selectSearchResult = (s) => {
    setSelectedStudent(s);
    setSearch(s.nom_prenom);
    setSearchResults([]);
    setFiliereId(''); setNiveauId(''); setGroupeId(''); setEtudiantId('');
  };

  const clearStudent = () => {
    setSelectedStudent(null);
    setSearch(''); setSearchResults([]);
    setFiliereId(''); setNiveauId(''); setGroupeId(''); setEtudiantId('');
  };

  const displayStudent = selectedStudent ? {
    ...selectedStudent,
    annee_label: currentAnnee?.label ?? '',
  } : null;

  const renderForm = () => {
    if (!displayStudent) {
      return <div className="empty-state"><p>Sélectionnez un étudiant pour commencer.</p></div>;
    }
    switch (activeTab) {
      case 'garde_nuit':
        return <GardeDeNuit etudiant={displayStudent} onSaved={refreshMoyennes} />;
      case 'grille_evaluation':
        return <GrilleEvaluationStage etudiant={displayStudent} onSaved={refreshMoyennes} />;
      case 'grille_examen':
        return <GrilleExamenStage etudiant={displayStudent} onSaved={refreshMoyennes} />;
      default: return null;
    }
  };

  const syncedMarks = Object.values(moyennes || {});

  return (
    <div className="page">
      <div className="page-header" style={{display:'flex', alignItems:'center', justifyContent:'space-between', gap:12, flexWrap:'wrap'}}>
        {selectedStudent ? (
          <div style={{display:'flex', alignItems:'center', gap:12, flexWrap:'wrap'}}>
            <div style={{padding:'4px 12px', background:'#e0f2fe', borderRadius:6, fontSize:13}}>
              <strong>{selectedStudent.nom_prenom}</strong>
              {selectedStudent.filiere && <span style={{color:'#666', marginLeft:8}}>{selectedStudent.filiere}</span>}
              {selectedStudent.numero_inscription && <span style={{color:'#999', marginLeft:8}}>#{selectedStudent.numero_inscription}</span>}
            </div>
            <button className="btn btn-sm btn-outline" onClick={clearStudent}><FiX style={{marginRight:4}}/>Changer</button>
          </div>
        ) : null}
        {(selectedStudent || groupeId) && (
          <button className="btn btn-primary" onClick={openDownload}>
            <FiDownload style={{marginRight:6}} />Télécharger la grille
          </button>
        )}
      </div>

      {selectedStudent && (
        <div className="card" style={{ marginBottom: 16 }}>
          <div className="card-body" style={{ padding: '10px 16px' }}>
            <div style={{ display: 'flex', alignItems: 'center', gap: 16, flexWrap: 'wrap' }}>
              <strong style={{ fontSize: 13 }}>Notes des grilles de stage (coef 4) :</strong>
              {syncedMarks.length === 0 ? (
                <span style={{ fontSize: 13, color: 'var(--gray-500)' }}>
                  aucune note reportée — remplissez une grille d'évaluation ou d'examen
                </span>
              ) : syncedMarks.map(m => (
                <span key={m.controle_id} style={{
                  display: 'inline-flex', alignItems: 'center', gap: 6,
                  padding: '3px 8px', background: 'var(--gray-100)', borderRadius: 6, fontSize: 13
                }}>
                  <strong>{m.service}</strong>
                  <span title="Note d'évaluation" style={{
                    padding: '2px 8px', background: '#dcfce7', color: '#166534',
                    borderRadius: 999, fontWeight: 600
                  }}>
                    Éval. {m.evaluation != null ? Number(m.evaluation).toFixed(2) : '—'}
                  </span>
                  <span title="Note d'examen" style={{
                    padding: '2px 8px', background: '#dbeafe', color: '#1e40af',
                    borderRadius: 999, fontWeight: 600
                  }}>
                    Exam. {m.examen != null ? Number(m.examen).toFixed(2) : '—'}
                  </span>
                </span>
              ))}
            </div>
            <p style={{ margin: '8px 0 0', fontSize: 12, color: 'var(--gray-500)' }}>
              Le stage n'entre dans la moyenne que lorsque tous les services sont notés (évaluation + examen).
            </p>
          </div>
        </div>
      )}

      {!selectedStudent && (
        <div className="card" style={{marginBottom:16}}>
          <div className="card-body">
            <FilterBar
              showSearch={false}
              value={{ filiere_id: filiereId, niveau_id: niveauId, groupe_id: groupeId, etudiant_id: etudiantId }}
              onChange={() => {}}
              onClear={() => {
                setFiliereId('');
                setNiveauId('');
                setGroupeId('');
                setEtudiantId('');
              }}
              chips={[
                filiereId && {
                  key: 'filiere',
                  label: `Filière: ${filieres.find(f => String(f.id) === String(filiereId))?.nom ?? ''}`,
                  onRemove: () => setFiliereId(''),
                },
                niveauId && {
                  key: 'niveau',
                  label: `Niveau: ${formatNiveau(niveaux.find(n => String(n.id) === String(niveauId))?.numero)}`,
                  onRemove: () => { setNiveauId(''); setGroupeId(''); setEtudiantId(''); },
                },
                groupeId && {
                  key: 'groupe',
                  label: `Groupe: ${groupes.find(g => String(g.id) === String(groupeId))?.nom ?? ''}`,
                  onRemove: () => { setGroupeId(''); setEtudiantId(''); },
                },
                etudiantId && {
                  key: 'etudiant',
                  label: `Étudiant: ${etudiants.find(e => String(e.id) === String(etudiantId))?.nom_prenom ?? ''}`,
                  onRemove: () => setEtudiantId(''),
                },
              ].filter(Boolean)}
            >
              <FilterSelect
                label="Filière"
                value={filiereId}
                options={filieres.map(f => ({ id: f.id, label: f.nom }))}
                onChange={(v) => { setFiliereId(v); setNiveauId(''); setGroupeId(''); setEtudiantId(''); }}
                placeholder="Toutes les filières"
                allLabel="Toutes les filières"
              />
              <FilterSelect
                label="Niveau"
                value={niveauId}
                options={niveaux.map(n => ({ id: n.id, label: formatNiveau(n.numero) }))}
                onChange={(v) => { setNiveauId(v); setGroupeId(''); setEtudiantId(''); }}
                disabled={!filiereId}
                placeholder="Tous les niveaux"
                allLabel="Tous les niveaux"
              />
              <FilterSelect
                label="Groupe"
                value={groupeId}
                options={groupes.map(g => ({ id: g.id, label: g.nom }))}
                onChange={(v) => { setGroupeId(v); setEtudiantId(''); }}
                disabled={!niveauId}
                placeholder="Tous les groupes"
                allLabel="Tous les groupes"
              />
              <FilterSelect
                label="Étudiant"
                value={etudiantId}
                options={etudiants.map(e => ({ id: e.id, label: e.nom_prenom }))}
                onChange={setEtudiantId}
                disabled={!groupeId || loadingEtudiants}
                placeholder={loadingEtudiants ? 'Chargement...' : 'Tous les étudiants'}
                allLabel={loadingEtudiants ? 'Chargement...' : 'Tous les étudiants'}
              />
            </FilterBar>

            <div style={{position:'relative', marginTop:12}}>
              <div style={{display:'flex', alignItems:'center', gap:8, marginBottom:4}}>
                <span style={{fontSize:12, color:'#999'}}>Ou rechercher par nom / CIN / N° inscription :</span>
              </div>
              <div className="search-input-wrapper" style={{position:'relative', maxWidth:400}}>
                <FiSearch style={{position:'absolute', left:10, top:'50%', transform:'translateY(-50%)', color:'#999'}} />
                <input type="text" className="form-input" placeholder="Rechercher..."
                  value={search} onChange={e => setSearch(e.target.value)}
                  style={{paddingLeft:32, width:'100%'}} />
                {search && <FiX style={{position:'absolute', right:10, top:'50%', transform:'translateY(-50%)', cursor:'pointer', color:'#999'}} onClick={() => { setSearch(''); setSearchResults([]); }} />}
              </div>
            </div>

            {searchResults.length > 0 && (
              <div style={{marginTop:8, border:'1px solid #e5e7eb', borderRadius:6, maxHeight:200, overflowY:'auto'}}>
                {searchResults.map(s => (
                  <div key={s.id}
                    style={{padding:'6px 10px', cursor:'pointer', display:'flex', justifyContent:'space-between', alignItems:'center', borderBottom:'1px solid #f3f4f6'}}
                    onMouseEnter={e => e.currentTarget.style.background='var(--gray-100)'}
                    onMouseLeave={e => e.currentTarget.style.background='transparent'}
                    onClick={() => selectSearchResult(s)}
                  >
                    <div><strong>{s.nom_prenom}</strong><span style={{color:'#666', marginLeft:8, fontSize:13}}>{s.filiere}</span></div>
                    <div style={{fontSize:12, color:'#999'}}>{s.numero_inscription && <span>#{s.numero_inscription} </span>}{s.cin && <span>({s.cin})</span>}</div>
                  </div>
                ))}
              </div>
            )}
            {searching && <Spinner />}
          </div>
        </div>
      )}

      <div className="tab-bar">
        {TABS.map(t => (
          <button key={t.key} className={`tab-btn ${activeTab === t.key ? 'active' : ''}`} onClick={() => setActiveTab(t.key)}>{t.label}</button>
        ))}
      </div>

      {renderForm()}

      <Modal open={downloadOpen} onClose={() => setDownloadOpen(false)} title="Télécharger la grille">
        <div style={{ display: 'grid', gap: 16 }}>
          <div>
            <label className="form-label">Document</label>
            <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap' }}>
              {[
                { key: 'grille_evaluation', label: "Grille d'évaluation" },
                { key: 'grille_examen', label: "Grille d'examen final" },
              ].map(o => (
                <button key={o.key} type="button"
                  className={`btn ${dl.type === o.key ? 'btn-primary' : 'btn-outline'}`}
                  onClick={() => setDl(d => ({ ...d, type: o.key }))}>
                  {o.label}
                </button>
              ))}
            </div>
          </div>

          {selectedStudent && groupeId && (
            <div>
              <label className="form-label">Concerne</label>
              <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap' }}>
                <button type="button"
                  className={`btn ${dl.scope === 'etudiant' ? 'btn-primary' : 'btn-outline'}`}
                  onClick={() => setDl(d => ({ ...d, scope: 'etudiant' }))}>
                  Cet étudiant
                </button>
                <button type="button"
                  className={`btn ${dl.scope === 'groupe' ? 'btn-primary' : 'btn-outline'}`}
                  onClick={() => setDl(d => ({ ...d, scope: 'groupe' }))}>
                  Tout le groupe ({groupes.find(g => String(g.id) === String(groupeId))?.nom ?? ''})
                </button>
              </div>
            </div>
          )}

          <div>
            <label className="form-label">Contenu</label>
            <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap' }}>
              <button type="button"
                className={`btn ${dl.mode === 'filled' ? 'btn-primary' : 'btn-outline'}`}
                onClick={() => setDl(d => ({ ...d, mode: 'filled' }))}>
                Rempli (notes enregistrées)
              </button>
              <button type="button"
                className={`btn ${dl.mode === 'blank' ? 'btn-primary' : 'btn-outline'}`}
                onClick={() => setDl(d => ({ ...d, mode: 'blank' }))}>
                Vide (à remplir à la main)
              </button>
            </div>
          </div>

          {dl.mode === 'filled' && selectedStudent && savedServices.length > 0 && (
            <div>
              <label className="form-label">Service</label>
              <select className="form-input" value={dl.service} onChange={e => setDl(d => ({ ...d, service: e.target.value }))}>
                <option value="">Tous les services avec grille</option>
                {savedServices.map(s => (
                  <option key={s} value={s}>{s}</option>
                ))}
              </select>
              <small style={{ color: 'var(--gray-600)' }}>{dl.type === 'grille_evaluation' ? 'Services pour lesquels une grille d\'évaluation a été enregistrée.' : "Services pour lesquels une grille d'examen a été enregistrée."}</small>
            </div>
          )}

          {dl.type === 'grille_evaluation' && (
            <div>
              <label className="form-label">Période du stage</label>
              <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap' }}>
                <div style={{ flex: '1 1 150px', minWidth: 140 }}>
                  <label className="form-label" style={{ fontSize: 12 }}>Date de début</label>
                  <input
                    type="date"
                    className="form-input"
                    value={dl.dureeDebut || ''}
                    onChange={e => setDl(d => ({ ...d, dureeDebut: e.target.value }))}
                  />
                </div>
                <div style={{ flex: '1 1 150px', minWidth: 140 }}>
                  <label className="form-label" style={{ fontSize: 12 }}>Date de fin</label>
                  <input
                    type="date"
                    className="form-input"
                    value={dl.dureeFin || ''}
                    onChange={e => setDl(d => ({ ...d, dureeFin: e.target.value }))}
                  />
                </div>
              </div>
              <small style={{ color: 'var(--gray-600)' }}>Imprimé sous forme : du JJ/MM/AAAA au JJ/MM/AAAA</small>
            </div>
          )}

          <div>
            <label className="form-label">Pratique exécutée {dl.mode === 'filled' ? '(facultatif)' : ''}</label>
            <input className="form-input" value={dl.pratique_executee}
              placeholder="ex : Pose de perfusion (laisse vide pour utiliser celle enregistrée)"
              onChange={e => setDl(d => ({ ...d, pratique_executee: e.target.value }))} />
          </div>

          <div style={{ display: 'flex', justifyContent: 'flex-end', gap: 8 }}>
            <button className="btn btn-outline" onClick={() => setDownloadOpen(false)}>Annuler</button>
            <button className="btn btn-primary" onClick={runDownload} disabled={downloading}>
              <FiDownload style={{marginRight:6}} />{downloading ? 'Génération...' : 'Télécharger'}
            </button>
          </div>
        </div>
      </Modal>
    </div>
  );
}
