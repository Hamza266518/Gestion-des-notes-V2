export default function ExamRow({ exam, mention }) {
  return (
    <div className="exam-row">
      <span className="exam-name">{exam.nom}</span>
      <span className="exam-grade">{Number(exam.valeur).toFixed(2)}/20</span>
      {mention && mention.label && (
        <span className={`mention-badge ${mention.class}`}>{mention.label}</span>
      )}
    </div>
  );
}
