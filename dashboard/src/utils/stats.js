/**
 * Presentation helpers only.
 *
 * Significance, confidence and uplift are NEVER computed here — the Laravel app
 * is the single source of truth (`StatisticsService`) and ships them in the test
 * payload. Anything derived locally disagrees with it: the server measures each
 * week separately and combines them, so a rate-vs-rate subtraction here reads a
 * different number from every other figure on the page.
 */

export function conversionRate(conversions, visitors) {
  if (!visitors) {
return 0;
}

  return Math.round((conversions / visitors) * 10000) / 100; // percent, 2dp
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
