import { Navigate } from 'react-router-dom';
import { useAuth } from '../../context/AuthContext';
import Spinner from './Spinner';

export default function PrivateRoute({ children, role }) {
  const { token, role: userRole, loading } = useAuth();
  if (loading) return <Spinner />;
  if (!token) return <Navigate to="/login" replace />;
  if (role && userRole !== role) return <Navigate to="/login" replace />;
  return children;
}