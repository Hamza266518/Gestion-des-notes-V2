import { useState, useEffect, useCallback } from 'react';
import { FiSearch, FiX } from 'react-icons/fi';
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

  const [filiereId, setFiliereId] = useState('');
  const [niveauId, setNiveauId] = useState('');
  const [groupeId, setGroupeId] = useState('');
  const [etudiantId, setEtudiantId] = useState('');

  const [selectedStudent, setSelectedStudent] = useState(null);
  const [activeTab, setActiveTab] = useState('garde_nuit');
  const [loadingEtudiants, setLoadingEtudiants] = useState(false);

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
    if (filiereId) {
      adminApi.getNiveaux(filiereId)
        .then(res => { setNiveaux(res.data.data || []); setNiveauId(''); setGroupeId(''); })
        .catch(() => setNiveaux([]));
    } else { setNiveaux([]); setNiveauId(''); setGroupeId(''); }
  }, [filiereId]);

  useEffect(() => {
    if (!niveauId || !currentAnnee) { setGroupes([]); setGroupeId(''); return; }
    adminApi.getGroupes({ niveau_id: niveauId, annee_academique_id: currentAnnee.id })
      .then(res => { setGroupes(res.data.data || []); setGroupeId(''); })
      .catch(() => setGroupes([]));
  }, [niveauId, currentAnnee]);

  useEffect(() => {
    if (!groupeId) { setEtudiants([]); setEtudiantId(''); return; }
    setLoadingEtudiants(true);
    etudiantsApi.getEtudiants({ groupe_id: groupeId })
      .then(res => { setEtudiants(res.data.data || []); setEtudiantId(''); })
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
      case 'garde_nuit': return <GardeDeNuit etudiant={displayStudent} />;
      case 'grille_evaluation': return <GrilleEvaluationStage etudiant={displayStudent} />;
      case 'grille_examen': return <GrilleExamenStage etudiant={displayStudent} />;
      default: return null;
    }
  };

  return (
    <div className="page">
      <div className="page-header">
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
      </div>

      {!selectedStudent && (
        <div className="card" style={{marginBottom:16}}>
          <div className="card-body">
            <div className="data-grid">
              <select className="form-select" value={filiereId} onChange={e => setFiliereId(e.target.value)}>
                <option value="">Filière</option>
                {filieres.map(f => <option key={f.id} value={f.id}>{f.nom}</option>)}
              </select>
              <select className="form-select" value={niveauId} onChange={e => setNiveauId(e.target.value)} disabled={!filiereId}>
                <option value="">Niveau</option>
                {niveaux.map(n => <option key={n.id} value={n.id}>{formatNiveau(n.numero)}</option>)}
              </select>
              <select className="form-select" value={groupeId} onChange={e => setGroupeId(e.target.value)} disabled={!niveauId}>
                <option value="">Groupe</option>
                {groupes.map(g => <option key={g.id} value={g.id}>{g.nom}</option>)}
              </select>
              <select className="form-select" value={etudiantId} onChange={e => setEtudiantId(e.target.value)} disabled={!groupeId || loadingEtudiants}>
                <option value="">{loadingEtudiants ? 'Chargement...' : 'Étudiant'}</option>
                {etudiants.map(e => <option key={e.id} value={e.id}>{e.nom_prenom}</option>)}
              </select>
            </div>

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
    </div>
  );
}
