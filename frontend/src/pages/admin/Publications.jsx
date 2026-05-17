import { useEffect, useState, useCallback } from 'react';
import { publicationsApi } from '../../api/publications';
import { adminApi } from '../../api/admin';
import { useToast } from '../../context/ToastContext';
import { useAnneeAcademique } from '../../context/AnneeAcademiqueContext';
import { handleApiError, showSuccess } from '../../utils/errorHandler';
import { formatNiveau } from '../../utils/helpers';
import Badge from '../../components/common/Badge';
import Spinner from '../../components/common/Spinner';
import Modal from '../../components/common/Modal';
import '../../css/components.css';
import '../../css/layout.css';
import '../../css/pages.css';

const TYPE_LABELS = {
  notes_s1: 'Notes S1',
  notes_s2: 'Notes S2',
  bulletin: 'Bulletin',
};

const TYPE_COLORS = {
  notes_s1: 'blue',
  notes_s2: 'purple',
  bulletin: 'green',
};

export default function Publications() {
  const [groupes, setGroupes] = useState([]);
  const [publications, setPublications] = useState([]);
  const [loading, setLoading] = useState(true);
  const [processing, setProcessing] = useState(null);
  const [bulkProcessing, setBulkProcessing] = useState(null);
  const [confirmModal, setConfirmModal] = useState(null);
  const { currentAnnee } = useAnneeAcademique();

  const toast = useToast();

  const loadData = useCallback(() => {
    if (!currentAnnee) {
      setGroupes([]);
      setPublications([]);
      setLoading(false);
      return;
    }
    setLoading(true);
    Promise.all([
      adminApi.getGroupes({ annee_academique_id: currentAnnee.id }),
      publicationsApi.getPublications({ annee_academique_id: currentAnnee.id }),
    ])
      .then(([groupesRes, pubsRes]) => {
        setGroupes(groupesRes.data.data || []);
        setPublications(pubsRes.data.data || []);
      })
      .catch(() => { setGroupes([]); setPublications([]); })
      .finally(() => setLoading(false));
  }, [currentAnnee]);

  useEffect(() => {
    loadData();
  }, [loadData]);

  const getPublication = (groupeId, type) => {
    return publications.find(p => p.groupe_id == groupeId && p.type === type);
  };

  const handlePublish = async (groupe, type) => {
    setProcessing(`${groupe.id}-${type}`);
    try {
      await publicationsApi.publish({ groupe_id: groupe.id, annee_academique_id: currentAnnee.id, type });
      showSuccess(toast, `${TYPE_LABELS[type]} publié pour ${groupe.nom}`);
      loadData();
    } catch (error) {
      handleApiError(error, toast);
    } finally {
      setProcessing(null);
      setConfirmModal(null);
    }
  };

  const handlePublishAll = async (type) => {
    setBulkProcessing(type);
    try {
      const res = await publicationsApi.publishAll({ annee_academique_id: currentAnnee.id, type });
      showSuccess(toast, res.data.message);
      loadData();
    } catch (error) {
      handleApiError(error, toast);
    } finally {
      setBulkProcessing(null);
      setConfirmModal(null);
    }
  };

  const handleUnpublish = async (pub) => {
    setProcessing(pub.id);
    try {
      await publicationsApi.unpublish({ groupe_id: pub.groupe_id, annee_academique_id: currentAnnee.id, type: pub.type });
      showSuccess(toast, `${TYPE_LABELS[pub.type]} dépublié`);
      loadData();
    } catch (error) {
      handleApiError(error, toast);
    } finally {
      setProcessing(null);
      setConfirmModal(null);
    }
  };

  const PublicationCell = ({ pub, type, groupe }) => {
    const isProcessing = processing === `${groupe.id}-${type}` || processing === pub?.id;

    if (pub?.is_published) {
      const date = pub.published_at
        ? new Date(pub.published_at).toLocaleDateString('fr-FR', { day: '2-digit', month: 'short', year: 'numeric' })
        : '';
      return (
        <div className="pub-cell">
          <Badge label="Publié" color={TYPE_COLORS[type] || 'green'} />
          <span className="pub-date">{date}</span>
          <button
            className="btn btn-sm btn-outline pub-unpublish-btn"
            onClick={() => setConfirmModal({ type: 'unpublish', pub, groupe })}
            disabled={isProcessing}
          >
            {isProcessing ? '...' : 'Dépublier'}
          </button>
        </div>
      );
    }

    return (
      <button
        className="btn btn-sm btn-accent"
        onClick={() => setConfirmModal({ type: 'publish', groupe, publicationType: type })}
        disabled={isProcessing}
      >
        {isProcessing ? '...' : `Publier ${TYPE_LABELS[type]}`}
      </button>
    );
  };

  if (loading) {
    return (
      <div className="text-center mt-5">
        <Spinner />
      </div>
    );
  }

  return (
    <div className="page-container">
      <div className="page-header">
        {currentAnnee && (
          <Badge label={`Année: ${currentAnnee.label}`} color="blue" />
        )}
      </div>

      <div className="filter-bar" style={{ marginBottom: 20 }}>
        <button
          className="btn btn-accent"
          onClick={() => setConfirmModal({ type: 'bulk', bulkType: 'notes_s1' })}
          disabled={bulkProcessing !== null || !currentAnnee}
        >
          {bulkProcessing === 'notes_s1' ? 'Publication...' : 'Publier toutes les Notes S1'}
        </button>
        <button
          className="btn btn-accent"
          onClick={() => setConfirmModal({ type: 'bulk', bulkType: 'notes_s2' })}
          disabled={bulkProcessing !== null || !currentAnnee}
        >
          {bulkProcessing === 'notes_s2' ? 'Publication...' : 'Publier toutes les Notes S2'}
        </button>
        <button
          className="btn btn-accent"
          onClick={() => setConfirmModal({ type: 'bulk', bulkType: 'bulletin' })}
          disabled={bulkProcessing !== null || !currentAnnee}
        >
          {bulkProcessing === 'bulletin' ? 'Publication...' : 'Publier tous les Bulletins'}
        </button>
      </div>

      {groupes.length === 0 ? (
        <div className="pub-empty-state">
          <h4 className="pub-empty-title">Aucun groupe trouvé</h4>
          <p className="pub-empty-text">Sélectionnez une année académique.</p>
        </div>
      ) : (
        <div className="table-wrap">
          <table>
            <thead>
              <tr>
                <th>Groupe</th>
                <th>Filière</th>
                <th>Niveau</th>
                <th>Notes S1</th>
                <th>Notes S2</th>
                <th>Bulletin</th>
              </tr>
            </thead>
            <tbody>
              {groupes.map(g => {
                const pubS1 = getPublication(g.id, 'notes_s1');
                const pubS2 = getPublication(g.id, 'notes_s2');
                const pubBulletin = getPublication(g.id, 'bulletin');
                return (
                  <tr key={g.id}>
                    <td><strong>{g.nom}</strong></td>
                    <td>{g.niveau?.filiere?.nom || '—'}</td>
                    <td>{g.niveau ? formatNiveau(g.niveau?.numero) : '—'}</td>
                    <td>
                      <PublicationCell pub={pubS1} type="notes_s1" groupe={g} />
                    </td>
                    <td>
                      <PublicationCell pub={pubS2} type="notes_s2" groupe={g} />
                    </td>
                    <td>
                      <PublicationCell pub={pubBulletin} type="bulletin" groupe={g} />
                    </td>
                  </tr>
                );
              })}
            </tbody>
          </table>
        </div>
      )}

      <Modal
        open={!!confirmModal}
        onClose={() => setConfirmModal(null)}
        title={
          confirmModal?.type === 'bulk' ? 'Confirmer la publication en masse' :
          confirmModal?.type === 'publish' ? 'Confirmer la publication' : 'Confirmer la dépublication'
        }
      >
        <p className="pub-confirm-text">
          {confirmModal?.type === 'bulk'
            ? `Cette action publiera ${TYPE_LABELS[confirmModal?.bulkType]} pour TOUS les groupes. Les étudiants pourront voir ces informations. Continuer ?`
            : confirmModal?.type === 'publish'
            ? `Cette action publiera ${TYPE_LABELS[confirmModal?.publicationType]} pour le groupe "${confirmModal?.groupe?.nom}". Les étudiants pourront voir ces informations. Continuer ?`
            : `Cette action dépubliera ${TYPE_LABELS[confirmModal?.pub?.type]} pour le groupe "${confirmModal?.pub?.groupe?.nom || confirmModal?.groupe?.nom}". Les étudiants ne pourront plus voir ces informations. Continuer ?`
          }
        </p>
        <div className="modal-footer">
          <button className="btn btn-outline" onClick={() => setConfirmModal(null)}>Annuler</button>
          <button
            className={`btn ${confirmModal?.type === 'unpublish' ? 'btn-danger' : 'btn-accent'}`}
            onClick={() => {
              if (confirmModal.type === 'publish') handlePublish(confirmModal.groupe, confirmModal.publicationType);
              else if (confirmModal.type === 'bulk') handlePublishAll(confirmModal.bulkType);
              else handleUnpublish(confirmModal.pub);
            }}
            disabled={processing !== null || bulkProcessing !== null}
          >
            {confirmModal?.type === 'unpublish' ? 'Dépublier' : 'Publier'}
          </button>
        </div>
      </Modal>
    </div>
  );
}
