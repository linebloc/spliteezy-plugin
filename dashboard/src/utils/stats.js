/**
 * Two-proportion z-test (two-tailed) to compute statistical significance.
 *
 * Two-tailed because we care whether the variant is different in EITHER direction,
 * not just whether it beats the control. This matches industry tools like CrazyEgg/VWO.
 *
 * Requires at least MIN_VISITORS per arm — below that, the normal approximation
 * isn't valid and showing any number is misleading.
 */
const MIN_VISITORS = 30;

export function computeSignificance(
  controlConversions,
  controlVisitors,
  variantConversions,
  variantVisitors
) {
  if (controlVisitors < 1 || variantVisitors < 1) {
    return { confidence: 0, zScore: 0, isSignificant: false, insufficientData: true };
  }

  const insufficientData = controlVisitors < MIN_VISITORS || variantVisitors < MIN_VISITORS;

  const p1 = controlConversions / controlVisitors;
  const p2 = variantConversions / variantVisitors;
  const pPool =
    (controlConversions + variantConversions) / (controlVisitors + variantVisitors);

  const se = Math.sqrt(pPool * (1 - pPool) * (1 / controlVisitors + 1 / variantVisitors));

  if (se === 0) {
return { confidence: 0, zScore: 0, isSignificant: false, insufficientData };
}

  const zScore = (p2 - p1) / se;

  // Two-tailed confidence: probability that the difference is NOT due to chance.
  // Formula: (2 * Φ(|z|) - 1) * 100
  const confidence = Math.max(0, (2 * normalCDF(Math.abs(zScore)) - 1) * 100);

  return {
    confidence: Math.round(confidence * 10) / 10,
    zScore: Math.round(zScore * 100) / 100,
    isSignificant: !insufficientData && confidence >= 95,
    insufficientData,
  };
}

/** Cumulative normal distribution approximation (Abramowitz and Stegun; valid for z >= 0). */
function normalCDF(z) {
  if (z < 0) {
    return 1 - normalCDF(-z);
  }

  const t = 1 / (1 + 0.2316419 * z);
  const poly =
    t * (0.319381530 +
      t * (-0.356563782 +
        t * (1.781477937 +
          t * (-1.821255978 + t * 1.330274429))));

  return 1 - (1 / Math.sqrt(2 * Math.PI)) * Math.exp(-0.5 * z * z) * poly;
}

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
