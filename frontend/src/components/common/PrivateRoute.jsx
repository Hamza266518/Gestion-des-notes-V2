import { Navigate } from 'react-router-dom';
import { useAuth } from '../../context/AuthContext';
import Spinner from './Spinner';

export default function PrivateRoute({ children, role }) {
  const { token, role: userRole, loading } = useAuth();
  
  if (loading) {
    return <Spinner message="Chargement de la session..." />;
  }
  
  if (!token) {
    return <Navigate to="/login" replace />;
  }
  
  if (role && userRole !== role) {
    const homePath = { admin: '/admin/journal', formateur: '/formateur/scanner', etudiant: '/etudiant/bulletin' };
    return <Navigate to={homePath[userRole] ?? '/login'} replace />;
  }
  
  return <>{children}</>;
}