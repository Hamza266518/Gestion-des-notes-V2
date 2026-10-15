import { useCallback, useEffect, useMemo, useState } from 'react';
import { FiSearch, FiX } from 'react-icons/fi';
import Modal from '../common/Modal';
import Spinner from '../common/Spinner';
import EmptyState from '../common/EmptyState';
import FiliereCascade from '../common/FiliereCascade';
import FilterBar from '../common/FilterBar';
import FilterSelect from '../common/FilterSelect';
import RecapTable, { TABLE_TYPES, tableTypeOptions } from './RecapTable';
import BulletinSheet from './BulletinSheet';
import { notesApi } from '../../api/notes';
import { adminApi } from '../../api/admin';
import { etudiantsApi } from '../../api/etudiants';
import { useToast } from '../../context/ToastContext';
import { handleApiError } from '../../utils/errorHandler';
import '../../css/components.css';
import '../../css/pages.css';

const ALL_TYPES = TABLE_TYPES.map(t => t.value);

/**
 * Read-only viewer for one academic year.
 *
 * Everything it shows already existed: `recap-notes` returns the students x
 * units grid together with the class average, and `bulletins` returns the unit
 * breakdown with its sequences and controls. No endpoint, table or calculation
 * was added for this -- it is the two existing read paths behind one filter.
 *
 * The cohort comes from the group, not from each student's current enrolment:
 * opening the 2024/2025 group shows whoever is still in it. A student promoted
 * afterwards now sits in their new year's group, so they appear there instead.
 */
export default function ArchiveAnneeModal({ open, annee, onClose }) {
  const toast = useToast();

  const [tab, setTab] = useState('recap');
  const [selected, setSelected] = useState({
    filiere_id: '',
    niveau_id: '',
    groupe_id: '',
    annee_academique_id: '',
  });
  const [tableType, setTableType] = useState('mpcc1');
  const [recap, setRecap] = useState(null);
  const [recapLoading, setRecapLoading] = useState(false);
  const [availableTables, setAvailableTables] = useState(ALL_TYPES);
  // Group whose available-table list is loaded; the relevé waits until it
  // matches the selection, so NSTI / Stage are never requested for a group
  // whose filière/year does not have them.
  const [typesFor, setTypesFor] = useState(null);

  const [filieres, setFilieres] = useState([]);
  const [groupes, setGroupes] = useState([]);

  const [etudiants, setEtudiants] = useState([]);
  const [etudiantId, setEtudiantId] = useState('');
  const [bulletin, setBulletin] = useState(null);
  const [bulletinLoading, setBulletinLoading] = useState(false);
  const [search, setSearch] = useState('');

  useEffect(() => {
    adminApi.getFilieres()
      .then(res => setFilieres(res.data.data || []))
      .catch(() => setFilieres([]));
  }, []);

  // Pin the cascade to the year being viewed. FiliereCascade only falls back to
  // the current year when annee_academique_id is empty, so handing it the id is
  // enough to keep the group list inside the archived year.
  useEffect(() => {
    if (!open || !annee) return;
    setTab('recap');
    setSelected({ filiere_id: '', niveau_id: '', groupe_id: '', annee_academique_id: annee.id });
    setTableType('mpcc1');
    setAvailableTables(ALL_TYPES);
    setTypesFor(null);
    setRecap(null);
    setEtudiants([]);
    setEtudiantId('');
    setBulletin(null);
    setSearch('');
  }, [open, annee]);

  // The bulletin sheet needs the filière and section names, and it can be opened
  // without ever loading the relevé, so resolve them from the same lists the
  // cascade itself reads.
  useEffect(() => {
    if (!selected.niveau_id || !annee) {
      setGroupes([]);
      return;
    }
    adminApi.getGroupes({ niveau_id: selected.niveau_id, annee_academique_id: annee.id })
      .then(res => setGroupes(res.data.data || []))
      .catch(() => setGroupes([]));
  }, [selected.niveau_id, annee]);

  const fetchRecap = useCallback(() => {
    if (!selected.groupe_id || !tableType) return;
    setRecapLoading(true);
    notesApi.getRecapNotes({ groupe_id: selected.groupe_id, type: tableType })
      .then(res => setRecap(res.data.data))
      .catch(err => handleApiError(err, toast))
      .finally(() => setRecapLoading(false));
  }, [selected.groupe_id, tableType, toast]);

  // NSTI only exists for a filière/year that ends with a soutenance and Stage
  // only for one that has a Stage unit: ask the backend which tables this group
  // has rather than assuming all of them.
  useEffect(() => {
    if (!selected.groupe_id) {
      setAvailableTables(ALL_TYPES);
      setTypesFor(null);
      return;
    }
    let cancelled = false;
    notesApi.getRecapTableTypes({ groupe_id: selected.groupe_id })
      .then(res => {
        if (cancelled) return;
        const list = Array.isArray(res.data?.data) && res.data.data.length ? res.data.data : ALL_TYPES;
        setAvailableTables(list);
        setTypesFor(selected.groupe_id);
      })
      .catch(() => {
        if (cancelled) return;
        setAvailableTables(ALL_TYPES);
        setTypesFor(selected.groupe_id);
      });
    return () => { cancelled = true; };
  }, [selected.groupe_id]);

  useEffect(() => {
    if (typesFor !== selected.groupe_id) return;
    if (tableType && !availableTables.includes(tableType)) {
      setTableType(availableTables[0] || 'mpcc1');
    }
  }, [typesFor, selected.groupe_id, availableTables, tableType]);

  useEffect(() => {
    if (tab !== 'recap') return;
    if (!selected.groupe_id || !tableType) return;
    // Wait for the group's table list before firing, so a stale NSTI / Stage
    // selection never reaches the API.
    if (typesFor !== selected.groupe_id) return;
    if (!availableTables.includes(tableType)) return;
    fetchRecap();
  }, [tab, fetchRecap, typesFor, selected.groupe_id, availableTables, tableType]);

  useEffect(() => {
    if (tab !== 'bulletin') return;
    if (!selected.groupe_id) {
      setEtudiants([]);
      return;
    }
    etudiantsApi.getEtudiants({ groupe_id: selected.groupe_id })
      .then(res => setEtudiants(res.data.data || []))
      .catch(() => setEtudiants([]));
  }, [tab, selected.groupe_id]);

  const loadBulletin = useCallback(() => {
    if (!etudiantId || !selected.groupe_id || !annee) return;
    setBulletinLoading(true);
    notesApi.getBulletin({
      etudiant_id: etudiantId,
      groupe_id: selected.groupe_id,
      annee_academique_id: annee.id,
    })
      .then(res => setBulletin(res.data.data))
      .catch(err => handleApiError(err, toast))
      .finally(() => setBulletinLoading(false));
  }, [etudiantId, selected.groupe_id, annee, toast]);

  useEffect(() => {
    setBulletin(null);
    loadBulletin();
  }, [loadBulletin]);

  const etudiantsTries = useMemo(() => {
    const q = search.trim().toLowerCase();
    const list = [...etudiants].sort((a, b) =>
      String(a.nom_prenom || '').localeCompare(String(b.nom_prenom || ''), 'fr')
    );
    if (!q) return list;
    return list.filter(e =>
      String(e.nom_prenom || '').toLowerCase().includes(q) ||
      String(e.numero_inscription || '').toLowerCase().includes(q) ||
      String(e.cin || '').toLowerCase().includes(q)
    );
  }, [etudiants, search]);

  const filiereNom = filieres.find(f => f.id == selected.filiere_id)?.nom;
  const groupeNom = groupes.find(g => g.id == selected.groupe_id)?.nom;

  return (
    <Modal
      open={open && !!annee}
      onClose={onClose}
      title={`Consultation de l'année ${annee?.label || ''}`}
      style={{ maxWidth: 1200, width: '95vw', maxHeight: '85vh', overflowY: 'auto' }}
    >
      {annee && (
        <>
          <div className="tab-bar">
            <button
              className={`tab-btn ${tab === 'recap' ? 'active' : ''}`}
              onClick={() => setTab('recap')}
            >
              Relevé du groupe
            </button>
            <button
              className={`tab-btn ${tab === 'bulletin' ? 'active' : ''}`}
              onClick={() => setTab('bulletin')}
            >
              Bulletin d'un étudiant
            </button>
          </div>

          <FilterBar
            showSearch={false}
            value={{ filiere_id: selected.filiere_id, niveau_id: selected.niveau_id, groupe_id: selected.groupe_id, table_type: tableType }}
            onChange={() => {}}
            onClear={() => {
              setSelected({ filiere_id: '', niveau_id: '', groupe_id: '', annee_academique_id: selected.annee_academique_id });
              setTableType('mpcc1');
            }}
          >
            <FiliereCascade selected={selected} onChange={setSelected} />
            {tab === 'recap' && (
              <FilterSelect
                label="Tableau"
                value={tableType}
                options={tableTypeOptions(availableTables)}
                onChange={setTableType}
                disabled={!selected.groupe_id}
                placeholder="Type de tableau"
                allLabel="Type de tableau"
              />
            )}
          </FilterBar>

          {tab === 'recap' && (
            <>
              {recapLoading && <Spinner />}
              {!recapLoading && !recap && (
                <EmptyState message="Sélectionnez une filière, un niveau et un groupe pour afficher le relevé de l'année." />
              )}
              {!recapLoading && recap && (
                <RecapTable recapData={recap} tableType={tableType} anneeLabel={annee.label} groupeId={selected.groupe_id} />
              )}
            </>
          )}

          {tab === 'bulletin' && (
            <div style={{ display: 'flex', gap: 16, alignItems: 'flex-start', flexWrap: 'wrap' }}>
              <div style={{ flex: '1 1 260px', minWidth: 240 }}>
                <div style={{ position: 'relative', marginBottom: 8 }}>
                  <FiSearch size={16} style={{ position: 'absolute', left: 10, top: 11, color: '#9ca3af' }} />
                  <input
                    className="form-input"
                    type="text"
                    value={search}
                    onChange={e => setSearch(e.target.value)}
                    placeholder="Rechercher un étudiant…"
                    style={{ paddingLeft: 32, paddingRight: 30 }}
                  />
                  {search && (
                    <button
                      type="button"
                      onClick={() => setSearch('')}
                      style={{
                        position: 'absolute', right: 8, top: 9,
                        background: 'none', border: 'none', cursor: 'pointer',
                        color: '#9ca3af', padding: 0, display: 'flex',
                      }}
                      aria-label="Effacer la recherche"
                    >
                      <FiX size={16} />
                    </button>
                  )}
                </div>

                {!selected.groupe_id ? (
                  <EmptyState message="Sélectionnez un groupe." />
                ) : (
                  <div style={{ maxHeight: 320, overflowY: 'auto', border: '1px solid #e5e7eb', borderRadius: 6 }}>
                    {etudiantsTries.length === 0 ? (
                      <div style={{ padding: 16, fontSize: 12, color: '#6b7280' }}>
                        {search ? 'Aucun résultat.' : 'Aucun étudiant dans ce groupe.'}
                      </div>
                    ) : (
                      etudiantsTries.map(e => (
                        <button
                          key={e.id}
                          type="button"
                          onClick={() => setEtudiantId(e.id)}
                          style={{
                            display: 'block', width: '100%', textAlign: 'left',
                            padding: '8px 12px', fontSize: 12, cursor: 'pointer',
                            border: 'none', borderBottom: '1px solid #f3f4f6',
                            background: etudiantId == e.id ? '#eff6ff' : 'transparent',
                            fontWeight: etudiantId == e.id ? 600 : 400,
                          }}
                        >
                          {e.nom_prenom || e.nom}
                          {e.numero_inscription ? (
                            <span style={{ color: '#9ca3af', fontWeight: 400 }}> · {e.numero_inscription}</span>
                          ) : null}
                        </button>
                      ))
                    )}
                  </div>
                )}
              </div>

              <div style={{ flex: '3 1 520px', minWidth: 320 }}>
                {bulletinLoading && <Spinner />}
                {!bulletinLoading && !bulletin && (
                  <EmptyState message="Sélectionnez un étudiant pour afficher son bulletin." />
                )}
                {!bulletinLoading && bulletin && (
                  <BulletinSheet
                    bulletin={bulletin}
                    filiereNom={filiereNom}
                    groupeNom={groupeNom}
                    anneeLabel={annee.label}
                  />
                )}
              </div>
            </div>
          )}

          <div className="modal-footer">
            <button className="btn btn-outline" onClick={onClose}>
              Fermer
            </button>
          </div>
        </>
      )}
    </Modal>
  );
}
