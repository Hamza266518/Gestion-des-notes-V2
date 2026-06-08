import { useState, useEffect, useCallback } from 'react';
import { formateursApi } from '../../api/formateurs';
import { useToast } from '../../context/ToastContext';
import { useAnneeAcademique } from '../../context/AnneeAcademiqueContext';
import Spinner from '../../components/common/Spinner';
import { handleApiError, showSuccess } from '../../utils/errorHandler';
import '../../css/components.css';
import '../../css/layout.css';

export default function NotesList() {
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);
  const [sequences, setSequences] = useState([]);
  const toast = useToast();
  const { currentAnnee } = useAnneeAcademique();

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
          <button className="btn btn-outline mt-2" onClick={loadSequences}>Reessayer</button>
        </div>
      </div>
    );
  }

  return (
    <div className="page">
      <div className="page-header">
        <h2 className="page-title">Mes Unites et Sequences</h2>
      </div>

      <div className="card mb-3">
        <div className="card-header">
          <h5 className="mb-0">Mes Unites et Sequences</h5>
        </div>
        <div className="card-body">
          <div className="table-wrap">
            <table className="table">
              <thead>
                <tr>
                  <th>Unite</th>
                  <th>Sequence</th>
                  <th>Coef</th>
                  <th>Controles</th>
                </tr>
              </thead>
              <tbody>
                {sequences.length === 0 ? (
                  <tr><td colSpan="4" className="text-center">Aucune sequence assignee</td></tr>
                ) : (
                  sequences.map((seq) => (
                    <tr key={seq.id}>
                      <td><strong>{seq.unite?.nom}</strong></td>
                      <td>{seq.nom}</td>
                      <td style={{ textAlign: 'center', fontWeight: 600 }}>{seq.coefficient ?? '—'}</td>
                      <td>{seq.nombre_controles || 0} controle(s)</td>
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
