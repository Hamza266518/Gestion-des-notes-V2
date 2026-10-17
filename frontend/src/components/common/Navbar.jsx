import { useAuth } from '../../context/AuthContext';
import { useAnneeAcademique } from '../../context/AnneeAcademiqueContext';
import { useAccountModal } from '../../context/AccountModalContext';
import { FiMenu, FiUser } from 'react-icons/fi';
import '../../css/navbar.css';

const roleLabels = {
  admin:     'Administrateur',
  formateur: 'Formateur',
  etudiant:  'Etudiant',
};

export default function Navbar({ title, onToggleSidebar }) {
  const { user, role } = useAuth();
  const { currentAnnee } = useAnneeAcademique();
  const { openAccount } = useAccountModal();

  return (
    <div className="navbar">
      <button className="navbar-hamburger" onClick={onToggleSidebar} aria-label="Toggle menu">
        <FiMenu size={22} />
      </button>
      <span className="navbar-title">{title}</span>
      <div className="navbar-right">
        {currentAnnee && <span className="navbar-year">{currentAnnee.label}</span>}
        <button type="button" className="navbar-account" onClick={openAccount}>
          <FiUser size={14} /> Mon compte
        </button>
        <span className="navbar-user">{user?.name}</span>
        <span className="navbar-role">{roleLabels[role] ?? role}</span>
      </div>
    </div>
  );
}
