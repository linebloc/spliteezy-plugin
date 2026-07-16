import { __ } from '@wordpress/i18n';
import { useState, useEffect } from 'react';

/**
 * Generic hook for WP AJAX requests to the Spliteezy admin endpoints.
 */
export function useApi(config, action, params = {}, deps = []) {
  const [data, setData] = useState(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);
  const [tick, setTick] = useState(0);

  const refresh = () => setTick((t) => t + 1);

  useEffect(() => {
    let cancelled = false;
    // eslint-disable-next-line react-hooks/set-state-in-effect
    setLoading(true);
    setError(null);

    const body = new FormData();
    body.append('action', action);
    body.append('nonce', config.nonce);
    Object.entries(params).forEach(([k, v]) => body.append(k, v));

    fetch(config.ajax_url, { method: 'POST', body, credentials: 'same-origin' })
      .then((r) => r.json())
      .then((json) => {
        if (cancelled) {
          return;
        }

        if (json.success) {
          setData(json.data);
        } else {
          setError(json.data?.message || __('Unknown error', 'spliteezy'));
        }
      })
      .catch((e) => {
        if (!cancelled) {
          setError(e.message);
        }
      })
      .finally(() => {
        if (!cancelled) {
          setLoading(false);
        }
      });

    return () => {
      cancelled = true;
    };
  // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [...deps, tick]);

  return { data, loading, error, refresh };
}
