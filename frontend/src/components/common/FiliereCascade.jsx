import { useEffect, useState } from 'react';
import { adminApi } from '../../api/admin';
import { useAnneeAcademique } from '../../context/AnneeAcademiqueContext';
import { formatNiveau } from '../../utils/helpers';

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
    <div className="filter-bar">
      <select className="form-select" value={selected.filiere_id || ''} onChange={e => setFiliere(e.target.value)}>
        <option value="">Filiere</option>
        {filieres.map(f => <option key={f.id} value={f.id}>{f.nom}</option>)}
      </select>
      <select className="form-select" value={selected.niveau_id || ''} onChange={e => setNiveau(e.target.value)} disabled={!selected.filiere_id}>
        <option value="">Niveau</option>
        {niveaux.map(n => <option key={n.id} value={n.id}>{formatNiveau(n.numero)}</option>)}
      </select>
      <select className="form-select" value={selected.groupe_id || ''} onChange={e => setGroupe(e.target.value)} disabled={!selected.niveau_id}>
        <option value="">Groupe</option>
        {groupes.map(g => <option key={g.id} value={g.id}>{g.nom}</option>)}
      </select>
    </div>
  );
}
