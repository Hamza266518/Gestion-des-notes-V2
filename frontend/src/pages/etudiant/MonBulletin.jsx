import { useState, useEffect, useCallback } from 'react';
import { useNavigate } from 'react-router-dom';
import portalApi from '../../api/portal';
import { passwordApi } from '../../api/password';
import { useToast } from '../../context/ToastContext';
import { useAnneeAcademique } from '../../context/AnneeAcademiqueContext';
import { handleApiError } from '../../utils/errorHandler';
import { formatNiveau } from '../../utils/helpers';
import { PasswordChangeModal } from '../../components/etudiant/PasswordChangeModal';
import Badge from '../../components/common/Badge';
import Spinner from '../../components/common/Spinner';
import '../../css/components.css';
import '../../css/layout.css';

function UniteAccordion({ uniteNom, sequences, allControlNums }) {
  const [open, setOpen] = useState(false);

  return (
    <div className="card mb-2">
      <div
        className="card-header"
        style={{ cursor: 'pointer' }}
        onClick={() => setOpen(prev => !prev)}
      >
        <h4 className="mb-0 d-flex justify-content-between align-items-center">
          <span>{uniteNom}</span>
          <span className="chevron">{open ? '▲' : '▼'}</span>
        </h4>
      </div>
      {open && (
        <div className="card-body p-0">
          <div className="table-wrap">
            <table className="table mb-0">
              <thead>
                <tr>
                  <th>Sequence</th>
                  {allControlNums.map((num) => (
                    <th key={num}>C{num}</th>
                  ))}
                </tr>
              </thead>
              <tbody>
                {sequences.map((seq, idx) => (
                  <tr key={idx}>
                    <td>{seq.sequence_nom}</td>
                    {allControlNums.map((num) => {
                      const ctrl = seq.controles.find(c => c.controle_numero === num);
                      return (
                        <td key={num}><strong>{ctrl?.valeur ?? '—'}</strong>/20</td>
                      );
                    })}
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </div>
      )}
    </div>
  );
}

function NotesTable({ notes }) {
  if (!notes || notes.length === 0) return (
    <div className="empty-state"><p>Aucune note disponible</p></div>
  );

  const grouped = notes.reduce((acc, note) => {
    if (!acc[note.unite_nom]) {
      acc[note.unite_nom] = [];
    }
    const existing = acc[note.unite_nom].find(s => s.sequence_nom === note.sequence_nom);
    if (!existing) {
      acc[note.unite_nom].push({ sequence_nom: note.sequence_nom, controles: [note] });
    } else {
      existing.controles.push(note);
    }
    return acc;
  }, {});

  const allControlNums = [...new Set(notes.map(n => n.controle_numero))].sort((a, b) => a - b);

  return (
    <div className="card mb-3">
      <div className="card-body">
        {Object.entries(grouped).map(([uniteNom, sequences]) => (
          <UniteAccordion
            key={uniteNom}
            uniteNom={uniteNom}
            sequences={sequences}
            allControlNums={allControlNums}
          />
        ))}
      </div>
    </div>
  );
}

function BulletinUniteAccordion({ unite }) {
  const [open, setOpen] = useState(false);

  return (
    <div className="card mb-2">
      <div
        className="card-header"
        style={{ cursor: 'pointer' }}
        onClick={() => setOpen(prev => !prev)}
      >
        <h4 className="mb-0 d-flex justify-content-between align-items-center">
          <span>
            {unite.nom}
            <Badge label={`Coef: ${unite.coefficient}`} color="primary" className="ml-2" />
          </span>
          <span className="chevron">{open ? '▲' : '▼'}</span>
        </h4>
      </div>
      {open && (
        <div className="card-body p-0">
          <div className="table-wrap">
            <table className="table mb-0">
              <thead>
                <tr>
                  <th>Sequence</th>
                  <th>Coef</th>
                  {unite.sequences[0]?.controles?.map((ctrl, cIdx) => (
                    <th key={cIdx}>C{ctrl.numero}</th>
                  ))}
                </tr>
              </thead>
              <tbody>
                {unite.sequences?.map((seq, sIdx) => (
                  <tr key={sIdx}>
                    <td>{seq.nom}</td>
                    <td><Badge label={seq.coefficient ?? unite.coefficient} color="info" /></td>
                    {seq.controles?.map((ctrl, cIdx) => (
                      <td key={cIdx}><strong>{ctrl.valeur ?? '—'}</strong></td>
                    ))}
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </div>
      )}
    </div>
  );
}

function BulletinSemestre({ unites }) {
  if (!unites || unites.length === 0) return (
    <div className="empty-state"><p>Aucune donnee disponible</p></div>
  );

  return (
    <div className="card mb-3">
      <div className="card-body">
        {unites.map((unite, idx) => (
          <BulletinUniteAccordion key={idx} unite={unite} />
        ))}
      </div>
    </div>
  );
}

function SemesterToggle({ active, onChange }) {
  return (
    <div className="d-flex gap-2 mb-3">
      <button
        className={`btn ${active === 1 ? 'btn-primary' : 'btn-outline'}`}
        onClick={() => onChange(1)}
      >
        Semestre 1
      </button>
      <button
        className={`btn ${active === 2 ? 'btn-primary' : 'btn-outline'}`}
        onClick={() => onChange(2)}
      >
        Semestre 2
      </button>
    </div>
  );
}

export default function MonBulletin() {
  const [bulletin, setBulletin] = useState(null);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState(null);
  const [activeSemestre, setActiveSemestre] = useState(1);
  const [showPasswordModal, setShowPasswordModal] = useState(false);
  const [checkingPassword, setCheckingPassword] = useState(true);
  const toast = useToast();
  const navigate = useNavigate();
  const { currentAnnee } = useAnneeAcademique();

  const loadBulletin = useCallback(async () => {
    setLoading(true);
    setError(null);
    
    try {
      const res = await portalApi.getBulletin(currentAnnee?.id);
      setBulletin(res.data.data);
    } catch (err) {
      const errorInfo = handleApiError(err, toast, { showToast: false });
      setError(errorInfo.message);
    } finally {
      setLoading(false);
    }
  }, [currentAnnee, toast]);

  // Check if student needs to change password on first login
  useEffect(() => {
    const checkPasswordStatus = async () => {
      try {
        const res = await passwordApi.checkFirstLogin();
        if (res.data.needs_password_change) {
          setShowPasswordModal(true);
        }
      } catch (err) {
        console.error('Error checking password status:', err);
      } finally {
        setCheckingPassword(false);
      }
    };

    checkPasswordStatus();
  }, []);

  useEffect(() => {
    if (!checkingPassword) {
      loadBulletin();
    }
  }, [loadBulletin, checkingPassword]);

  if (loading) return <Spinner />;

  if (error) {
    return (
      <div className="page">
        <div className="alert alert-danger">
          <p>{error}</p>
          <button className="btn btn-outline mt-2" onClick={loadBulletin}>Reessayer</button>
        </div>
      </div>
    );
  }

  if (!bulletin) {
    return (
      <div className="page">
        <div className="empty-state">
          <p>Aucune donnee disponible</p>
        </div>
      </div>
    );
  }

  const { etudiant, publications, notes_s1, notes_s2, bulletin: bulletinData } = bulletin;

  return (
    <div className="page">
      <div className="page-header">
        <button className="btn btn-primary" onClick={() => navigate('/etudiant/mon-bulletin')}>
          Mon Bulletin
        </button>
      </div>

      <div className="card mb-4">
        <div className="card-body">
          <h4 className="mb-3">Informations de l'etudiant</h4>
          <div className="row">
            <div className="col-md-6">
              <p><strong>Nom:</strong> {etudiant.nom_prenom}</p>
              <p><strong>CIN:</strong> {etudiant.cin}</p>
            </div>
            <div className="col-md-6">
              <p><strong>Groupe:</strong> {etudiant.groupe?.nom}</p>
              <p><strong>Niveau:</strong> {formatNiveau(etudiant.groupe?.niveau?.numero)}</p>
            </div>
          </div>
        </div>
      </div>


      {publications?.bulletin && bulletinData ? (
        <div>
          <SemesterToggle active={activeSemestre} onChange={setActiveSemestre} />
          <BulletinSemestre unites={bulletinData.semestres?.[activeSemestre]} />
        </div>
      ) : (
        <div>
          <SemesterToggle active={activeSemestre} onChange={setActiveSemestre} />
          {activeSemestre === 1 && publications?.notes_s1 && <NotesTable notes={notes_s1} />}
          {activeSemestre === 2 && publications?.notes_s2 && <NotesTable notes={notes_s2} />}
          {((activeSemestre === 1 && !publications?.notes_s1) || (activeSemestre === 2 && !publications?.notes_s2)) && (
            <div className="empty-state"><p>Aucune note publiee pour ce semestre</p></div>
          )}
        </div>
      )}

      {showPasswordModal && (
        <PasswordChangeModal 
          onSuccess={() => setShowPasswordModal(false)} 
        />
      )}
    </div>
  );
}

