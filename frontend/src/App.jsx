import { Routes, Route, Navigate } from 'react-router-dom';
import { AuthProvider } from './context/AuthContext';
import { ToastProvider } from './context/ToastContext';
import PrivateRoute from './components/common/PrivateRoute';
import AdminLayout from './components/common/AdminLayout';

import Login from './pages/auth/Login';
import Dashboard from './pages/admin/Dashboard';
import AnneesAcademiques from './pages/admin/AnneesAcademiques';
import FilieresGroupes from './pages/admin/FilieresGroupes';
import Etudiants from './pages/admin/Etudiants';
import Unites from './pages/admin/Unites';
import ScanCin from './pages/admin/ScanCin';

import './css/variables.css';

export default function App() {
  return (
    <AuthProvider>
      <ToastProvider>
        <Routes>
          <Route path="/login" element={<Login />} />
          <Route path="/" element={<Navigate to="/login" replace />} />

          <Route
            path="/admin"
            element={
              <PrivateRoute role="admin">
                <AdminLayout />
              </PrivateRoute>
            }
          >
            <Route index element={<Navigate to="dashboard" replace />} />
            <Route path="dashboard" element={<Dashboard />} />
            <Route path="annees" element={<AnneesAcademiques />} />
            <Route path="filieres-groupes" element={<FilieresGroupes />} />
            <Route path="etudiants" element={<Etudiants />} />
            <Route path="unites" element={<Unites />} />
            <Route path="scan-cin" element={<ScanCin />} />
          </Route>
        </Routes>
      </ToastProvider>
    </AuthProvider>
  );
}