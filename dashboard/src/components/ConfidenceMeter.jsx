import { __, sprintf } from '@wordpress/i18n';

/**
 * Chance-to-win meter — the Bayesian probability that a variant beats the
 * control. Green ≥ 90%, amber ≥ 70%, grey below. The marker sits at 50%
 * (even odds). Pass lowData to grey the value out with a "low data" note.
 */
export default function ConfidenceMeter({ value, lowData = false }) {
  // Below the server's learned sample threshold the Beta prior dominates and
  // produces absurd numbers (an arm with fewer visitors "wins"), so show no
  // percentage at all. The exact threshold is per-website and computed
  // server-side (StatisticsService), so this can't state a fixed number.
  if (lowData) {
    return (
      <div
        className="eezy-conf eezy-conf--muted"
        title={__('Not enough data yet to calculate a reliable chance to win', 'spliteezy')}
      >
        <div className="eezy-conf__bar eezy-conf__bar-wrap">
          <span className="eezy-conf__threshold" title={__('Even odds with the control', 'spliteezy')} />
        </div>
        <span className="eezy-conf__label">—</span>
        <span className="eezy-conf__note">{__('low data', 'spliteezy')}</span>
      </div>
    );
  }

  const pct = Math.min(100, Math.max(0, value ?? 0));
  const cls =
    pct >= 90 ? 'eezy-conf--high' :
    pct >= 70 ? 'eezy-conf--mid'  :
                'eezy-conf--low';

  return (
    <div
      className={`eezy-conf ${cls}`}
      title={sprintf(/* translators: %d: chance-to-win percentage. */ __('%d%% chance to beat the control', 'spliteezy'), pct)}
    >
      <div className="eezy-conf__bar eezy-conf__bar-wrap">
        <div className="eezy-conf__fill" style={{ width: `${pct}%` }} />
        <span className="eezy-conf__threshold" title={__('Even odds with the control', 'spliteezy')} />
      </div>
      <span className="eezy-conf__label">~{Math.round(pct)}%</span>
    </div>
  );
}
