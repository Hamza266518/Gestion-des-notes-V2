import { useState, useEffect, useCallback, useMemo } from 'react';
import { FiAlertTriangle, FiRefreshCw, FiInbox } from 'react-icons/fi';
import portalApi from '../../api/portal';
import { passwordApi } from '../../api/password';
import { useToast } from '../../context/ToastContext';
import { useAnneeAcademique } from '../../context/AnneeAcademiqueContext';
import { handleApiError } from '../../utils/errorHandler';
import {
  formatNiveau, controlColumnLabel, controlColumnKey,
  gradeValueClass,
} from '../../utils/helpers';
import { PasswordChangeModal } from '../../components/etudiant/PasswordChangeModal';
import '../../css/components.css';
import '../../css/layout.css';
import '../../css/mes-notes.css';

function sortColumns(cols) {
  return [...cols].sort((a, b) => (a.numero ?? 0) - (b.numero ?? 0));
}

/**
 * A sequence only gets a cell for the controls it actually has. One unit can mix
 * a sequence with a single CC and another with three, so a column that does not
 * apply is left empty rather than filled with a dash: a dash would look like a
 * control that exists but has not been graded yet.
 */
function GradeCell({ ctrl }) {
  if (!ctrl) return <td className="grade-cell" />;

  if (ctrl.est_absent) {
    return (
      <td className="grade-cell">
        <span className="grade-chip grade-fail">Absent</span>
      </td>
    );
  }

  const max = ctrl.note_max ?? 20;

  if (ctrl.valeur === null || ctrl.valeur === undefined) {
    return (
      <td className="grade-cell">
        <span className="grade-chip grade-none">Non noté</span>
      </td>
    );
  }

  return (
    <td className="grade-cell">
      <span className={`grade-chip ${gradeValueClass(ctrl.valeur, max)}`}>
        {ctrl.valeur}
        <small>/{max}</small>
      </span>
    </td>
  );
}

function UnitSection({ unite, sequences, columns }) {
  return (
    <section className="unit-block">
      <div className="unit-head">
        <div>
          <h2 className="unit-title">{unite.nom}</h2>
          <p className="unit-sub">
            {sequences.length} séquence{sequences.length > 1 ? 's' : ''}
          </p>
        </div>
        {unite.coefficient != null && (
          <span className="coef-label">Coef {unite.coefficient}</span>
        )}
      </div>

      <div className="table-wrapper">
        <table className="notes-table">
          <thead>
            <tr>
              <th>Séquence</th>
              {columns.map(col => <th key={col.key}>{col.label}</th>)}
            </tr>
          </thead>
          <tbody>
            {sequences.map((seq, idx) => (
              <tr key={idx}>
                <td className="seq-cell">
                  <span className="seq-name">{seq.nom}</span>
                  {seq.controles?.length ? (
                    <span className="seq-coef">{seq.controles.length} contrôle(s)</span>
                  ) : null}
                </td>
                {columns.map(col => (
                  <GradeCell
                    key={col.key}
                    ctrl={(seq.controles || []).find(
                      c => controlColumnKey(c.controle_numero) === col.key
                    )}
                  />
                ))}
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </section>
  );
}

function NotesGrid({ notes }) {
  const units = useMemo(() => {
    const map = new Map();

    for (const note of notes) {
      const key = note.unite_id ?? note.unite_nom;
      if (!map.has(key)) {
        map.set(key, {
          id: key,
          nom: note.unite_nom,
          coefficient: note.unite_coefficient,
          sequenceMap: new Map(),
        });
      }
      const unit = map.get(key);

      if (!unit.sequenceMap.has(note.sequence_nom)) {
        unit.sequenceMap.set(note.sequence_nom, {
          nom: note.sequence_nom,
          controles: [],
        });
      }
      unit.sequenceMap.get(note.sequence_nom).controles.push(note);
    }

    return [...map.values()].map(u => ({ ...u, sequences: [...u.sequenceMap.values()] }));
  }, [notes]);

  const columns = useMemo(() => {
    const seen = new Map();
    for (const note of notes) {
      const key = controlColumnKey(note.controle_numero);
      if (!seen.has(key)) {
        seen.set(key, { key, numero: note.controle_numero });
      }
    }
    return sortColumns([...seen.values()]).map(c => ({
      ...c,
      label: controlColumnLabel(c.numero),
    }));
  }, [notes]);

  return (
    <>
      {units.map(unit => (
        <UnitSection
          key={unit.id}
          unite={{ nom: unit.nom, coefficient: unit.coefficient }}
          sequences={unit.sequences}
          columns={columns}
        />
      ))}
    </>
  );
}

function BulletinUnit({ unite }) {
  const columns = useMemo(() => {
    const seen = new Map();
    for (const seq of unite.sequences || []) {
      for (const ctrl of seq.controles || []) {
        const key = controlColumnKey(ctrl.numero);
        if (!seen.has(key)) {
          seen.set(key, { key, numero: ctrl.numero });
        }
      }
    }
    return sortColumns([...seen.values()]).map(c => ({
      ...c,
      label: controlColumnLabel(c.numero),
    }));
  }, [unite.sequences]);

  const sequences = (unite.sequences || []).map(seq => ({
    nom: seq.nom,
    controles: (seq.controles || []).map(c => ({
      controle_numero: c.numero,
      valeur: c.valeur,
      note_max: c.note_max,
      est_absent: c.est_absent,
    })),
  }));

  return <UnitSection unite={unite} sequences={sequences} columns={columns} />;
}

function initialsOf(name) {
  const parts = (name || '').trim().split(/\s+/).filter(Boolean);
  if (parts.length === 0) return '';
  const last = parts.length > 1 ? parts[parts.length - 1] : parts[0];
  return (parts[0][0] + last[0]).toUpperCase();
}

function StudentHeader({ etudiant }) {
  const groupe = etudiant.groupe || {};
  const niveau = groupe.niveau || {};
  const filiere = niveau.filiere || {};

  const facts = [
    { label: 'CIN', value: etudiant.cin },
    { label: 'N° inscription', value: etudiant.numero_inscription },
    { label: 'Groupe', value: groupe.nom },
    { label: 'Filière', value: filiere.nom },
    { label: 'Niveau', value: niveau.numero ? formatNiveau(niveau.numero) : null },
    { label: 'Promotion', value: groupe.promotion },
  ].filter(f => f.value);

  return (
    <header className="student-card">
      <div className="student-id">
        <span className="student-avatar" aria-hidden="true">
          {initialsOf(etudiant.nom_prenom)}
        </span>
        <h1 className="student-name">{etudiant.nom_prenom}</h1>
      </div>

      {facts.length > 0 && (
        <dl className="student-facts">
          {facts.map(fact => (
            <div className="student-fact" key={fact.label}>
              <dt>{fact.label}</dt>
              <dd>{fact.value}</dd>
            </div>
          ))}
        </dl>
      )}
    </header>
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

  if (loading) {
    return (
      <div className="mes-notes-page">
        <div className="mes-notes-container">
          <div className="notes-loading">
            <div className="loading-spinner" />
            <p>Chargement de vos notes</p>
          </div>
        </div>
      </div>
    );
  }

  if (error) {
    return (
      <div className="mes-notes-page">
        <div className="mes-notes-container">
          <div className="notes-error">
            <FiAlertTriangle className="error-icon" />
            <h3>Impossible de charger vos notes</h3>
            <p>{error}</p>
            <button className="btn-retry" onClick={loadBulletin}>
              <FiRefreshCw /> Réessayer
            </button>
          </div>
        </div>
      </div>
    );
  }

  if (!bulletin) {
    return (
      <div className="mes-notes-page">
        <div className="mes-notes-container">
          <div className="notes-error">
            <FiInbox className="error-icon" />
            <h3>Aucune note disponible</h3>
            <p>Vos notes n'ont pas encore été publiées.</p>
          </div>
        </div>
      </div>
    );
  }

  const { etudiant, publications, notes_s1, notes_s2, bulletin: bulletinData } = bulletin;

  const activeNotes = activeSemestre === 1 ? notes_s1 : notes_s2;
  const notesPublished = activeSemestre === 1 ? publications?.notes_s1 : publications?.notes_s2;
  const bulletinPublished = publications?.bulletin && bulletinData;
  const semesterUnites = bulletinPublished
    ? bulletinData.semestres?.[activeSemestre] || []
    : [];

  return (
    <div className="mes-notes-page">
      <div className="mes-notes-container">
        <StudentHeader etudiant={etudiant} />

        <nav className="semester-section">
          <button
            className={`semestre-btn ${activeSemestre === 1 ? 'active' : ''}`}
            onClick={() => setActiveSemestre(1)}
          >
            Semestre 1
          </button>
          <button
            className={`semestre-btn ${activeSemestre === 2 ? 'active' : ''}`}
            onClick={() => setActiveSemestre(2)}
          >
            Semestre 2
          </button>
        </nav>

        {!notesPublished && !bulletinPublished && (
          <div className="publication-notice">
            <FiAlertTriangle className="notice-icon" />
            <p>Les notes de ce semestre ne sont pas encore publiées.</p>
          </div>
        )}

        {bulletinPublished
          ? (semesterUnites.length > 0
            ? semesterUnites.map((unite, idx) => (
              <BulletinUnit key={unite.id ?? idx} unite={unite} />
            ))
            : (
              <div className="unit-block">
                <p className="table-empty">Aucune note pour ce semestre</p>
              </div>
            ))
          : (notesPublished && <NotesGrid notes={activeNotes || []} />)}
      </div>

      {showPasswordModal && (
        <PasswordChangeModal onSuccess={() => setShowPasswordModal(false)} />
      )}
    </div>
  );
}