import { __, sprintf } from '@wordpress/i18n';

/**
 * Visual confidence meter. Shows a coloured bar + percentage.
 * Green ≥ 95%, amber ≥ 80%, red < 80%.
 * Pass lowData to show the value greyed out with a "low data" note.
 */
export default function ConfidenceMeter({ value, lowData = false }) {
  const pct = Math.min(100, Math.max(0, value ?? 0));
  const cls = lowData ? 'eezy-conf--muted' :
    pct >= 95 ? 'eezy-conf--high' :
    pct >= 80 ? 'eezy-conf--mid'  :
                'eezy-conf--low';

  return (
    <div
      className={`eezy-conf ${cls}`}
      title={lowData
        ? sprintf(/* translators: %d: confidence percentage. */ __('~%d%% confidence (need 30+ visitors per variant for reliable results)', 'spliteezy'), pct)
        : sprintf(/* translators: %d: confidence percentage. */ __('%d%% confidence', 'spliteezy'), pct)}
    >
      <div className="eezy-conf__bar eezy-conf__bar-wrap">
        <div className="eezy-conf__fill" style={{ width: `${pct}%` }} />
        <span className="eezy-conf__threshold" title={__('95% threshold', 'spliteezy')} />
      </div>
      <span className="eezy-conf__label">~{pct}%</span>
      {lowData && <span className="eezy-conf__note">{__('low data', 'spliteezy')}</span>}
    </div>
  );
}
