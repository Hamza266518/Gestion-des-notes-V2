import { useEffect, useState, useCallback } from 'react';
import { notesApi } from '../../api/notes';
import { adminApi } from '../../api/admin';
import { etudiantsApi } from '../../api/etudiants';
import { useToast } from '../../context/ToastContext';
import { useAnneeAcademique } from '../../context/AnneeAcademiqueContext';
import { handleApiError } from '../../utils/errorHandler';
import { formatNiveau } from '../../utils/helpers';
import BulletinSheet from '../../components/admin/BulletinSheet';
import { DOCX_MIME, saveBlob, filenameFromDisposition } from '../../components/admin/RecapTable';
import Spinner from '../../components/common/Spinner';
import FilterBar from '../../components/common/FilterBar';
import FilterSelect from '../../components/common/FilterSelect';
import usePersistedState from '../../hooks/usePersistedState';
import '../../css/components.css';
import '../../css/layout.css';
import '../../css/pages.css';

export default function Bulletins() {
  const [filieres, setFilieres] = useState([]);
  const [niveaux, setNiveaux] = useState([]);
  const [groupes, setGroupes] = useState([]);
  const [etudiants, setEtudiants] = useState([]);

  const [saved, setSaved] = usePersistedState('bulletins', { filiereId: '', niveauId: '', groupeId: '', etudiantId: '' });
  const [filiereId, setFiliereId] = useState(() => saved.filiereId);
  const [niveauId, setNiveauId] = useState(() => saved.niveauId);
  const [groupeId, setGroupeId] = useState(() => saved.groupeId);
  const [etudiantId, setEtudiantId] = useState(() => saved.etudiantId);

  useEffect(() => {
    setSaved({ filiereId, niveauId, groupeId, etudiantId });
  }, [filiereId, niveauId, groupeId, etudiantId, setSaved]);

  const [bulletin, setBulletin] = useState(null);
  const [loading, setLoading] = useState(false);
  const [downloadingDocx, setDownloadingDocx] = useState(false);
  const [downloadingDocxAll, setDownloadingDocxAll] = useState(false);

  const toast = useToast();
  const { currentAnnee } = useAnneeAcademique();

  useEffect(() => {
    adminApi.getFilieres()
      .then(res => setFilieres(res.data.data || []))
      .catch(() => setFilieres([]));
  }, []);

  useEffect(() => {
    if (!filiereId) {
      setNiveaux([]);
      setNiveauId('');
      setGroupeId('');
      return;
    }
    adminApi.getNiveaux(filiereId)
      .then(res => {
        const niveauxData = res.data.data || [];
        setNiveaux(niveauxData);
        setNiveauId(cur => (niveauxData.some(n => String(n.id) === String(cur)) ? cur : ''));
      })
      .catch(() => setNiveaux([]));
  }, [filiereId]);

  useEffect(() => {
    if (!niveauId) {
      setGroupes([]);
      setGroupeId('');
      return;
    }
    const params = { niveau_id: niveauId };
    if (currentAnnee) {
      params.annee_academique_id = currentAnnee.id;
    }
    adminApi.getGroupes(params)
      .then(res => {
        const groupesData = res.data.data || [];
        setGroupes(groupesData);
        setGroupeId(cur => (groupesData.some(g => String(g.id) === String(cur)) ? cur : ''));
      })
      .catch(() => setGroupes([]));
  }, [niveauId, currentAnnee]);

  useEffect(() => {
    if (!groupeId) {
      setEtudiants([]);
      setEtudiantId('');
      return;
    }
    etudiantsApi.getEtudiants({ groupe_id: groupeId })
      .then(res => {
        const etudiantsData = res.data.data || [];
        setEtudiants(etudiantsData);
        setEtudiantId(cur => (etudiantsData.some(e => String(e.id) === String(cur)) ? cur : ''));
      })
      .catch(() => setEtudiants([]));
  }, [groupeId]);

  const loadBulletin = useCallback(async () => {
    if (!etudiantId || !groupeId || !currentAnnee) return;

    setLoading(true);
    try {
      const res = await notesApi.getBulletin({
        etudiant_id: etudiantId,
        groupe_id: groupeId,
        annee_academique_id: currentAnnee.id,
      });
      setBulletin(res.data.data);
    } catch (error) {
      handleApiError(error, toast);
    } finally {
      setLoading(false);
    }
  }, [etudiantId, groupeId, currentAnnee]);

  useEffect(() => {
    loadBulletin();
  }, [loadBulletin]);

  // The .docx twin of the on-screen sheet: the very same bulletin, one A4
  // portrait page, laid out like the school's reference document. The
  // whole-group variant writes one bulletin per page.
  const downloadDocx = async () => {
    if (!etudiantId || !groupeId || !currentAnnee) return;
    setDownloadingDocx(true);
    try {
      const res = await notesApi.downloadBulletinDocx({
        etudiant_id: etudiantId,
        groupe_id: groupeId,
        annee_academique_id: currentAnnee.id,
      });
      const blob = new Blob([res.data], { type: DOCX_MIME });
      saveBlob(blob, filenameFromDisposition(res.headers?.['content-disposition'], 'Bulletin.docx'));
    } catch (error) {
      handleApiError(error, toast);
    } finally {
      setDownloadingDocx(false);
    }
  };

  const downloadDocxAll = async () => {
    if (!groupeId || !currentAnnee) return;
    setDownloadingDocxAll(true);
    try {
      const res = await notesApi.downloadBulletinDocxAll({
        groupe_id: groupeId,
        annee_academique_id: currentAnnee.id,
      });
      const blob = new Blob([res.data], { type: DOCX_MIME });
      saveBlob(blob, filenameFromDisposition(res.headers?.['content-disposition'], 'Bulletins.docx'));
    } catch (error) {
      handleApiError(error, toast);
    } finally {
      setDownloadingDocxAll(false);
    }
  };

  const handleResetFilters = () => {
    setNiveauId('');
    setGroupeId('');
    setEtudiantId('');
  };

  const handleResetGroupeFilters = () => {
    setEtudiantId('');
  };

  return (
    <div className="page">
      <FilterBar
        showSearch={false}
        value={{ filiere_id: filiereId, niveau_id: niveauId, groupe_id: groupeId, etudiant_id: etudiantId }}
        onChange={() => {}}
        onClear={() => {
          setFiliereId('');
          setNiveauId('');
          setGroupeId('');
          setEtudiantId('');
        }}
        chips={[
          filiereId && {
            key: 'filiere',
            label: `Filière: ${filieres.find(f => String(f.id) === String(filiereId))?.nom ?? ''}`,
            onRemove: () => { setFiliereId(''); handleResetFilters(); },
          },
          niveauId && {
            key: 'niveau',
            label: `Niveau: ${formatNiveau(niveaux.find(n => String(n.id) === String(niveauId))?.numero)}`,
            onRemove: () => { setNiveauId(''); setGroupeId(''); handleResetGroupeFilters(); },
          },
          groupeId && {
            key: 'groupe',
            label: `Groupe: ${groupes.find(g => String(g.id) === String(groupeId))?.nom ?? ''}`,
            onRemove: () => { setGroupeId(''); handleResetGroupeFilters(); },
          },
          etudiantId && {
            key: 'etudiant',
            label: `Étudiant: ${etudiants.find(e => String(e.id) === String(etudiantId))?.nom ?? etudiants.find(e => String(e.id) === String(etudiantId))?.nom_prenom ?? ''}`,
            onRemove: () => setEtudiantId(''),
          },
        ].filter(Boolean)}
      >
        <FilterSelect
          label="Filière"
          value={filiereId}
          options={filieres.map(f => ({ id: f.id, label: f.nom }))}
          onChange={(v) => { setFiliereId(v); handleResetFilters(); }}
          placeholder="Toutes les filières"
          allLabel="Toutes les filières"
        />
        <FilterSelect
          label="Niveau"
          value={niveauId}
          options={niveaux.map(n => ({ id: n.id, label: formatNiveau(n.numero) }))}
          onChange={(v) => { setNiveauId(v); setGroupeId(''); handleResetGroupeFilters(); }}
          disabled={!filiereId}
          placeholder="Tous les niveaux"
          allLabel="Tous les niveaux"
        />
        <FilterSelect
          label="Groupe"
          value={groupeId}
          options={groupes.map(g => ({ id: g.id, label: g.nom }))}
          onChange={(v) => { setGroupeId(v); handleResetGroupeFilters(); }}
          disabled={!niveauId}
          placeholder="Tous les groupes"
          allLabel="Tous les groupes"
        />
        <FilterSelect
          label="Étudiant"
          value={etudiantId}
          options={etudiants.map(e => ({ id: e.id, label: e.nom || e.nom_prenom }))}
          onChange={setEtudiantId}
          disabled={!groupeId}
          placeholder="Tous les étudiants"
          allLabel="Tous les étudiants"
        />
      </FilterBar>

      {!loading && !bulletin && (
        <div className="empty-state" style={{ marginTop: 40 }}>
          <p className="empty-state-message">Sélectionnez une filière, un niveau, un groupe et un étudiant pour afficher le bulletin</p>
        </div>
      )}

      {loading && (
        <div className="text-center mt-5">
          <Spinner />
        </div>
      )}

      {bulletin && bulletin.student && (
        <BulletinSheet
          bulletin={bulletin}
          filiereNom={filieres.find(f => f.id == filiereId)?.nom}
          groupeNom={groupes.find(g => g.id == groupeId)?.nom}
          anneeLabel={currentAnnee?.label}
          onDownloadDocx={downloadDocx}
          onDownloadDocxAll={downloadDocxAll}
          downloadingDocx={downloadingDocx}
          downloadingDocxAll={downloadingDocxAll}
        />
      )}
    </div>
  );
}
