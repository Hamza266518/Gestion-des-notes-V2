import { useEffect, useState, useCallback } from 'react';
import { adminApi } from '../../api/admin';
import { publicationsApi } from '../../api/publications';
import { useToast } from '../../context/ToastContext';
import Spinner from '../../components/common/Spinner';
import Badge from '../../components/common/Badge';
import '../../css/components.css';
import '../../css/layout.css';

export default function Publications() {
  const [groupes, setGroupes]       = useState([]);
  const [annees, setAnnees]         = useState([]);
  const [publications, setPublications] = useState([]);
  const [loading, setLoading]       = useState(true);
  const [selAnnee, setSelAnnee]     = useState('');
  const [saving, setSaving]         = useState('');
  const toast = useToast();

  const load = useCallback(() => {
    adminApi.getGroupes({})
      .then(res => setGroupes(res.data.data));
  }, []);

  useEffect(() => {
    setLoading(true);
    load()
      .finally(() => setLoading(false));
  }, [load]);

  useEffect(() => {
    adminApi.getAnnees().then(r => {
      setAnnees(r.data.data);
      const current = r.data.data.find(a => a.is_current);
      if (current) setSelAnnee(current.id);
    });
  }, []);

  useEffect(() => {
    if (selAnnee) {
      publicationsApi.getPublications({ annee_academique_id: selAnnee })
        .then(r => setPublications(r.data.data));
    }
  }, [selAnnee]);

  const isPublished = (groupeId, semestre) => {
    return publications.find(p =>
      p.groupe_id === groupeId &&
      p.semestre === semestre &&
      p.is_published
    );
  };

  const getPublicationId = (groupeId, semestre) => {
    return publications.find(p =>
      p.groupe_id === groupeId &&
      p.semestre === semestre
    )?.id;
  };

  const handlePublish = async (groupeId, semestre) => {
    const key = `${groupeId}_${semestre}`;
    setSaving(key);
    try {
      await publicationsApi.publish({
        groupe_id: groupeId,
        annee_academique_id: selAnnee,
        semestre,
      });
      toast.success(`Semestre ${semestre} publié`);
      publicationsApi.getPublications({ annee_academique_id: selAnnee })
        .then(r => setPublications(r.data.data));
    } catch {
      toast.error('Erreur de publication');
    } finally {
      setSaving('');
    }
  };

  const handleUnpublish = async (groupeId, semestre) => {
    const id = getPublicationId(groupeId, semestre);
    if (!id) return;
    setSaving(`${groupeId}_${semestre}`);
    try {
      await publicationsApi.unpublish(id);
      toast.success('Publication annulée');
      publicationsApi.getPublications({ annee_academique_id: selAnnee })
        .then(r => setPublications(r.data.data));
    } catch {
      toast.error('Erreur');
    } finally {
      setSaving('');
    }
  };

  if (loading) return <Spinner />;

  return (
    <div className="page">
      <div className="page-header">
        <h2 className="page-title">Publications des Bulletins</h2>
        <select
          className="form-select"
          value={selAnnee}
          onChange={e => setSelAnnee(e.target.value)}
          style={{ width: 'auto' }}
        >
          {annees.map(a => <option key={a.id} value={a.id}>{a.label}</option>)}
        </select>
      </div>

      <div className="table-wrap">
        <table>
          <thead>
            <tr>
              <th>Groupe</th>
              <th>Filière</th>
              <th>Niveau</th>
              <th>Semestre 1</th>
              <th>Semestre 2</th>
            </tr>
          </thead>
          <tbody>
            {groupes.length === 0 ? (
              <tr><td colSpan={5} className="table-empty">Aucun groupe</td></tr>
            ) : groupes.map(g => (
              <tr key={g.id}>
                <td><strong>{g.nom}</strong></td>
                <td>{g.niveau?.filiere?.nom ?? '—'}</td>
                <td>{g.niveau?.numero}ère année</td>
                {[1, 2].map(s => {
                  const pub = isPublished(g.id, s);
                  const key = `${g.id}_${s}`;
                  return (
                    <td key={s}>
                      <div style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
                        <Badge
                          label={pub ? 'Publié' : 'Non publié'}
                          color={pub ? 'green' : 'red'}
                        />
                        {pub ? (
                          <button
                            className="btn btn-sm btn-danger"
                            disabled={saving === key}
                            onClick={() => handleUnpublish(g.id, s)}
                          >
                            Dépublier
                          </button>
                        ) : (
                          <button
                            className="btn btn-sm btn-accent"
                            disabled={saving === key}
                            onClick={() => handlePublish(g.id, s)}
                          >
                            Publier S{s}
                          </button>
                        )}
                      </div>
                    </td>
                  );
                })}
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </div>
  );
}