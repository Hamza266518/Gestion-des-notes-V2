import { useAuth } from '../../context/AuthContext';
import { adminApi } from '../../api/admin';
import { useEffect, useState } from 'react';
import '../../css/navbar.css';

const roleLabels = {
  admin:     'Administrateur',
  formateur: 'Formateur',
  etudiant:  'Etudiant',
};

export default function Navbar({ title }) {
  const { user, role } = useAuth();
  const [annee, setAnnee] = useState(null);

  useEffect(() => {
    adminApi.getAnnees().then(res => {
      const current = res.data.data.find(a => a.is_current);
      setAnnee(current);
    });
  }, []);

  return (
    <div className="navbar">
      <span className="navbar-title">{title}</span>
      <div className="navbar-right">
        {annee && <span className="navbar-year">{annee.label}</span>}
        <span className="navbar-user">{user?.name}</span>
        <span className="navbar-role">{roleLabels[role] ?? role}</span>
      </div>
    </div>
  );
}