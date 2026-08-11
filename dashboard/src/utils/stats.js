/**
 * Presentation helpers only.
 *
 * Significance/confidence is NEVER computed here — the Laravel app is the single
 * source of truth (`StatisticsService`), and it ships the figure in the test
 * payload. A local z-test would disagree with it: the server also gates on
 * learned per-website traffic patterns, which the plugin has no way to know.
 */

export function conversionRate(conversions, visitors) {
  if (!visitors) {
return 0;
}

  return Math.round((conversions / visitors) * 10000) / 100; // percent, 2dp
}

/** Relative uplift of variant vs control as a percentage. */
export function relativeUplift(variantRate, controlRate) {
  if (!controlRate) {
return null;
}

  return Math.round(((variantRate - controlRate) / controlRate) * 1000) / 10; // 1dp
}

export function formatDate(isoString) {
  if (!isoString) {
return '—';
}

  return new Date(isoString).toLocaleDateString(undefined, {
    year: 'numeric',
    month: 'short',
    day: 'numeric',
  });
}

export function daysRunning(startDate) {
  if (!startDate) {
return 0;
}

  const ms = Date.now() - new Date(startDate).getTime();

  return Math.max(0, Math.floor(ms / 86400000));
}
