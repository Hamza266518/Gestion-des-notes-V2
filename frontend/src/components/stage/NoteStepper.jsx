import { useCallback } from 'react';

export default function NoteStepper({ value, onChange, min = 0, max = 20, step = 0.5, incStep }) {
  const numValue = parseFloat(value) || 0;
  const plus = incStep ?? step * 2;

  const dec = useCallback(() => {
    const next = Math.max(min, +(numValue - step).toFixed(2));
    onChange(next.toString());
  }, [numValue, min, step, onChange]);

  const inc = useCallback(() => {
    const next = Math.min(max, +(numValue + plus).toFixed(2));
    onChange(next.toString());
  }, [numValue, max, plus, onChange]);

  const fmt = n => (n % 1 === 0 ? n.toString() : n.toFixed(1));
  const display = value === '' || value === null || value === undefined ? '—' : value;

  return (
    <div className="num-stepper">
      <button type="button" className="num-stepper-btn" onClick={dec} disabled={numValue <= min}>−{fmt(step)}</button>
      <span className="num-stepper-value">{display}</span>
      <button type="button" className="num-stepper-btn" onClick={inc} disabled={numValue >= max}>+{fmt(plus)}</button>
    </div>
  );
}
