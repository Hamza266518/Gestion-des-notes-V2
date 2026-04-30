import { FiCreditCard, FiHash, FiUsers, FiBookOpen, FiCalendar } from 'react-icons/fi';

export default function StudentCard({ etudiant }) {
  const groupe = etudiant.groupe;
  const filiere = groupe?.filiere?.nom;
  const anneeAcademique = etudiant.anneeAcademique?.label;

  return (
    <div className="student-card">
      <div className="student-info">
        <div className="info-item">
          <FiCreditCard />
          <span className="info-label">CIN</span>
          <span>{etudiant.cin}</span>
        </div>
        <div className="info-item">
          <FiHash />
          <span className="info-label">N°</span>
          <span>{etudiant.numero_inscription}</span>
        </div>
        {filiere && (
          <div className="info-item">
            <FiBookOpen />
            <span className="info-label">Filière</span>
            <span>{filiere}</span>
          </div>
        )}
        {groupe?.nom && (
          <div className="info-item">
            <FiUsers />
            <span className="info-label">Groupe</span>
            <span>{groupe.nom}</span>
          </div>
        )}
        {anneeAcademique && (
          <div className="info-item">
            <FiCalendar />
            <span className="info-label">Année</span>
            <span>{anneeAcademique}</span>
          </div>
        )}
      </div>
    </div>
  );
}
