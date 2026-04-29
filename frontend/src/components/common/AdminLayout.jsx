import { Outlet } from 'react-router-dom';
import Sidebar from './Sidebar';
import Navbar from './Navbar';
import Toast from './Toast';
import { useLocation } from 'react-router-dom';
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
};

export default function AdminLayout() {
  const { pathname } = useLocation();
  const title = pageTitles[pathname] ?? 'IFP';

  return (
    <div className="admin-layout">
      <Sidebar />
      <div className="admin-content">
        <Navbar title={title} />
        <div className="page-content">
          <Outlet />
        </div>
      </div>
      <Toast />
    </div>
  );
}