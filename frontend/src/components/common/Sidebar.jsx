import { useState } from 'react';
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
  FiChevronDown,
  FiX,
  FiClock,
  FiBriefcase,
  FiUsers
} from 'react-icons/fi';
import logoMenu from '../../image/logo-menu.png';
import { startPrefetch, cancelPrefetch } from '../../api/prefetch';
import '../../css/sidebar.css';

const adminGroups = [
  {
    label: 'Général',
    items: [
      { to: '/admin/journal',      label: 'Journal',   icon: FiClock },
      { to: '/admin/dashboard',    label: 'Dashboard',  icon: FiGrid },
    ],
  },
  {
    label: 'Gestion',
    items: [
      { to: '/admin/filieres-groupes', label: 'Filières & Groupes', icon: FiHome },
      { to: '/admin/etudiants',    label: 'Étudiants',         icon: FiUserPlus },
      { to: '/admin/annees',       label: 'Années Académiques', icon: FiCalendar },
    ],
  },
  {
    label: 'Pédagogie',
    items: [
      { to: '/admin/unites',       label: 'Unités & Séquences', icon: FiBookOpen },
      { to: '/admin/formateurs',   label: 'Formateurs',         icon: FiUserCheck },
    ],
  },
  {
    label: 'Évaluations',
    items: [
      { to: '/admin/notes',        label: 'Relevé des Notes',   icon: FiFileText },
      { to: '/admin/saisie-notes', label: 'Saisie des Notes',   icon: FiEdit },
      { to: '/admin/scan-examens', label: 'Scan Examens',       icon: FiCamera },
      { to: '/admin/stages',       label: 'Stages',             icon: FiBriefcase },
      { to: '/admin/soutenance',   label: 'Soutenance',         icon: FiUsers },
    ],
  },
  {
    label: 'Documents',
    items: [
      { to: '/admin/bulletins',    label: 'Bulletins',          icon: FiFileText },
      { to: '/admin/publications', label: 'Publications',       icon: FiCheckSquare },
      { to: '/admin/diplomes',     label: 'Diplômes',           icon: FiAward },
    ],
  },
];

const formateurLinks = [
  { to: '/formateur/scanner', label: 'Scanner Notes',         icon: FiCamera },
  { to: '/formateur/notes',   label: 'Mes Unités et Séquences', icon: FiBookOpen },
  { to: '/formateur/liste-notes', label: 'Liste des Notes',   icon: FiEdit },
];

const etudiantLinks = [
  { to: '/etudiant/bulletin', label: 'Mes Notes', icon: FiFileText },
  { to: '/etudiant/mon-bulletin', label: 'Mon Bulletin', icon: FiAward },
];

function SidebarGroup({ group, collapsed, mobileOpen, onCloseMobile }) {
  const [open, setOpen] = useState(true);

  if (collapsed) {
    return (
      <div className="sidebar-group-collapsed">
        {group.items.map(item => {
          const Icon = item.icon;
          return (
            <NavLink
              key={item.to}
              to={item.to}
              className={({ isActive }) => `sidebar-link${isActive ? ' active' : ''}`}
              onClick={mobileOpen ? onCloseMobile : undefined}
              onMouseEnter={() => startPrefetch(item.to)}
              onMouseLeave={() => cancelPrefetch(item.to)}
              title={item.label}
            >
              <span className="sidebar-link-icon"><Icon size={20} /></span>
            </NavLink>
          );
        })}
      </div>
    );
  }

  return (
    <div className="sidebar-group">
      <button className="sidebar-group-header" onClick={() => setOpen(!open)}>
        <span>{group.label}</span>
        <FiChevronDown size={14} className={`sidebar-group-arrow${open ? ' open' : ''}`} />
      </button>
      {open && group.items.map(item => {
        const Icon = item.icon;
        return (
          <NavLink
            key={item.to}
            to={item.to}
            className={({ isActive }) => `sidebar-link${isActive ? ' active' : ''}`}
            onClick={mobileOpen ? onCloseMobile : undefined}
            onMouseEnter={() => startPrefetch(item.to)}
            onMouseLeave={() => cancelPrefetch(item.to)}
          >
            <span className="sidebar-link-icon"><Icon size={20} /></span>
            <span>{item.label}</span>
          </NavLink>
        );
      })}
    </div>
  );
}

export default function Sidebar({ collapsed, mobileOpen, onToggle, onCloseMobile }) {
  const { role, logout } = useAuth();

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
          </div>
          <button className="sidebar-toggle desktop-toggle" onClick={onToggle} aria-label="Toggle sidebar">
            {collapsed ? <FiChevronRight size={18} /> : <FiChevronLeft size={18} />}
          </button>
          <button className="sidebar-toggle mobile-close" onClick={onCloseMobile} aria-label="Close sidebar">
            <FiX size={20} />
          </button>
        </div>

        <nav className="sidebar-nav">
          {role === 'admin' ? (
            adminGroups.map(group => (
              <SidebarGroup
                key={group.label}
                group={group}
                collapsed={collapsed}
                mobileOpen={mobileOpen}
                onCloseMobile={onCloseMobile}
              />
            ))
          ) : (
            (role === 'formateur' ? formateurLinks : etudiantLinks).map(link => {
              const Icon = link.icon;
              return (
                <NavLink
                  key={link.to}
                  to={link.to}
                  className={({ isActive }) => `sidebar-link${isActive ? ' active' : ''}`}
                  onClick={mobileOpen ? onCloseMobile : undefined}
                  onMouseEnter={() => startPrefetch(link.to)}
                  onMouseLeave={() => cancelPrefetch(link.to)}
                  title={collapsed ? link.label : undefined}
                >
                  <span className="sidebar-link-icon"><Icon size={20} /></span>
                  {!collapsed && <span>{link.label}</span>}
                </NavLink>
              );
            })
          )}
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
