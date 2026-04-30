import { Outlet } from 'react-router-dom';
import Sidebar from './Sidebar';
import Navbar from './Navbar';
import Toast from './Toast';
import { useLocation } from 'react-router-dom';
import '../../css/layout.css';

const pageTitles = {
  '/formateur/scanner': 'Scanner de Notes',
  '/formateur/notes': 'Liste des Notes',
};

export default function FormateurLayout() {
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
