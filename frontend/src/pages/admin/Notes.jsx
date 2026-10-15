import { useState, useCallback, useEffect } from 'react';
import { notesApi } from '../../api/notes';
import { useAnneeAcademique } from '../../context/AnneeAcademiqueContext';
import FiliereCascade from '../../components/common/FiliereCascade';
import FilterBar from '../../components/common/FilterBar';
import FilterSelect from '../../components/common/FilterSelect';
import RecapTable, {
  TABLE_TYPES,
  tableTypeOptions,
  DOCX_MIME,
  saveBlob,
  filenameFromDisposition,
} from '../../components/admin/RecapTable';
import Spinner from '../../components/common/Spinner';
import EmptyState from '../../components/common/EmptyState';
import { useToast } from '../../context/ToastContext';
import usePersistedState from '../../hooks/usePersistedState';
import '../../css/components.css';
import '../../css/layout.css';

const ALL_TYPES = TABLE_TYPES.map(t => t.value);

export default function Notes() {
  const toast = useToast();
  const [saved, setSaved] = usePersistedState('notes', {
    selected: {
      filiere_id: '',
      niveau_id: '',
      groupe_id: '',
      annee_academique_id: '',
    },
    tableType: 'mpcc1',
  });
  const [selected, setSelected] = useState(() => saved.selected);
  const [tableType, setTableType] = useState(() => saved.tableType);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState(null);
  const [recapData, setRecapData] = useState(null);
  const [downloading, setDownloading] = useState(false);
  const [downloadingAll, setDownloadingAll] = useState(false);
  const [availableTables, setAvailableTables] = useState(ALL_TYPES);
  // Group whose available-table list is loaded; until it matches the selection
  // the relevé waits, so a stale NSTI / Stage choice never reaches the API.
  const [typesFor, setTypesFor] = useState(null);

  const { currentAnnee } = useAnneeAcademique();

  useEffect(() => {
    setSaved({ selected, tableType });
  }, [selected, tableType, setSaved]);

  // NSTI only exists for a filière/year that ends with a soutenance and Stage
  // only for one that has a Stage unit, so ask the backend which tables this
  // group has instead of assuming all of them.
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

  // A restored selection the group does not have falls back to its first table.
  useEffect(() => {
    if (typesFor !== selected.groupe_id) return;
    if (tableType && !availableTables.includes(tableType)) {
      setTableType(availableTables[0] || 'mpcc1');
    }
  }, [typesFor, selected.groupe_id, availableTables, tableType]);

  const fetchRecap = useCallback(() => {
    if (!selected.groupe_id || !tableType) return;
    setLoading(true);
    setError(null);
    notesApi.getRecapNotes({ groupe_id: selected.groupe_id, type: tableType })
      .then(res => setRecapData(res.data.data))
      .catch(err => setError(err.response?.data?.message || 'Erreur lors du chargement'))
      .finally(() => setLoading(false));
  }, [selected.groupe_id, tableType]);

  // Show the table as soon as a group and a table type are chosen: the select
  // list is the only action, there is no "Afficher" button anymore.
  const [loadedFor, setLoadedFor] = useState(null);
  useEffect(() => {
    const key = `${selected.groupe_id}|${tableType}`;
    if (!selected.groupe_id || !tableType || loadedFor === key) return;
    // Wait for the group's table list, so NSTI / Stage are never requested on a
    // filière/year that does not have them.
    if (typesFor !== selected.groupe_id) return;
    if (!availableTables.includes(tableType)) return;
    setLoadedFor(key);
    fetchRecap();
  }, [selected.groupe_id, tableType, typesFor, availableTables, fetchRecap, loadedFor]);

  // The single table the selector is pointing at.
  const downloadOne = async () => {
    if (!selected.groupe_id || !tableType) return;
    setDownloading(true);
    try {
      const res = await notesApi.downloadRecapDocx({ groupe_id: selected.groupe_id, type: tableType });
      const blob = new Blob([res.data], { type: DOCX_MIME });
      saveBlob(blob, filenameFromDisposition(res.headers?.['content-disposition'], 'Releve_Notes.docx'));
    } catch (err) {
      toast.error(err?.response?.data?.message || 'Erreur lors de la génération du relevé');
    } finally {
      setDownloading(false);
    }
  };

  // Every table of the filière, one sheet per page, in a single .docx.
  const downloadAll = async () => {
    if (!selected.groupe_id) return;
    setDownloadingAll(true);
    try {
      const res = await notesApi.downloadRecapDocxAll({ groupe_id: selected.groupe_id });
      const blob = new Blob([res.data], { type: DOCX_MIME });
      saveBlob(blob, filenameFromDisposition(res.headers?.['content-disposition'], 'Releve_Notes_Tous_les_tableaux.docx'));
    } catch (err) {
      toast.error(err?.response?.data?.message || 'Erreur lors de la génération des relevés');
    } finally {
      setDownloadingAll(false);
    }
  };

  return (
    <div className="page">
      <FilterBar
        showSearch={false}
        center
        value={{ filiere_id: selected.filiere_id, niveau_id: selected.niveau_id, groupe_id: selected.groupe_id, table_type: tableType }}
        onChange={() => {}}
        onClear={() => {
          setSelected({ filiere_id: '', niveau_id: '', groupe_id: '', annee_academique_id: selected.annee_academique_id });
          setTableType('mpcc1');
          setRecapData(null);
          setLoadedFor(null);
        }}
      >
        <FiliereCascade selected={selected} onChange={setSelected} />
        <FilterSelect
          label="Tableau"
          value={tableType}
          options={tableTypeOptions(availableTables)}
          onChange={setTableType}
          disabled={!selected.groupe_id}
          placeholder="Type de tableau"
          allLabel="Type de tableau"
        />
      </FilterBar>

      <div
        className="recap-actions"
        style={{ display: 'flex', justifyContent: 'flex-end', alignItems: 'center', gap: 8, marginBottom: 12, flexWrap: 'wrap' }}
      >
        <button
          className="btn btn-outline"
          onClick={downloadAll}
          disabled={downloadingAll || !selected.groupe_id}
          title="Télécharger tous les tableaux de cette filière dans un seul fichier"
        >
          {downloadingAll ? 'Génération…' : 'Télécharger tous les tableaux (.docx)'}
        </button>
        <button
          className="btn btn-primary"
          onClick={downloadOne}
          disabled={downloading || !selected.groupe_id || !tableType}
          title="Télécharger uniquement le tableau sélectionné"
        >
          {downloading ? 'Génération…' : 'Télécharger ce tableau (.docx)'}
        </button>
      </div>

      {loading && <Spinner />}
      {error && <div className="alert alert-error">{error}</div>}
      {!loading && !error && !recapData && (
        <EmptyState message="Sélectionnez une filière, un niveau, un groupe et un type de tableau pour afficher le relevé." />
      )}
      {!loading && !error && recapData && (
        <RecapTable
          recapData={recapData}
          tableType={tableType}
          anneeLabel={currentAnnee?.label}
          groupeId={selected.groupe_id}
          showDownloadBar={false}
        />
      )}
    </div>
  );
}
