import { NavLink } from 'react-router-dom';
import { useAuth } from '../../context/AuthContext';
import { 
  FiGrid, 
  FiCalendar, 
  FiHome, 
  FiUserPlus, 
  FiBookOpen, 
  FiUserCheck, 
  FiEdit, 
  FiFileText, 
  FiCheckSquare, 
  FiAward,
  FiCamera,
  FiLogOut
} from 'react-icons/fi';
import '../../css/sidebar.css';

const adminLinks = [
  { to: '/admin/dashboard',    label: 'Dashboard',          icon: FiGrid },
  { to: '/admin/annees',       label: 'Années Académiques', icon: FiCalendar },
  { to: '/admin/filieres-groupes', label: 'Filières & Groupes', icon: FiHome },
  { to: '/admin/etudiants',    label: 'Etudiants',         icon: FiUserPlus },
  { to: '/admin/unites',       label: 'Unités & Séquences', icon: FiBookOpen },
  { to: '/admin/formateurs',   label: 'Formateurs',         icon: FiUserCheck },
  { to: '/admin/notes',        label: 'Notes',              icon: FiEdit },
  { to: '/admin/bulletins',    label: 'Bulletins',          icon: FiFileText },
  { to: '/admin/publications', label: 'Publications',       icon: FiCheckSquare },
  { to: '/admin/diplomes',     label: 'Diplômes',           icon: FiAward },
];

const formateurLinks = [
  { to: '/formateur/scanner', label: 'Scanner Notes',         icon: FiCamera },
  { to: '/formateur/notes',   label: 'Mes Unites et Sequences', icon: FiBookOpen },
  { to: '/formateur/liste-notes', label: 'Liste des Notes',   icon: FiEdit },
];

const etudiantLinks = [
  { to: '/etudiant/bulletin', label: 'Mes Notes', icon: FiFileText },
  { to: '/etudiant/mon-bulletin', label: 'Mon Bulletin', icon: FiAward },
];

export default function Sidebar() {
  const { role, logout } = useAuth();

  const links =
    role === 'admin'     ? adminLinks :
    role === 'formateur' ? formateurLinks :
    etudiantLinks;

  return (
    <div className="sidebar">
      <div className="sidebar-logo">
        <div className="sidebar-logo-title">IFP</div>
        <div className="sidebar-logo-sub">Institut des Formations Paramédicales Privé</div>
      </div>

      <nav className="sidebar-nav">
        {links.map(link => {
          const Icon = link.icon;
          return (
            <NavLink
              key={link.to}
              to={link.to}
              className={({ isActive }) =>
                `sidebar-link${isActive ? ' active' : ''}`
              }
            >
              <span className="sidebar-link-icon"><Icon size={20} /></span>
              <span>{link.label}</span>
            </NavLink>
          );
        })}
      </nav>

      <div className="sidebar-footer">
        <button className="sidebar-logout" onClick={logout}>
          <FiLogOut size={18} /> Déconnexion
        </button>
      </div>
    </div>
  );
}