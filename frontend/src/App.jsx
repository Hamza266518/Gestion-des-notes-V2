import { Routes, Route, Navigate } from 'react-router-dom';
import { AuthProvider } from './context/AuthContext';
import { AnneeAcademiqueProvider } from './context/AnneeAcademiqueContext';
import { ToastProvider } from './context/ToastContext';
import PrivateRoute from './components/common/PrivateRoute';
import AdminLayout from './components/common/AdminLayout';
import EtudiantLayout from './components/common/EtudiantLayout';
import FormateurLayout from './components/common/FormateurLayout';

import Login from './pages/auth/Login';
import Dashboard from './pages/admin/Dashboard';
import Journal from './pages/admin/Journal';
import AnneesAcademiques from './pages/admin/AnneesAcademiques';
import FilieresGroupes from './pages/admin/FilieresGroupes';
import Etudiants from './pages/admin/Etudiants';
import Unites from './pages/admin/Unites';
import Formateurs from './pages/admin/Formateurs';
import ScanCin from './pages/admin/ScanCin';
import Diplomes from './pages/admin/Diplomes';
import Notes from './pages/admin/Notes';
import GestionNotes from './pages/admin/GestionNotes';
import Publications from './pages/admin/Publications';
import Bulletins from './pages/admin/Bulletins';
import Sequences from './pages/admin/Sequences';

import MonBulletin from './pages/etudiant/MonBulletin';
import MonBulletinView from './pages/etudiant/MonBulletinView';
import { ChangePassword } from './pages/etudiant/ChangePassword';

import Scanner from './pages/formateur/Scanner';
import NotesList from './pages/formateur/NotesList';
import MesNotesList from './pages/formateur/MesNotesList';

export default function App() {
  return (
    <AuthProvider>
      <AnneeAcademiqueProvider>
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
              <Route index element={<Navigate to="journal" replace />} />
              <Route path="journal" element={<Journal />} />
              <Route path="dashboard" element={<Dashboard />} />
              <Route path="annees" element={<AnneesAcademiques />} />
              <Route path="filieres-groupes" element={<FilieresGroupes />} />
              <Route path="etudiants" element={<Etudiants />} />
              <Route path="unites" element={<Unites />} />
              <Route path="formateurs" element={<Formateurs />} />
              <Route path="scan-cin" element={<ScanCin />} />
              <Route path="diplomes" element={<Diplomes />} />
              <Route path="notes" element={<Notes />} />
              <Route path="saisie-notes" element={<GestionNotes />} />
              <Route path="publications" element={<Publications />} />
              <Route path="bulletins" element={<Bulletins />} />
              <Route path="sequences" element={<Sequences />} />
            </Route>

            <Route
              path="/etudiant"
              element={
                <PrivateRoute role="etudiant">
                  <EtudiantLayout />
                </PrivateRoute>
              }
            >
              <Route index element={<Navigate to="bulletin" replace />} />
              <Route path="bulletin" element={<MonBulletin />} />
              <Route path="mon-bulletin" element={<MonBulletinView />} />
              <Route path="changer-mot-de-passe" element={<ChangePassword />} />
            </Route>

            <Route
              path="/formateur"
              element={
                <PrivateRoute role="formateur">
                  <FormateurLayout />
                </PrivateRoute>
              }
            >
              <Route index element={<Navigate to="scanner" replace />} />
              <Route path="scanner" element={<Scanner />} />
              <Route path="notes" element={<NotesList />} />
              <Route path="liste-notes" element={<MesNotesList />} />
            </Route>

          </Routes>
        </ToastProvider>
      </AnneeAcademiqueProvider>
    </AuthProvider>
  );
}
