import { Outlet } from 'react-router-dom';
import Sidebar from './Sidebar';
import Navbar from './Navbar';
import { useLocation } from 'react-router-dom';
import '../../css/layout.css';

const pageTitles = {
  '/etudiant/bulletin': 'Mes Notes',
  '/etudiant/mon-bulletin': 'Mon Bulletin',
};

export default function EtudiantLayout() {
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
    </div>
  );
}
