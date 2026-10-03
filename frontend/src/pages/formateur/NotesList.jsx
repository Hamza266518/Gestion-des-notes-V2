import { useState, useEffect, useCallback } from 'react';
import { formateursApi } from '../../api/formateurs';
import { useToast } from '../../context/ToastContext';
import Spinner from '../../components/common/Spinner';
import { handleApiError, showSuccess } from '../../utils/errorHandler';
import '../../css/components.css';
import '../../css/layout.css';

export default function NotesList() {
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);
  const [sequences, setSequences] = useState([]);
  const toast = useToast();

  const loadSequences = useCallback(async () => {
    setLoading(true);
    setError(null);

    try {
      const res = await formateursApi.getMySequences();
      setSequences(res.data.data || []);
    } catch (err) {
      const errorInfo = handleApiError(err, toast, { showToast: false });
      setError(errorInfo.message);
    } finally {
      setLoading(false);
    }
  }, [toast]);

  useEffect(() => {
    loadSequences();
  }, [loadSequences]);

  if (loading && sequences.length === 0) return <Spinner />;

  if (error) {
    return (
      <div className="page">
        <div className="alert alert-danger">
          <p>{error}</p>
          <button className="btn btn-outline mt-2" onClick={loadSequences}>Réessayer</button>
        </div>
      </div>
    );
  }

  return (
    <div className="page">
      <div className="page-header">
        <h2 className="page-title">Mes Unités et Séquences</h2>
      </div>

      <div className="card mb-3">
        <div className="card-body">
          <div className="table-wrap">
            <table className="table">
              <thead>
                <tr>
                  <th>Unité</th>
                  <th>Séquence</th>
                  <th>Coef. unité</th>
                  <th>Contrôles</th>
                </tr>
              </thead>
              <tbody>
                {sequences.length === 0 ? (
                  <tr><td colSpan="4" className="text-center">Aucune séquence assignée</td></tr>
                ) : (
                  sequences.map((seq) => (
                    <tr key={seq.id}>
                      <td><strong>{seq.unite?.nom}</strong></td>
                      <td>{seq.nom}</td>
                      <td style={{ textAlign: 'center', fontWeight: 600 }}>{seq.unite?.coefficient ?? '—'}</td>
                      <td>{seq.controles?.length ?? seq.nombre_controles ?? 0} contrôle(s)</td>
                    </tr>
                  ))
                )}
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </div>
  );
}
