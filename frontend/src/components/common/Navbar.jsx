import { useAuth } from '../../context/AuthContext';
import { useAnneeAcademique } from '../../context/AnneeAcademiqueContext';
import '../../css/navbar.css';

const roleLabels = {
  admin:     'Administrateur',
  formateur: 'Formateur',
  etudiant:  'Etudiant',
};

export default function Navbar({ title }) {
  const { user, role } = useAuth();
  const { currentAnnee } = useAnneeAcademique();

  return (
    <div className="navbar">
      <span className="navbar-title">{title}</span>
      <div className="navbar-right">
        {currentAnnee && <span className="navbar-year">{currentAnnee.label}</span>}
        <span className="navbar-user">{user?.name}</span>
        <span className="navbar-role">{roleLabels[role] ?? role}</span>
      </div>
    </div>
  );
}