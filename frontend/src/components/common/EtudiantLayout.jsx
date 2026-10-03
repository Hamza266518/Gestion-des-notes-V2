import { Outlet, useLocation } from 'react-router-dom';
import { useState } from 'react';
import Sidebar from './Sidebar';
import Navbar from './Navbar';
import '../../css/layout.css';

const pageTitles = {
  // Both student pages carry their own heading inside the card, so the bar above
  // them stays empty rather than repeating the same words a second time.
  '/etudiant/bulletin': '',
  '/etudiant/mon-bulletin': '',
};

export default function EtudiantLayout() {
  const { pathname } = useLocation();
  const title = pageTitles[pathname] ?? 'IFP';
  const [collapsed, setCollapsed] = useState(false);
  const [mobileOpen, setMobileOpen] = useState(false);

  return (
    <div className={`admin-layout${collapsed ? ' sidebar-collapsed-layout' : ''}`}>
      <Sidebar
        collapsed={collapsed}
        mobileOpen={mobileOpen}
        onToggle={() => setCollapsed(p => !p)}
        onCloseMobile={() => setMobileOpen(false)}
      />
      <div className="admin-content">
        <Navbar title={title} onToggleSidebar={() => setMobileOpen(true)} />
        <div className="page-content">
          <Outlet />
        </div>
      </div>
    </div>
  );
}
