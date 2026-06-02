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
  FiLogOut,
  FiChevronLeft,
  FiChevronRight,
  FiX
} from 'react-icons/fi';
import logoMenu from '../../image/logo-menu.png';
import '../../css/sidebar.css';

const adminLinks = [
  { to: '/admin/dashboard',    label: 'Dashboard',          icon: FiGrid },
  { to: '/admin/annees',       label: 'Années Académiques', icon: FiCalendar },
  { to: '/admin/filieres-groupes', label: 'Filières & Groupes', icon: FiHome },
  { to: '/admin/etudiants',    label: 'Etudiants',         icon: FiUserPlus },
  { to: '/admin/unites',       label: 'Unités & Séquences', icon: FiBookOpen },
  { to: '/admin/formateurs',   label: 'Formateurs',         icon: FiUserCheck },
  { to: '/admin/notes',        label: 'Relevé des Notes',   icon: FiFileText },
  { to: '/admin/saisie-notes', label: 'Saisie des Notes',   icon: FiEdit },
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

export default function Sidebar({ collapsed, mobileOpen, onToggle, onCloseMobile }) {
  const { role, logout } = useAuth();

  const links =
    role === 'admin'     ? adminLinks :
    role === 'formateur' ? formateurLinks :
    etudiantLinks;

  return (
    <>
      {mobileOpen && (
        <div className="sidebar-overlay" onClick={onCloseMobile} />
      )}

      <div className={`sidebar${collapsed ? ' sidebar-collapsed' : ''}${mobileOpen ? ' sidebar-mobile-open' : ''}`}>
        <div className="sidebar-header">
          <div className="sidebar-logo">
            <img 
              src={logoMenu} 
              alt="IFP Logo" 
              className="sidebar-logo-img" 
            />
            <span className="sidebar-logo-title">IFP</span>
          </div>
          <button className="sidebar-toggle desktop-toggle" onClick={onToggle} aria-label="Toggle sidebar">
            {collapsed ? <FiChevronRight size={18} /> : <FiChevronLeft size={18} />}
          </button>
          <button className="sidebar-toggle mobile-close" onClick={onCloseMobile} aria-label="Close sidebar">
            <FiX size={20} />
          </button>
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
                onClick={mobileOpen ? onCloseMobile : undefined}
                title={collapsed ? link.label : undefined}
              >
                <span className="sidebar-link-icon"><Icon size={20} /></span>
                {!collapsed && <span>{link.label}</span>}
              </NavLink>
            );
          })}
        </nav>

        <div className="sidebar-footer">
          <button className="sidebar-logout" onClick={logout}>
            <FiLogOut size={18} />
            {!collapsed && <span>Déconnexion</span>}
          </button>
        </div>
      </div>
    </>
  );
}
