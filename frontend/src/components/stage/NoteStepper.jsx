import { useCallback } from 'react';

export default function NoteStepper({ value, onChange, min = 0, max = 20, step = 0.5 }) {
  const numValue = parseFloat(value) || 0;

  const dec = useCallback(() => {
    const next = Math.max(min, +(numValue - step).toFixed(2));
    onChange(next.toString());
  }, [numValue, min, step, onChange]);

  const inc = useCallback(() => {
    const next = Math.min(max, +(numValue + step * 2).toFixed(2));
    onChange(next.toString());
  }, [numValue, max, step, onChange]);

  const stepStr = step % 1 === 0 ? step.toString() : step.toFixed(1);
  const incStr = ((step * 2) % 1 === 0 ? (step * 2) : (step * 2).toFixed(1)).toString();

  return (
    <div className="num-stepper">
      <button type="button" className="num-stepper-btn" onClick={dec} disabled={numValue <= min}>−{stepStr}</button>
      <span className="num-stepper-value">{value || '—'}</span>
      <button type="button" className="num-stepper-btn" onClick={inc} disabled={numValue >= max}>+{incStr}</button>
    </div>
  );
}
