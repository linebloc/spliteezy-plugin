import { __, sprintf } from '@wordpress/i18n';
import { useState, useEffect } from 'react';
import { useApi } from '../hooks/useApi.js';
import DropdownMenu from '../components/DropdownMenu.jsx';
import { computeSignificance, formatDate } from '../utils/stats.js';

const TABS = ['active', 'paused', 'scheduled', 'draft', 'ended'];

const TAB_LABELS = {
  active:    () => __('Active', 'spliteezy'),
  paused:    () => __('Paused', 'spliteezy'),
  scheduled: () => __('Scheduled', 'spliteezy'),
  draft:     () => __('Draft', 'spliteezy'),
  ended:     () => __('Ended', 'spliteezy'),
};

export default function TestList({ config, onOpenTest, onNewTest, onError, onPlanChange }) {
  const [tab, setTab] = useState('active');
  const [flushing, setFlushing] = useState(false);
  const [actionNotice, setActionNotice] = useState(null);
  const { data, loading, error, refresh } = useApi(config, 'spliteezy_get_tests', {}, []);

  // Bubble connection errors up to the app-level banner.
  useEffect(() => { onError?.(error ?? null); }, [error]);

  // Keep the app-level plan snapshot current with each fetch.
  useEffect(() => { if (data?.plan) { onPlanChange?.(data.plan); } }, [data?.plan]);

  function handleRefresh() {
    setFlushing(true);
    const body = new FormData();
    body.append('action', 'spliteezy_flush_manifest');
    body.append('nonce', config.nonce);
    fetch(config.ajax_url, { method: 'POST', body, credentials: 'same-origin' })
      .finally(() => {
        setFlushing(false);
        refresh();
      });
  }

  const tests = data?.tests ?? [];
  const plan  = data?.plan ?? null;

  const filtered = tests.filter((t) => {
    if (tab === 'ended') return ['ended', 'winner'].includes(t.status);
    return t.status === tab;
  });

  return (
    <div className="eezy-wrap">
      <div className="eezy-header">
        <div className="eezy-logo">
          <svg className="eezy-logo__icon" width="26" height="26" viewBox="0 0 48 48" fill="none" xmlns="http://www.w3.org/2000/svg">
            <defs>
              <linearGradient id="ezd-bg" x1="4" y1="4" x2="44" y2="44" gradientUnits="userSpaceOnUse">
                <stop offset="0" stopColor="#4335d8"/>
                <stop offset="0.55" stopColor="#5b4cf5"/>
                <stop offset="1" stopColor="#b04fe0"/>
              </linearGradient>
              <linearGradient id="ezd-win" x1="0" y1="0" x2="1" y2="1">
                <stop offset="0" stopColor="#22b8d6"/>
                <stop offset="1" stopColor="#3ec98e"/>
              </linearGradient>
            </defs>
            <rect x="4" y="4" width="40" height="40" rx="11" fill="url(#ezd-bg)"/>
            <path d="M24 34.5 V29 C24 23.5 15.5 25 15.5 19.5 M24 29 C24 23.5 32.5 25 32.5 19.5" stroke="#ffffff" strokeOpacity="0.55" strokeWidth="2.2" strokeLinecap="round" fill="none"/>
            <circle cx="24" cy="37" r="3" fill="#ffffff"/>
            <rect x="10.5" y="10" width="10" height="9" rx="2.6" fill="#ffffff" fillOpacity="0.5"/>
            <rect x="27.5" y="10" width="10" height="9" rx="2.6" fill="#ffffff"/>
            <circle cx="37.2" cy="10.4" r="3.4" fill="url(#ezd-win)"/>
            <path d="M35.7 10.4 l1 1.1 2 -2.1" stroke="#ffffff" strokeWidth="1.1" fill="none" strokeLinecap="round" strokeLinejoin="round"/>
          </svg>
          <span className="eezy-logo__name">Split<span style={{color:'var(--eezy-primary)'}}>eezy</span></span>
        </div>
        <span className="eezy-connected-badge">
          <span className="eezy-connected-badge__dot" />
          {__('Connected', 'spliteezy')}
        </span>
        <a
          href={`${config.settings_url}`}
          className="eezy-btn eezy-btn--ghost eezy-btn--sm"
          style={{ marginLeft: 'auto' }}
        >
          {__('Settings', 'spliteezy')}
        </a>
      </div>

      {plan && <PlanUsage plan={plan} tests={tests} />}

      <div className="eezy-section-header">
        <h2 className="eezy-section-title">{__('A/B Tests', 'spliteezy')}</h2>
        <div style={{ display: 'flex', gap: 8 }}>
          <button
            className="eezy-btn eezy-btn--ghost"
            onClick={handleRefresh}
            disabled={flushing || loading}
            title={__('Flush cache and reload tests', 'spliteezy')}
          >
            {flushing ? __('Refreshing…', 'spliteezy') : __('↻ Refresh', 'spliteezy')}
          </button>
          <button className="eezy-btn eezy-btn--primary" onClick={onNewTest}>
            {__('+ New Test', 'spliteezy')}
          </button>
        </div>
      </div>

      <div className="eezy-tabs">
        {TABS.map((t) => (
          <button
            key={t}
            className={`eezy-tab ${tab === t ? 'eezy-tab--active' : ''}`}
            onClick={() => setTab(t)}
          >
            {TAB_LABELS[t]()}
            {data && (
              <span className="eezy-tab__count">
                {tests.filter((x) => (t === 'ended' ? ['ended', 'winner'].includes(x.status) : x.status === t)).length}
              </span>
            )}
          </button>
        ))}
      </div>

      {loading && (
        <div className="eezy-loading">
          <div className="eezy-spinner" />
          <span>{__('Loading tests…', 'spliteezy')}</span>
        </div>
      )}

      {error && (
        <div className="eezy-notice eezy-notice--error">
          <strong>{__('Could not reach Spliteezy API.', 'spliteezy')}</strong>
          <span style={{ display: 'block', marginTop: 4, fontSize: 12 }}>
            {error} — <a href={config.settings_url}>{__('Check your connection in Settings', 'spliteezy')}</a>.
          </span>
        </div>
      )}

      {actionNotice && (
        <div className="eezy-notice eezy-notice--warning" style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'flex-start', gap: 12, marginBottom: 16 }}>
          <span>{actionNotice}</span>
          <button
            type="button"
            onClick={() => setActionNotice(null)}
            aria-label={__('Dismiss', 'spliteezy')}
            style={{ background: 'none', border: 'none', cursor: 'pointer', fontSize: 16, lineHeight: 1, padding: 0, color: 'inherit' }}
          >
            ×
          </button>
        </div>
      )}

      {!loading && !error && filtered.length === 0 && (
        <div className="eezy-empty-state eezy-empty-state--inline">
          <p>{sprintf(/* translators: %s: test status tab name. */ __('No %s tests found.', 'spliteezy'), TAB_LABELS[tab]().toLowerCase())}</p>
        </div>
      )}

      {!loading && !error && filtered.length > 0 && (
        <div className="eezy-table-wrapper">
          <table className="eezy-table">
            <thead>
              <tr>
                <th>{__('Test', 'spliteezy')}</th>
                <th>{__('Goal', 'spliteezy')}</th>
                <th>{__('Split', 'spliteezy')}</th>
                <th>{__('Visitors', 'spliteezy')}</th>
                <th>{__('Started', 'spliteezy')}</th>
                <th>{__('Confidence', 'spliteezy')}</th>
                <th style={{ width: 36 }}></th>
              </tr>
            </thead>
            <tbody>
              {filtered.map((test) => (
                <TestRow
                  key={test.id}
                  test={test}
                  onOpen={onOpenTest}
                  config={config}
                  onRefresh={refresh}
                  onNavigate={onOpenTest}
                  onActionError={setActionNotice}
                />
              ))}
            </tbody>
          </table>
        </div>
      )}
    </div>
  );
}

const VARIANT_COLORS = ['#6B7280', '#5B4CF5', '#B8862F', '#C2554A', '#5E6AD2'];

function TestRow({ test, onOpen, config, onRefresh, onNavigate, onActionError }) {
  const [actioning, setActioning] = useState(false);

  const variants    = test.variants ?? [];
  const goals       = test.goals ?? [];
  const control     = variants.find((v) => v.is_control) ?? variants[0];
  const challengers = variants.filter((v) => !v.is_control);

  const primaryGoal = goals.find((g) => g.is_primary) ?? goals[0] ?? null;
  // Type-based label first: the API label is generated in English.
  const goalLabel = primaryGoal
    ? (GOAL_TYPE_LABELS[primaryGoal.type]?.() || primaryGoal.label || primaryGoal.type)
    : '—';

  const totalVisitors = variants.reduce((sum, v) => sum + (v.visitors ?? 0), 0);

  const bestConfidence = challengers.reduce((best, v) => {
    const { confidence } = computeSignificance(
      control?.conversions ?? 0, control?.visitors ?? 0,
      v.conversions ?? 0, v.visitors ?? 0
    );
    return confidence > best ? confidence : best;
  }, 0);

  const confCls = bestConfidence >= 95 ? 'high' : bestConfidence >= 80 ? 'mid' : 'low';
  const hasData = totalVisitors > 0;

  async function doAction(action) {
    if (actioning) return;
    if (action === 'delete' && !window.confirm(sprintf(/* translators: %s: test name. */ __('Delete "%s"? This cannot be undone.', 'spliteezy'), test.name))) return;

    setActioning(true);
    try {
      const body = new FormData();
      body.append('action', 'spliteezy_test_action');
      body.append('nonce', config.nonce);
      body.append('test_id', test.id);
      body.append('test_action', action);
      const res  = await fetch(config.ajax_url, { method: 'POST', body, credentials: 'same-origin' });
      const json = await res.json();
      if (json.success) {
        onActionError?.(null);
        if (action === 'clone' && json.data?.test_id) {
          onNavigate?.(json.data.test_id);
        } else {
          onRefresh?.();
        }
      } else {
        onActionError?.(json.data?.message || (typeof json.data === 'string' ? json.data : __('Action failed.', 'spliteezy')));
      }
    } catch (_) {
      onActionError?.(__('Network error — please try again.', 'spliteezy'));
    }
    setActioning(false);
  }

  const { status } = test;
  const menuItems = [
    ...(status === 'draft' ? [{ label: __('Start', 'spliteezy'), onClick: () => doAction('start') }] : []),
    ...(status === 'scheduled' ? [{ label: __('Start Now', 'spliteezy'), onClick: () => doAction('start') }] : []),
    ...(status === 'active' ? [
      { label: __('Pause', 'spliteezy'),  onClick: () => doAction('pause')  },
      { label: __('Finish', 'spliteezy'), onClick: () => doAction('finish') },
      { label: __('Stop', 'spliteezy'),   onClick: () => doAction('stop')   },
    ] : []),
    ...(status === 'paused' ? [
      { label: __('Resume', 'spliteezy'), onClick: () => doAction('resume') },
      { label: __('Finish', 'spliteezy'), onClick: () => doAction('finish') },
      { label: __('Stop', 'spliteezy'),   onClick: () => doAction('stop')   },
    ] : []),
    { label: __('Clone', 'spliteezy'), onClick: () => doAction('clone') },
    'separator',
    { label: __('Delete', 'spliteezy'), onClick: () => doAction('delete'), danger: true },
  ];

  return (
    <tr
      className={`eezy-table__row eezy-table__row--clickable${actioning ? ' eezy-table__row--loading' : ''}`}
      onClick={() => onOpen(test.id)}
    >
      <td>
        <span className="eezy-test-name">{test.name}</span>
        {test.target_url && (
          <span className="eezy-test-url">{(() => { try { return new URL(test.target_url).pathname; } catch { return test.target_url; } })()}</span>
        )}
      </td>
      <td><span className="eezy-goal-label">{goalLabel}</span></td>
      <td>
        <div className="eezy-split-bars">
          {variants.map((v, i) => (
            <div
              key={v.id ?? i}
              className="eezy-split-bars__seg"
              style={{ flex: v.weight ?? 50, background: VARIANT_COLORS[i % VARIANT_COLORS.length] }}
            />
          ))}
        </div>
        <span className="eezy-muted" style={{ fontSize: 11, marginTop: 3, display: 'block' }}>
          {variants.map((v) => `${v.weight}%`).join(' / ')}
        </span>
      </td>
      <td style={{ fontVariantNumeric: 'tabular-nums' }}>
        {hasData ? totalVisitors.toLocaleString() : <span className="eezy-muted">—</span>}
      </td>
      <td style={{ fontSize: 12, color: 'var(--eezy-text-muted)' }}>{formatDate(test.started_at)}</td>
      <td>
        {challengers.length === 0 ? (
          <span className="eezy-muted" style={{ fontSize: 11 }}>—</span>
        ) : (
          <div className="eezy-conf-wrap">
            <div className="eezy-conf-track">
              <div
                className={`eezy-conf-track__fill eezy-conf-track__fill--${confCls}`}
                style={{ width: `${Math.min(100, bestConfidence)}%` }}
              />
              <span className="eezy-conf-track__marker" />
            </div>
            <span className={`eezy-conf-pct eezy-conf-pct--${confCls}`}>
              {hasData ? `${Math.round(bestConfidence)}%` : '—'}
            </span>
          </div>
        )}
      </td>
      <td onClick={(e) => e.stopPropagation()} style={{ width: 36 }}>
        <DropdownMenu items={menuItems} />
      </td>
    </tr>
  );
}

const GOAL_TYPE_LABELS = {
  page_view:      () => __('Page view', 'spliteezy'),
  page_reached:   () => __('Page reached', 'spliteezy'),
  click:          () => __('Click', 'spliteezy'),
  scroll_depth:   () => __('Scroll depth', 'spliteezy'),
  time_on_page:   () => __('Time on page', 'spliteezy'),
  element_view:   () => __('Element view', 'spliteezy'),
  video_play:     () => __('Video play', 'spliteezy'),
  external_event: () => __('External event', 'spliteezy'),
  engagement:     () => __('Engagement', 'spliteezy'),
};

function formatEndMode(mode, value) {
  if (!mode || mode === 'manual') return __('Manual', 'spliteezy');
  if (mode === 'confidence') return sprintf(/* translators: %d: confidence percentage. */ __('%d%% confidence', 'spliteezy'), value ?? 95);
  if (mode === 'page_views') return sprintf(/* translators: %s: number of page views. */ __('%s views', 'spliteezy'), Number(value ?? 0).toLocaleString());
  if (mode === 'datetime') return formatDate(value);
  return mode;
}

function PlanUsage({ plan, tests }) {
  const visitorPct = plan.visitors_limit > 0
    ? Math.min(100, Math.round((plan.visitors_used / plan.visitors_limit) * 100))
    : null;
  const testPct = plan.tests_limit > 0
    ? Math.min(100, Math.round((plan.tests_used / plan.tests_limit) * 100))
    : null;

  const totalConversions = tests.reduce(
    (sum, t) => sum + (t.variants ?? []).reduce((s, v) => s + (v.conversions ?? 0), 0),
    0
  );

  const visitorSub = plan.visitors_limit > 0
    ? sprintf(/* translators: %s: monthly visitor limit. */ __('of %s / mo', 'spliteezy'), plan.visitors_limit.toLocaleString())
    : __('Unlimited', 'spliteezy');

  const testSub = plan.tests_limit > 0
    ? sprintf(/* translators: 1: active test limit, 2: plan name. */ __('of %1$s on %2$s', 'spliteezy'), plan.tests_limit, plan.name)
    : sprintf(/* translators: %s: plan name. */ __('%s plan', 'spliteezy'), plan.name);

  return (
    <div className="eezy-stat-grid">
      <StatCard
        label={__('Visitors this month', 'spliteezy')}
        value={plan.visitors_used.toLocaleString()}
        sub={visitorSub}
        pct={visitorPct}
        icon={<UsersIcon />}
      />
      <StatCard
        label={__('Conversions', 'spliteezy')}
        value={totalConversions.toLocaleString()}
        sub={__('across all tests', 'spliteezy')}
        pct={null}
        icon={<TargetIcon />}
      />
      <StatCard
        label={__('Active tests', 'spliteezy')}
        value={plan.tests_used}
        sub={testSub}
        pct={testPct}
        icon={<FlaskIcon />}
      />
    </div>
  );
}

function StatCard({ label, value, sub, pct, icon }) {
  const fillCls = pct >= 90 ? ' eezy-stat-card__fill--danger' : pct >= 70 ? ' eezy-stat-card__fill--warn' : '';
  return (
    <div className="eezy-stat-card">
      <div className="eezy-stat-card__header">
        <p className="eezy-stat-card__label">{label}</p>
        <div className="eezy-stat-card__icon-wrap">{icon}</div>
      </div>
      <p className="eezy-stat-card__value">{value}</p>
      {sub && <p className="eezy-stat-card__sub">{sub}</p>}
      {pct !== null && (
        <div className="eezy-stat-card__track">
          <div className={`eezy-stat-card__fill${fillCls}`} style={{ width: `${pct}%` }} />
        </div>
      )}
    </div>
  );
}

function UsersIcon() {
  return (
    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
      <path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/>
      <circle cx="9" cy="7" r="4"/>
      <path d="M22 21v-2a4 4 0 0 0-3-3.87"/>
      <path d="M16 3.13a4 4 0 0 1 0 7.75"/>
    </svg>
  );
}

function FlaskIcon() {
  return (
    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
      <path d="M9 3h6"/>
      <path d="M10 3v7l-4.5 7.5A2 2 0 0 0 7.24 21h9.52a2 2 0 0 0 1.74-3L14 10V3"/>
      <path d="M7.5 14.5h9"/>
    </svg>
  );
}

function TargetIcon() {
  return (
    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
      <circle cx="12" cy="12" r="10"/>
      <circle cx="12" cy="12" r="6"/>
      <circle cx="12" cy="12" r="2"/>
    </svg>
  );
}
