import { Outlet, useLocation } from 'react-router-dom';
import { useState } from 'react';
import Sidebar from './Sidebar';
import Navbar from './Navbar';
import '../../css/layout.css';
import '../../css/variables.css';

const pageTitles = {
  '/admin/dashboard':    'Dashboard',
  '/admin/annees':       'Années Académiques',
  '/admin/filieres-groupes': 'Filières & Groupes',
  '/admin/etudiants':    'Etudiants',
  '/admin/scan-cin':     'Scan CIN',
  '/admin/unites':       'Unités & Séquences',
  '/admin/formateurs':   'Formateurs',
  '/admin/notes':        'Notes',
  '/admin/bulletins':    'Bulletins',
  '/admin/publications': 'Publications',
  '/admin/diplomes':     'Diplômes',
  '/admin/saisie-notes': 'Saisie des Notes',
};

export default function AdminLayout() {
  const { pathname } = useLocation();
  const title = pageTitles[pathname] ?? 'IFP';
  const [collapsed, setCollapsed] = useState(false);
  const [mobileOpen, setMobileOpen] = useState(false);

  return (
    <div className="admin-layout">
      <Sidebar
        collapsed={collapsed}
        mobileOpen={mobileOpen}
        onToggle={() => setCollapsed(p => !p)}
        onCloseMobile={() => setMobileOpen(false)}
      />
      <div className={`admin-content${collapsed ? ' sidebar-collapsed' : ''}`}>
        <Navbar title={title} collapsed={collapsed} onToggleSidebar={() => setMobileOpen(true)} />
        <div className="page-content">
          <Outlet />
        </div>
      </div>
    </div>
  );
}
