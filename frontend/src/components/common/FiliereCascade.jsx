import { useEffect, useState } from 'react';
import { adminApi } from '../../api/admin';
import { useAnneeAcademique } from '../../context/AnneeAcademiqueContext';
import { formatNiveau } from '../../utils/helpers';
import FilterSelect from './FilterSelect';

// Renders the filière → niveau → groupe cascade as smart FilterSelect pills.
// Parent decides the layout (FilterBar); this component only owns the data + cascade resets.
export default function FiliereCascade({ selected, onChange }) {
  const [filieres, setFilieres] = useState([]);
  const [niveaux, setNiveaux] = useState([]);
  const [groupes, setGroupes] = useState([]);
  const { currentAnnee } = useAnneeAcademique();

  useEffect(() => {
    adminApi.getFilieres()
      .then(res => setFilieres(res.data.data || []))
      .catch(() => setFilieres([]));
  }, []);

  useEffect(() => {
    if (currentAnnee && !selected.annee_academique_id) {
      onChange({ ...selected, annee_academique_id: currentAnnee.id, niveau_id: '', groupe_id: '' });
    }
  }, [currentAnnee]);

  useEffect(() => {
    if (selected.filiere_id) {
      adminApi.getNiveaux(selected.filiere_id)
        .then(res => {
          const niveauxData = res.data.data || [];
          setNiveaux(niveauxData);
          if (selected.niveau_id && !niveauxData.find(n => n.id == selected.niveau_id)) {
            onChange({ ...selected, niveau_id: '', groupe_id: '' });
          }
        })
        .catch(() => {
          setNiveaux([]);
          onChange({ ...selected, niveau_id: '', groupe_id: '' });
        });
    } else {
      setNiveaux([]);
      if (selected.niveau_id || selected.groupe_id) {
        onChange({ ...selected, niveau_id: '', groupe_id: '' });
      }
    }
  }, [selected.filiere_id]);

  useEffect(() => {
    if (selected.niveau_id && selected.annee_academique_id) {
      adminApi.getGroupes({ niveau_id: selected.niveau_id, annee_academique_id: selected.annee_academique_id })
        .then(res => {
          const groupesData = res.data.data || [];
          setGroupes(groupesData);
          if (selected.groupe_id && !groupesData.find(g => g.id == selected.groupe_id)) {
            onChange({ ...selected, groupe_id: '' });
          }
        })
        .catch(() => {
          setGroupes([]);
          onChange({ ...selected, groupe_id: '' });
        });
    } else {
      setGroupes([]);
      if (selected.groupe_id) {
        onChange({ ...selected, groupe_id: '' });
      }
    }
  }, [selected.niveau_id, selected.annee_academique_id]);

  const setFiliere = (filiere_id) => {
    onChange({ filiere_id, niveau_id: '', groupe_id: '', annee_academique_id: selected.annee_academique_id });
  };

  const setNiveau = (niveau_id) => {
    onChange({ ...selected, niveau_id, groupe_id: '' });
  };

  const setGroupe = (groupe_id) => {
    onChange({ ...selected, groupe_id });
  };

  return (
    <>
      <FilterSelect
        label="Filière"
        value={selected.filiere_id || ''}
        options={filieres.map(f => ({ id: f.id, label: f.nom }))}
        onChange={setFiliere}
        placeholder="Toutes les filières"
        allLabel="Toutes les filières"
      />
      <FilterSelect
        label="Niveau"
        value={selected.niveau_id || ''}
        options={niveaux.map(n => ({ id: n.id, label: formatNiveau(n.numero) }))}
        onChange={setNiveau}
        disabled={!selected.filiere_id}
        placeholder="Tous les niveaux"
        allLabel="Tous les niveaux"
      />
      <FilterSelect
        label="Groupe"
        value={selected.groupe_id || ''}
        options={groupes.map(g => ({ id: g.id, label: g.nom }))}
        onChange={setGroupe}
        disabled={!selected.niveau_id}
        placeholder="Tous les groupes"
        allLabel="Tous les groupes"
      />
    </>
  );
}
