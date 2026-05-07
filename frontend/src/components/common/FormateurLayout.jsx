import { Outlet, useLocation } from 'react-router-dom';
import { useState } from 'react';
import Sidebar from './Sidebar';
import Navbar from './Navbar';
import '../../css/layout.css';

const pageTitles = {
  '/formateur/scanner': 'Scanner de Notes',
  '/formateur/notes': 'Mes Unites et Sequences',
  '/formateur/liste-notes': 'Liste des Notes',
};

export default function FormateurLayout() {
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
