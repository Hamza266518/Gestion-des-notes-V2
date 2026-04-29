import React from 'react';
import { useEffect, useState } from 'react';
import { adminApi } from '../../api/admin';
import { unitesApi } from '../../api/unites';
import { notesApi } from '../../api/notes';
import { useToast } from '../../context/ToastContext';
import Spinner from '../../components/common/Spinner';
import Badge from '../../components/common/Badge';
import '../../css/components.css';
import '../../css/layout.css';

const mention = (v) => {
  if (v === null || v === undefined) return null;
  if (v >= 16) return { label: 'Très Bien', color: 'green' };
  if (v >= 14) return { label: 'Bien', color: 'teal' };
  if (v >= 12) return { label: 'Assez Bien', color: 'yellow' };
  if (v >= 10) return { label: 'Passable', color: 'orange' };
  return { label: 'Insuffisant', color: 'red' };
};

export default function Bulletins() {
  const [filieres, setFilieres]   = useState([]);
  const [niveaux, setNiveaux]     = useState([]);
  const [groupes, setGroupes]     = useState([]);
  const [annees, setAnnees]       = useState([]);
  const [etudiants, setEtudiants] = useState([]);
  const [unites, setUnites]       = useState([]);
  const [bulletin, setBulletin]   = useState(null);
  const [loading, setLoading]     = useState(false);
  const [sel, setSel] = useState({
    filiere_id: '', niveau_id: '', annee_id: '',
    groupe_id: '', etudiant_id: '', semestre: '1'
  });
  const toast = useToast();

  const buildBulletin = async () => {
    setLoading(true);
    try {
      const allNotes = await notesApi.getNotes({ groupe_id: sel.groupe_id });
      const notes = allNotes.data.data.filter(n => n.etudiant_id == sel.etudiant_id);

      const unitesData = unites.map(u => {
        const seqsData = u.sequences?.map(s => {
          const controlesData = s.controles?.map(c => {
            const note = notes.find(n => n.controle_id === c.id);
            return { numero: c.numero, valeur: note?.valeur ?? null };
          }) ?? [];

          const vals = controlesData.filter(c => c.valeur !== null).map(c => c.valeur);
          const moySeq = vals.length > 0 ? vals.reduce((a, b) => a + b, 0) / vals.length : null;

          return { ...s, controles: controlesData, moyenne: moySeq };
        }) ?? [];

        const seqsWithMoy = seqsData.filter(s => s.moyenne !== null);
        const moyUnite = seqsWithMoy.length > 0
          ? seqsWithMoy.reduce((acc, s) => acc + s.moyenne * s.coefficient, 0) /
            seqsWithMoy.reduce((acc, s) => acc + s.coefficient, 0)
          : null;

        return { ...u, sequences: seqsData, moyenne: moyUnite };
      });

      const unitesWithMoy = unitesData.filter(u => u.moyenne !== null);
      const moyGen = unitesWithMoy.length > 0
        ? unitesWithMoy.reduce((acc, u) => acc + u.moyenne * u.coefficient, 0) /
          unitesWithMoy.reduce((acc, u) => acc + u.coefficient, 0)
        : null;

      setBulletin({ unites: unitesData, moyenne_generale: moyGen });
    } catch {
      toast.error('Erreur de chargement du bulletin');
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    if (sel.etudiant_id && unites.length > 0) {
      buildBulletin();
    }
  }, [sel.etudiant_id, unites]);

  const etudiant = etudiants.find(e => e.id == sel.etudiant_id);

  return (
    <div className="page">
      <div className="page-header">
        <h2 className="page-title">Bulletins de Notes</h2>
      </div>

      <div className="filter-bar" style={{ flexWrap: 'wrap' }}>
        <select className="form-select" value={sel.filiere_id} onChange={e => setSel(p => ({ ...p, filiere_id: e.target.value, niveau_id: '', groupe_id: '', etudiant_id: '' }))}>
          <option value="">Filière</option>
          {filieres.map(f => <option key={f.id} value={f.id}>{f.nom}</option>)}
        </select>
        <select className="form-select" value={sel.annee_id} onChange={e => setSel(p => ({ ...p, annee_id: e.target.value }))}>
          <option value="">Année</option>
          {annees.map(a => <option key={a.id} value={a.id}>{a.label}</option>)}
        </select>
        <select className="form-select" value={sel.niveau_id} onChange={e => setSel(p => ({ ...p, niveau_id: e.target.value, groupe_id: '', etudiant_id: '' }))} disabled={!sel.filiere_id}>
          <option value="">Niveau</option>
          {niveaux.map(n => <option key={n.id} value={n.id}>{n.numero}ère année</option>)}
        </select>
        <select className="form-select" value={sel.groupe_id} onChange={e => setSel(p => ({ ...p, groupe_id: e.target.value, etudiant_id: '' }))} disabled={!sel.niveau_id}>
          <option value="">Groupe</option>
          {groupes.map(g => <option key={g.id} value={g.id}>{g.nom}</option>)}
        </select>
        <select className="form-select" value={sel.semestre} onChange={e => setSel(p => ({ ...p, semestre: e.target.value }))}>
          <option value="1">Semestre 1</option>
          <option value="2">Semestre 2</option>
        </select>
        <select className="form-select" value={sel.etudiant_id} onChange={e => setSel(p => ({ ...p, etudiant_id: e.target.value }))} disabled={!sel.groupe_id}>
          <option value="">Stagiaire</option>
          {etudiants.map(e => <option key={e.id} value={e.id}>{e.nom_prenom}</option>)}
        </select>
      </div>

      {!sel.etudiant_id ? (
        <div className="card card-body" style={{ textAlign: 'center', color: 'var(--gray-400)' }}>
          Sélectionnez un étudiant pour voir son bulletin
        </div>
      ) : loading ? <Spinner /> : bulletin && (
        <div className="card">
          <div className="card-body" style={{ borderBottom: '1px solid var(--gray-200)' }}>
            <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'flex-start' }}>
              <div>
                <div style={{ fontSize: 18, fontWeight: 700 }}>{etudiant?.nom_prenom}</div>
                <div style={{ fontSize: 13, color: 'var(--gray-500)', marginTop: 4 }}>
                  CIN: {etudiant?.cin} — N°: {etudiant?.numero_inscription}
                </div>
                <div style={{ fontSize: 13, color: 'var(--gray-500)' }}>
                  Groupe: {etudiant?.groupe?.nom} — Filière: {etudiant?.groupe?.niveau?.filiere?.nom}
                </div>
              </div>
              <div style={{ textAlign: 'right' }}>
                <div style={{ fontSize: 13, color: 'var(--gray-500)' }}>Semestre {sel.semestre}</div>
                {bulletin.moyenne_generale !== null && (
                  <>
                    <div style={{ fontSize: 22, fontWeight: 700, color: 'var(--primary)' }}>
                      {bulletin.moyenne_generale.toFixed(2)}/20
                    </div>
                    {mention(bulletin.moyenne_generale) && (
                      <Badge label={mention(bulletin.moyenne_generale).label} color={mention(bulletin.moyenne_generale).color} />
                    )}
                  </>
                )}
              </div>
            </div>
          </div>

          <div style={{ overflowX: 'auto' }}>
            <table>
              <thead>
                <tr>
                  <th style={{ minWidth: 200 }}>Unité / Séquence</th>
                  <th>C1</th>
                  <th>C2</th>
                  <th>C3</th>
                  <th>Moy. Séquence</th>
                  <th>Moy. Unité</th>
                </tr>
              </thead>
              <tbody>
                {bulletin.unites.map(u => (
                  <React.Fragment key={u.id}>
                    {u.sequences?.map((s, si) => (
                      <tr key={s.id}>
                        <td style={{ paddingLeft: si === 0 ? 20 : 36 }}>
                          {si === 0 && <div style={{ fontSize: 11, fontWeight: 600, color: 'var(--gray-500)', marginBottom: 2 }}>{u.nom}</div>}
                          <span style={{ fontSize: 13 }}>↳ {s.nom}</span>
                        </td>
                        {[0, 1, 2].map(ci => (
                          <td key={ci} style={{ textAlign: 'center' }}>
                            {s.controles?.[ci]?.valeur ?? '—'}
                          </td>
                        ))}
                        <td style={{ textAlign: 'center', fontWeight: 500 }}>
                          {s.moyenne !== null ? s.moyenne.toFixed(2) : '—'}
                        </td>
                        {si === 0 && (
                          <td
                            rowSpan={u.sequences.length}
                            style={{ textAlign: 'center', fontWeight: 700, verticalAlign: 'middle', color: 'var(--primary)' }}
                          >
                            {u.moyenne !== null ? u.moyenne.toFixed(2) : '—'}
                          </td>
                        )}
                      </tr>
                    ))}
                  </React.Fragment>
                ))}
                <tr style={{ background: 'var(--gray-50)', fontWeight: 700 }}>
                  <td colSpan={5} style={{ textAlign: 'right', paddingRight: 16 }}>
                    Moyenne Générale S{sel.semestre}
                  </td>
                  <td style={{ textAlign: 'center', color: 'var(--primary)', fontSize: 16 }}>
                    {bulletin.moyenne_generale !== null ? bulletin.moyenne_generale.toFixed(2) : '—'}/20
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>
      )}
    </div>
  );
}