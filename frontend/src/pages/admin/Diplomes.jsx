import { useEffect, useState, useCallback } from 'react';
import { adminApi } from '../../api/admin';
import { diplomesApi } from '../../api/diplomes';
import { useToast } from '../../context/ToastContext';
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

export default function Diplomes() {
  const [diplomes, setDiplomes] = useState([]);
  const [annees, setAnnees]     = useState([]);
  const [selAnnee, setSelAnnee] = useState('');
  const [loading, setLoading]   = useState(true);
  const toast = useToast();

  const load = useCallback(() => {
    if (!selAnnee) {
      setLoading(false);
      return;
    }
    setLoading(true);
    diplomesApi.getDiplomes({ annee_academique_id: selAnnee })
      .then(res => setDiplomes(res.data.data))
      .catch(() => toast.error('Erreur'))
      .finally(() => setLoading(false));
  }, [selAnnee, toast]);

  useEffect(() => {
    load();
  }, [load]);

  useEffect(() => {
    adminApi.getAnnees().then(r => {
      setAnnees(r.data.data);
      const current = r.data.data.find(a => a.is_current);
      if (current) setSelAnnee(current.id);
    });
  }, []);

  const handleMarkPrinted = async (id) => {
    try {
      await diplomesApi.markPrinted(id);
      toast.success('Marqué comme imprimé');
      load();
    } catch {
      toast.error('Erreur');
    }
  };

  if (loading) return <Spinner />;

  return (
    <div className="page">
      <div className="page-header">
        <h2 className="page-title">Diplômes</h2>
        <select
          className="form-select"
          value={selAnnee}
          onChange={e => setSelAnnee(e.target.value)}
          style={{ width: 'auto' }}
        >
          <option value="">Sélectionner une année</option>
          {annees.map(a => <option key={a.id} value={a.id}>{a.label}</option>)}
        </select>
      </div>

      <div className="table-wrap">
        <table>
          <thead>
            <tr>
              <th>#</th>
              <th>Stagiaire</th>
              <th>Filière</th>
              <th>Moyenne</th>
              <th>Mention</th>
              <th>Statut impression</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody>
            {diplomes.length === 0 ? (
              <tr><td colSpan={7} className="table-empty">Aucun diplôme généré</td></tr>
            ) : diplomes.map((d, i) => (
              <tr key={d.id}>
                <td>{i + 1}</td>
                <td><strong>{d.etudiant?.nom_prenom}</strong></td>
                <td>{d.etudiant?.groupe?.niveau?.filiere?.nom ?? '—'}</td>
                <td><strong>{d.moyenne_generale}/20</strong></td>
                <td><Badge label={d.mention} color={mentionColor(d.mention)} /></td>
                <td>
                  <Badge
                    label={d.is_printed ? 'Imprimé' : 'Non imprimé'}
                    color={d.is_printed ? 'green' : 'gray'}
                  />
                </td>
                <td>
                  {!d.is_printed && (
                    <button
                      className="btn btn-sm btn-primary"
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