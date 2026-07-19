import { __, sprintf } from '@wordpress/i18n';
import { useEffect, useMemo, useRef, useState } from 'react';
import { useApi } from '../hooks/useApi.js';
import StatusBadge from '../components/StatusBadge.jsx';
import ConfidenceMeter from '../components/ConfidenceMeter.jsx';
import DropdownMenu from '../components/DropdownMenu.jsx';
import TimeSeriesChart from '../components/TimeSeriesChart.jsx';
import InfoTip from '../components/InfoTip.jsx';
import { conversionRate, relativeUplift, formatDate, daysRunning } from '../utils/stats.js';

const VARIANT_COLORS = ['#6B7280', '#5B4CF5', '#B8862F', '#C2554A', '#5E6AD2'];

const GOAL_LABELS = {
  page_view:      () => __('Page view', 'spliteezy'),
  page_reached:   () => __('Page reached', 'spliteezy'),
  click:          () => __('Click', 'spliteezy'),
  scroll_depth:   () => __('Scroll depth', 'spliteezy'),
  time_on_page:   () => __('Time on page', 'spliteezy'),
  element_view:   () => __('Element view', 'spliteezy'),
  video_play:     () => __('Video playback', 'spliteezy'),
  external_event: () => __('External event', 'spliteezy'),
  engagement:     () => __('Engagement', 'spliteezy'),
};

function endLabel(mode, value, threshold) {
  if (!mode || mode === 'manual') return __('No end date', 'spliteezy');
  if (mode === 'confidence') return sprintf(/* translators: %d: confidence percentage. */ __('At %d%% confidence', 'spliteezy'), value ?? threshold ?? 95);
  if (mode === 'page_views') return sprintf(/* translators: %s: number of page views. */ __('After %s views', 'spliteezy'), Number(value ?? 0).toLocaleString());
  if (mode === 'datetime') return sprintf(/* translators: %s: date. */ __('On %s', 'spliteezy'), formatDate(value));
  return mode;
}

// Type-based label first: the API label is generated in English.
function goalLabel(g) {
  return GOAL_LABELS[g?.type]?.() || g?.label || g?.type || '—';
}

// API variant labels are generated in English; translate the known shapes.
function variantName(v) {
  if (!v) return '';
  const m = v.label?.match(/^Variant ([A-Z])$/);
  if (m) return sprintf(/* translators: %s: variant letter. */ __('Variant %s', 'spliteezy'), m[1]);
  if (v.is_control && (!v.label || v.label === 'Control')) return __('Control', 'spliteezy');
  return v.label || (v.is_control ? __('Control', 'spliteezy') : __('Variant', 'spliteezy'));
}

function goalDetail(g) {
  if (!g) return null;
  if (g.percent)    return sprintf(/* translators: %d: scroll percentage. */ __('%d%% scroll depth', 'spliteezy'), g.percent);
  if (g.seconds)    return sprintf(/* translators: %d: seconds. */ __('%ds on page', 'spliteezy'), g.seconds);
  if (g.event_name) return g.event_name;
  if (g.selector)   return g.selector;
  if (g.url) {
    try { return new URL(g.url).pathname; } catch { return g.url; }
  }
  return null;
}

function buildTimeline(test) {
  const synthetic = [];
  if (test.created_at) synthetic.push({ event: 'test_created',   data: {}, created_at: test.created_at });
  if (test.started_at) synthetic.push({ event: 'test_activated', data: {}, created_at: test.started_at });
  if (test.ended_at)   synthetic.push({ event: 'test_ended',     data: {}, created_at: test.ended_at });
  return [...synthetic, ...(test.history ?? [])].sort(
    (a, b) => new Date(b.created_at) - new Date(a.created_at)
  );
}

function SortHeader({ label, field, sortKey, sortDir, onSort, align = 'left' }) {
  const active = sortKey === field;
  return (
    <button
      onClick={() => onSort(field)}
      className={`eezy-sort-header ${align === 'right' ? 'eezy-sort-header--right' : ''}`}
    >
      {label}
      <span className={`eezy-sort-header__icon ${active ? 'eezy-sort-header__icon--active' : ''}`}>
        {active ? (sortDir === 'desc' ? '▼' : '▲') : '⇅'}
      </span>
    </button>
  );
}

export default function TestDetail({ config, testId, onBack, onError, onOpenTest }) {
  const { data: test, loading, error, refresh } = useApi(
    config,
    'spliteezy_get_test',
    { test_id: testId },
    [testId]
  );

  const [sortKey, setSortKey]         = useState(null);
  const [sortDir, setSortDir]         = useState('desc');
  const [actioning, setActioning]     = useState(false);
  const [actionError, setActionError] = useState(null);
  const [chartMetric, setChartMetric] = useState('rate');

  // Inline rename
  const [editingName, setEditingName] = useState(false);
  const [nameInput, setNameInput]     = useState('');
  const nameInputRef = useRef(null);

  // Split popover — 'header' | 'card' | null
  const [splitAnchor, setSplitAnchor] = useState(null);
  const [splitValue, setSplitValue]   = useState(50);
  const splitHeaderRef = useRef(null);
  const splitCardRef   = useRef(null);

  // Derive safe values before early returns so all hooks run unconditionally.
  const variants = test?.variants ?? [];
  const goals    = test?.goals ?? [];
  const control  = variants.find((v) => v.is_control) ?? variants[0] ?? {};

  // Stable color mapping by variant ID so colors don't shift on sort.
  const colorMap = useMemo(
    () => Object.fromEntries(variants.map((v, i) => [v.id, VARIANT_COLORS[i % VARIANT_COLORS.length]])),
    [variants]
  );

  // Statistics come from the server (StatisticsService) — the same engine the
  // app dashboard uses. Never recompute significance/confidence client-side;
  // it has to stay a single source of truth (learning-mode gating, Bayesian
  // chance-to-win, learned sample sizes — none of that is safe to mirror in JS).
  const statsByVariantId = useMemo(() => {
    const map = {};
    (test?.statistics?.variants ?? []).forEach((s) => {
      map[s.variant_id] = s;
    });
    return map;
  }, [test]);

  const sortedVariants = useMemo(() => {
    if (!sortKey) return variants;
    return [...variants].sort((a, b) => {
      let va, vb;
      if (sortKey === 'visitors') {
        va = a.visitors ?? 0; vb = b.visitors ?? 0;
      } else if (sortKey === 'conversions') {
        va = a.conversions ?? 0; vb = b.conversions ?? 0;
      } else if (sortKey === 'rate') {
        va = conversionRate(a.conversions ?? 0, a.visitors ?? 0);
        vb = conversionRate(b.conversions ?? 0, b.visitors ?? 0);
      } else if (sortKey === 'confidence') {
        // Control has no chance-to-win value; push it to the bottom when sorting.
        va = a.is_control ? -1 : (statsByVariantId[a.id]?.probability_to_beat_control ?? 0);
        vb = b.is_control ? -1 : (statsByVariantId[b.id]?.probability_to_beat_control ?? 0);
      } else {
        return 0;
      }
      return sortDir === 'desc' ? vb - va : va - vb;
    });
  }, [variants, sortKey, sortDir, statsByVariantId]);

  useEffect(() => { onError?.(error ?? null); }, [error]);

  // Close split popover on outside click.
  useEffect(() => {
    if (!splitAnchor) return;
    function handleClick(e) {
      const inHeader = splitHeaderRef.current?.contains(e.target);
      const inCard   = splitCardRef.current?.contains(e.target);
      if (!inHeader && !inCard) setSplitAnchor(null);
    }
    document.addEventListener('mousedown', handleClick);
    return () => document.removeEventListener('mousedown', handleClick);
  }, [splitAnchor]);

  async function updateTest(fields) {
    const body = new FormData();
    body.append('action', 'spliteezy_test_action');
    body.append('nonce', config.nonce);
    body.append('test_id', testId);
    body.append('test_action', 'update');
    Object.entries(fields).forEach(([k, v]) => body.append(k, String(v)));
    try {
      const res  = await fetch(config.ajax_url, { method: 'POST', body, credentials: 'same-origin' });
      const json = await res.json();
      if (json.success) {
        refresh();
        return true;
      }
    } catch (_) {}
    return false;
  }

  function startEditName() {
    setNameInput(test.name);
    setEditingName(true);
    setTimeout(() => nameInputRef.current?.select(), 0);
  }

  async function commitRename() {
    const trimmed = nameInput.trim();
    setEditingName(false);
    if (trimmed && trimmed !== test.name) {
      await updateTest({ name: trimmed });
    }
  }

  async function commitSplit() {
    setSplitAnchor(null);
    const challenger = variants.find((v) => !v.is_control);
    if (challenger && splitValue !== challenger.weight) {
      await updateTest({ split: splitValue });
    }
  }

  async function doAction(action) {
    if (actioning) return;
    if (action === 'delete' && !window.confirm(sprintf(/* translators: %s: test name. */ __('Delete "%s"? This cannot be undone.', 'spliteezy'), test?.name))) return;

    setActioning(true);
    setActionError(null);
    try {
      const body = new FormData();
      body.append('action', 'spliteezy_test_action');
      body.append('nonce', config.nonce);
      body.append('test_id', testId);
      body.append('test_action', action);
      const res  = await fetch(config.ajax_url, { method: 'POST', body, credentials: 'same-origin' });
      const json = await res.json();
      if (json.success) {
        if (action === 'delete') {
          onBack();
        } else if (action === 'clone' && json.data?.test_id) {
          onOpenTest?.(json.data.test_id);
        } else {
          refresh();
        }
      } else {
        setActionError(json.data?.message || (typeof json.data === 'string' ? json.data : __('Action failed.', 'spliteezy')));
      }
    } catch (_) {
      setActionError(__('Network error — please try again.', 'spliteezy'));
    }
    setActioning(false);
  }

  async function applyVariant(variantPostId, variantLabel) {
    if (!window.confirm(
      sprintf(/* translators: %s: variant name. */ __('Apply "%s" to the original post?\n\nThis will permanently replace the original post\'s content and SEO settings. This cannot be undone.', 'spliteezy'), variantLabel)
    )) return;

    setActioning(true);
    setActionError(null);
    try {
      const body = new FormData();
      body.append('action', 'spliteezy_test_action');
      body.append('nonce', config.nonce);
      body.append('test_id', testId);
      body.append('test_action', 'apply');
      body.append('variant_post_id', variantPostId);
      const res  = await fetch(config.ajax_url, { method: 'POST', body, credentials: 'same-origin' });
      const json = await res.json();
      if (json.success) {
        onBack();
      } else {
        setActionError(json.data?.message || (typeof json.data === 'string' ? json.data : __('Apply failed.', 'spliteezy')));
      }
    } catch (_) {
      setActionError(__('Network error — please try again.', 'spliteezy'));
    }
    setActioning(false);
  }

  function handleSort(key) {
    if (sortKey !== key) {
      setSortKey(key);
      setSortDir('desc');
    } else if (sortDir === 'desc') {
      setSortDir('asc');
    } else {
      // Third click: clear sort back to default order.
      setSortKey(null);
      setSortDir('desc');
    }
  }

  if (loading) {
    return (
      <div className="eezy-wrap">
        <div className="eezy-loading"><div className="eezy-spinner" /><span>{__('Loading…', 'spliteezy')}</span></div>
      </div>
    );
  }

  if (error || !test) {
    return (
      <div className="eezy-wrap">
        <button className="eezy-btn eezy-btn--ghost" onClick={onBack}>{__('← Back', 'spliteezy')}</button>
        <div className="eezy-notice eezy-notice--error" style={{ marginTop: 16 }}>{error || __('Test not found.', 'spliteezy')}</div>
      </div>
    );
  }

  const primaryGoal = goals.find((g) => g.is_primary) ?? goals[0] ?? null;

  const totalVisitors    = variants.reduce((s, v) => s + (v.visitors ?? 0), 0);
  const totalConversions = variants.reduce((s, v) => s + (v.conversions ?? 0), 0);
  const splitLabel       = variants.map((v) => `${v.weight}%`).join(' / ');
  const days             = daysRunning(test.started_at);

  const chartSeries = variants.map((v) => {
    const visits = v.daily_stats ?? [];
    const convs  = v.daily_conversions ?? [];
    let points;
    if (chartMetric === 'visitors') {
      points = visits;
    } else if (chartMetric === 'conversions') {
      points = convs;
    } else {
      const convByDate = Object.fromEntries(convs.map((p) => [p.date, p.value]));
      points = visits.map((p) => ({
        date: p.date,
        value: p.value > 0 ? Math.round(((convByDate[p.date] ?? 0) / p.value) * 1000) / 10 : 0,
      }));
    }
    return { label: variantName(v), color: colorMap[v.id], points };
  });

  // Winner and test-level confidence come straight from the server's verdict —
  // it already accounts for learning mode, direction (z > 0), and the
  // confidence threshold. Don't re-derive any of this client-side.
  const statistics = test.statistics ?? null;
  const verdict = statistics?.verdict ?? null;
  const winnerVariant = verdict?.winner_id ? variants.find((v) => v.id === verdict.winner_id) : null;
  const winnerStats = winnerVariant ? statsByVariantId[winnerVariant.id] : null;
  const hasWinner = verdict?.type === 'significant' && !!winnerVariant && !!winnerStats;

  const testConfidence = statistics?.confidence ?? null;
  const confidenceLowData = !(statistics?.has_enough_data ?? false);
  const controlRate = conversionRate(control.conversions ?? 0, control.visitors ?? 0);

  const hasUneditedVariants = variants.some((v) => !v.is_control && v.needs_edit);

  return (
    <div className="eezy-wrap">

      {/* ── Header ── */}
      <div className="eezy-header">
        <button className="eezy-btn eezy-btn--ghost" onClick={onBack}>{__('← Tests', 'spliteezy')}</button>
        <div className="eezy-header__title-group">
          <div className="eezy-header__title-row">
            {editingName ? (
              <input
                ref={nameInputRef}
                className="eezy-header__title-input"
                value={nameInput}
                onChange={(e) => setNameInput(e.target.value)}
                onBlur={commitRename}
                onKeyDown={(e) => {
                  if (e.key === 'Enter') { e.target.blur(); }
                  if (e.key === 'Escape') { setEditingName(false); }
                }}
                autoFocus
              />
            ) : (
              <h1 className="eezy-header__title eezy-header__title--editable" onClick={startEditName} title={__('Click to rename', 'spliteezy')}>
                {test.name}
              </h1>
            )}
            <StatusBadge status={test.status} />
          </div>
          {test.target_url && (
            <a href={test.target_url} target="_blank" rel="noopener noreferrer" className="eezy-header__url">
              {test.target_url}
            </a>
          )}
        </div>
        <div className="eezy-test-actions">
          {test.status === 'draft' && (
            <button
              className="eezy-btn eezy-btn--primary"
              disabled={actioning || hasUneditedVariants}
              title={hasUneditedVariants ? __('Edit all variant content before starting', 'spliteezy') : undefined}
              onClick={() => doAction('start')}
            >
              {__('Start Test', 'spliteezy')}
            </button>
          )}
          {test.status === 'scheduled' && (
            <button
              className="eezy-btn eezy-btn--primary"
              disabled={actioning || hasUneditedVariants}
              title={hasUneditedVariants ? __('Edit all variant content before starting', 'spliteezy') : undefined}
              onClick={() => doAction('start')}
            >
              {__('Start Now', 'spliteezy')}
            </button>
          )}
          {test.status === 'active' && (<>
            <div className="eezy-split-wrap" ref={splitHeaderRef}>
              <button
                className="eezy-btn eezy-btn--ghost eezy-btn--icon"
                title={__('Adjust split', 'spliteezy')}
                onClick={() => {
                  const challenger = variants.find((v) => !v.is_control);
                  setSplitValue(challenger?.weight ?? 50);
                  setSplitAnchor((a) => a === 'header' ? null : 'header');
                }}
              >
                <svg width="16" height="16" viewBox="0 0 20 20" fill="none" stroke="currentColor" strokeWidth="1.75" strokeLinecap="round">
                  <line x1="3" y1="5"  x2="17" y2="5"  />
                  <circle cx="7"  cy="5"  r="2" fill="white" />
                  <line x1="3" y1="10" x2="17" y2="10" />
                  <circle cx="13" cy="10" r="2" fill="white" />
                  <line x1="3" y1="15" x2="17" y2="15" />
                  <circle cx="9"  cy="15" r="2" fill="white" />
                </svg>
              </button>
              {splitAnchor === 'header' && <SplitPopover splitValue={splitValue} setSplitValue={setSplitValue} onClose={() => setSplitAnchor(null)} onSave={commitSplit} align="end" />}
            </div>
            <button className="eezy-btn eezy-btn--ghost" disabled={actioning} onClick={() => doAction('pause')}>{__('Pause', 'spliteezy')}</button>
            <button className="eezy-btn eezy-btn--primary" disabled={actioning} onClick={() => doAction('finish')}>{__('Finish', 'spliteezy')}</button>
          </>)}
          {test.status === 'paused' && (<>
            <button className="eezy-btn eezy-btn--primary" disabled={actioning} onClick={() => doAction('resume')}>{__('Resume', 'spliteezy')}</button>
            <button className="eezy-btn eezy-btn--ghost" disabled={actioning} onClick={() => doAction('finish')}>{__('Finish', 'spliteezy')}</button>
          </>)}
          <DropdownMenu items={[
            { label: __('Duplicate test', 'spliteezy'), onClick: () => doAction('clone'), disabled: actioning },
            ...(['active', 'paused'].includes(test.status) ? ['separator', { label: __('Stop', 'spliteezy'), onClick: () => doAction('stop'), disabled: actioning }] : []),
            'separator',
            { label: __('Delete', 'spliteezy'), onClick: () => doAction('delete'), danger: true, disabled: actioning },
          ]} />
        </div>
      </div>

      {actionError && (
        <div className="eezy-notice eezy-notice--error" style={{ marginBottom: 16 }}>{actionError}</div>
      )}

      {hasUneditedVariants && ['draft', 'scheduled'].includes(test.status) && (
        <div className="eezy-banner eezy-banner--warning">
          <svg width="16" height="16" viewBox="0 0 20 20" fill="currentColor" style={{ flexShrink: 0 }}>
            <path fillRule="evenodd" d="M8.485 2.495c.673-1.167 2.357-1.167 3.03 0l6.28 10.875c.673 1.167-.17 2.625-1.516 2.625H3.72c-1.347 0-2.189-1.458-1.515-2.625L8.485 2.495zM10 5a.75.75 0 01.75.75v3.5a.75.75 0 01-1.5 0v-3.5A.75.75 0 0110 5zm0 9a1 1 0 100-2 1 1 0 000 2z" clipRule="evenodd" />
          </svg>
          <span>{__("Variant content hasn't been edited yet — click", 'spliteezy')} <strong>{__('Edit content', 'spliteezy')}</strong> {__('on each variant below before starting the test.', 'spliteezy')}</span>
        </div>
      )}

      {/* ── Winner banner ── */}
      {hasWinner && (
        <div className="eezy-banner eezy-banner--winner">
          <span className="eezy-banner__badge">A</span>
          <span style={{ flex: 1 }}>
            {sprintf(
              /* translators: 1: variant name, 2: conversion rate, 3: confidence percentage. */
              __('%1$s is winning — %2$s%% conv. rate at %3$s%% confidence.', 'spliteezy'),
              variantName(winnerVariant) || __('Variant A', 'spliteezy'),
              Number(winnerStats.conversion_rate).toFixed(2),
              Math.round(winnerStats.confidence)
            )}
          </span>
          {['ended', 'winner'].includes(test.status) && winnerVariant.post_id && (
            <button
              className="eezy-btn eezy-btn--primary eezy-btn--sm"
              disabled={actioning}
              onClick={() => applyVariant(winnerVariant.post_id, variantName(winnerVariant) || __('this variant', 'spliteezy'))}
            >
              {__('Apply to website', 'spliteezy')}
            </button>
          )}
        </div>
      )}

      {/* ── Info cards ── */}
      <div className="eezy-info-cards">
        <InfoCard
          label={__('Started', 'spliteezy')}
          value={formatDate(test.started_at)}
          sub={test.started_at
            ? `${days > 0 ? sprintf(/* translators: %d: number of days. */ __('%d days running', 'spliteezy'), days) : __('Started today', 'spliteezy')} · ${endLabel(test.end_mode, test.end_value, test.confidence_threshold)}`
            : endLabel(test.end_mode, test.end_value, test.confidence_threshold)}
        />
        <div className="eezy-split-wrap" ref={splitCardRef}>
          <InfoCard
            label={__('Split', 'spliteezy')}
            value={`${control.weight ?? 50}% / ${variants.find((v) => !v.is_control)?.weight ?? 50}%`}
            sub={__('Control / Variant A', 'spliteezy')}
            onEdit={!['ended', 'winner'].includes(test.status) ? () => {
              const challenger = variants.find((v) => !v.is_control);
              setSplitValue(challenger?.weight ?? 50);
              setSplitAnchor((a) => a === 'card' ? null : 'card');
            } : undefined}
          />
          {splitAnchor === 'card' && <SplitPopover splitValue={splitValue} setSplitValue={setSplitValue} onClose={() => setSplitAnchor(null)} onSave={commitSplit} />}
        </div>
        {primaryGoal && (
          <InfoCard label={__('Primary goal', 'spliteezy')} value={goalLabel(primaryGoal)} sub={goalDetail(primaryGoal)} />
        )}
        <InfoCard
          label={__('Confidence', 'spliteezy')}
          value={testConfidence !== null && !confidenceLowData ? `~${Math.round(testConfidence)}%` : '—'}
          sub={sprintf(/* translators: %d: confidence threshold percentage. */ __('Target: %d%%', 'spliteezy'), test.confidence_threshold ?? 95)}
          info={sprintf(
            /* translators: %d: confidence threshold percentage. */
            __('How certain we are that the difference between variants is real and not random chance. When it crosses your %d%% threshold, a winner can be declared.', 'spliteezy'),
            test.confidence_threshold ?? 95
          )}
        />
      </div>

      {/* ── Performance (chart + variants table) ── */}
      <div className="eezy-card eezy-card--flush">
        <div className="eezy-chart-section">
          <h2 className="eezy-card__title">{__('Performance', 'spliteezy')}</h2>
          <div className="eezy-metric-tiles">
            <MetricTile
              label={__('Unique visitors', 'spliteezy')}
              value={totalVisitors.toLocaleString()}
              active={chartMetric === 'visitors'}
              onClick={() => setChartMetric('visitors')}
            />
            <MetricTile
              label={__('Unique conversions', 'spliteezy')}
              value={totalConversions.toLocaleString()}
              active={chartMetric === 'conversions'}
              onClick={() => setChartMetric('conversions')}
            />
            <MetricTile
              label={__('Conversion rate', 'spliteezy')}
              value={`${conversionRate(totalConversions, totalVisitors)}%`}
              active={chartMetric === 'rate'}
              onClick={() => setChartMetric('rate')}
            />
          </div>
          <TimeSeriesChart series={chartSeries} unit={chartMetric === 'rate' ? '%' : ''} />
        </div>

        {/* Variants table */}
        <div style={{ borderTop: '1px solid #f3f4f6' }}>

          {/* Column headers */}
          <div className="eezy-perf-header">
            <span>{__('Variant', 'spliteezy')}</span>
            <SortHeader label={__('Visitors', 'spliteezy')}    field="visitors"    sortKey={sortKey} sortDir={sortDir} onSort={handleSort} align="right" />
            <SortHeader label={__('Conversions', 'spliteezy')} field="conversions" sortKey={sortKey} sortDir={sortDir} onSort={handleSort} align="right" />
            <SortHeader label={__('Conv. Rate', 'spliteezy')}  field="rate"        sortKey={sortKey} sortDir={sortDir} onSort={handleSort} />
            <SortHeader label={__('Chance to win', 'spliteezy')}  field="confidence"  sortKey={sortKey} sortDir={sortDir} onSort={handleSort} />
            <span />
          </div>

          {/* Variant rows */}
          {sortedVariants.map((v) => {
            const isControl = !!v.is_control;
            const sig = isControl ? null : statsByVariantId[v.id];
            const rate = conversionRate(v.conversions ?? 0, v.visitors ?? 0);
            const uplift = isControl ? null : relativeUplift(rate, controlRate);
            const viewUrl = isControl ? test.target_url : null;
            const editUrl = !isControl && v.post_id && test.status !== 'active'
              ? `${config.admin_url}post.php?post=${v.post_id}&action=edit`
              : null;
            const canApply = !isControl && v.post_id && ['ended', 'winner'].includes(test.status);

            return (
              <div
                key={v.id}
                className={`eezy-perf-row ${isControl ? 'eezy-perf-row--control' : ''}`}
              >
                {/* Variant name */}
                <div className="eezy-variant-cell">
                  <span className="eezy-variant-dot" style={{ background: colorMap[v.id] }} />
                  <span className="eezy-variant-label">
                    {variantName(v)}
                  </span>
                  {isControl && <span className="eezy-badge eezy-badge--gray">{__('Control', 'spliteezy')}</span>}
                </div>

                {/* Visitors */}
                <span className="eezy-perf-num">{(v.visitors ?? 0).toLocaleString()}</span>

                {/* Conversions */}
                <span className="eezy-perf-num">{(v.conversions ?? 0).toLocaleString()}</span>

                {/* Conv. Rate + relative uplift */}
                <div className="eezy-conv-rate">
                  <span className={`eezy-conv-rate__value ${rate > 0 ? 'eezy-conv-rate__value--positive' : ''}`}>
                    {rate}%
                  </span>
                  {isControl && <span className="eezy-conv-rate__baseline">{__('Baseline', 'spliteezy')}</span>}
                  {uplift !== null && (
                    <span className={`eezy-conv-rate__uplift ${uplift > 0 ? 'eezy-conv-rate__uplift--up' : uplift < 0 ? 'eezy-conv-rate__uplift--down' : ''}`}>
                      {uplift > 0 ? '↑' : uplift < 0 ? '↓' : ''}{Math.abs(uplift).toFixed(1)}%
                    </span>
                  )}
                </div>

                {/* Chance to win */}
                <div>
                  {isControl ? (
                    <span className="eezy-conv-rate__baseline">{__('Baseline', 'spliteezy')}</span>
                  ) : (
                    <ConfidenceMeter value={sig?.probability_to_beat_control ?? 0} lowData={(sig?.confidence ?? null) === null} />
                  )}
                </div>

                {/* View / Edit / Apply */}
                <div className="eezy-perf-view">
                  {viewUrl && (
                    <a href={viewUrl} target="_blank" rel="noopener noreferrer" className="eezy-btn eezy-btn--ghost eezy-btn--sm">
                      {__('View →', 'spliteezy')}
                    </a>
                  )}
                  {editUrl && v.needs_edit && (
                    <a href={editUrl} target="_blank" rel="noopener noreferrer" className="eezy-btn eezy-btn--primary eezy-btn--sm">
                      {__('Edit content →', 'spliteezy')}
                    </a>
                  )}
                  {editUrl && !v.needs_edit && (
                    <a href={editUrl} target="_blank" rel="noopener noreferrer" className="eezy-btn eezy-btn--ghost eezy-btn--sm">
                      {__('Edit →', 'spliteezy')}
                    </a>
                  )}
                  {canApply && (
                    <button
                      className="eezy-btn eezy-btn--primary eezy-btn--sm"
                      disabled={actioning}
                      onClick={() => applyVariant(v.post_id, variantName(v) || __('this variant', 'spliteezy'))}
                    >
                      {__('Apply', 'spliteezy')}
                    </button>
                  )}
                </div>
              </div>
            );
          })}
        </div>
      </div>

      {/* ── History ── */}
      <div className="eezy-card">
        <h2 className="eezy-card__title">{__('History', 'spliteezy')}</h2>
        <ul className="eezy-history-list">
          {buildTimeline(test).map((h, i) => (
            <li key={i} className="eezy-history-item">
              <span className={`eezy-history-item__dot ${h.event.startsWith('test_') ? 'eezy-history-item__dot--system' : ''}`} />
              <span className="eezy-history-item__text">{historyLabel(h)}</span>
              <span className="eezy-history-item__date">{formatDate(h.created_at)}</span>
            </li>
          ))}
        </ul>
      </div>

    </div>
  );
}

function historyLabel(h) {
  if (h.event === 'test_created')   return __('Test created', 'spliteezy');
  if (h.event === 'test_activated') return __('Test activated', 'spliteezy');
  if (h.event === 'test_ended')     return __('Test ended', 'spliteezy');
  if (h.event === 'name_changed') {
    return sprintf(/* translators: 1: previous test name, 2: new test name. */ __('Renamed from "%1$s" to "%2$s"', 'spliteezy'), h.data.from, h.data.to);
  }
  if (h.event === 'split_changed') {
    const fromControl = 100 - h.data.from;
    const toControl   = 100 - h.data.to;
    return sprintf(/* translators: 1: previous split, 2: new split. */ __('Split changed from %1$s to %2$s', 'spliteezy'), `${fromControl}/${h.data.from}`, `${toControl}/${h.data.to}`);
  }
  return h.event.replace(/_/g, ' ');
}

function SplitPopover({ splitValue, setSplitValue, onClose, onSave, align = 'start' }) {
  const controlPct = 100 - splitValue;
  return (
    <div className={`eezy-split-popover ${align === 'end' ? 'eezy-split-popover--end' : ''}`}>
      <p className="eezy-split-popover__label">{__('Adjust split', 'spliteezy')}</p>
      <div className="eezy-split-control">
        <div className="eezy-split-numbers">
          <div className="eezy-split-number eezy-split-number--control">
            <span className="eezy-split-number__pct">{controlPct}%</span>
            <span className="eezy-split-number__name">{__('Control', 'spliteezy')}</span>
          </div>
          <div className="eezy-split-number eezy-split-number--variant">
            <span className="eezy-split-number__pct">{splitValue}%</span>
            <span className="eezy-split-number__name">{__('Variant A', 'spliteezy')}</span>
          </div>
        </div>
        <input
          type="range" min="0" max="100" step="5"
          value={controlPct}
          onChange={(e) => setSplitValue(100 - Math.max(10, Math.min(90, Number(e.target.value))))}
          className="eezy-split-slider eezy-split-slider--sm"
          style={{ background: `linear-gradient(to right, var(--eezy-brand-soft) 0%, var(--eezy-brand-soft) ${controlPct}%, var(--eezy-primary) ${controlPct}%, var(--eezy-primary) 100%)` }}
        />
        <div className="eezy-split-hints">
          <span>{__('Original page', 'spliteezy')}</span>
          <span>{__('Variant page', 'spliteezy')}</span>
        </div>
      </div>
      <div className="eezy-split-popover__actions">
        <button className="eezy-btn eezy-btn--ghost eezy-btn--sm" onClick={onClose}>{__('Cancel', 'spliteezy')}</button>
        <button className="eezy-btn eezy-btn--primary eezy-btn--sm" onClick={onSave}>{__('Save', 'spliteezy')}</button>
      </div>
    </div>
  );
}

function MetricTile({ label, value, active, onClick }) {
  return (
    <button
      type="button"
      className={`eezy-metric-tile${active ? ' eezy-metric-tile--active' : ''}`}
      onClick={onClick}
      aria-pressed={active}
    >
      <span className="eezy-metric-tile__label">{label}</span>
      <span className="eezy-metric-tile__value">{value}</span>
    </button>
  );
}

function InfoCard({ label, value, sub, onEdit, info }) {
  return (
    <div className="eezy-info-card">
      <div className="eezy-info-card__top">
        <span className="eezy-info-card__label-group">
          <span className="eezy-info-card__label">{label}</span>
          {info && <InfoTip text={info} />}
        </span>
        {onEdit && (
          <button className="eezy-info-card__edit" onClick={onEdit} title={sprintf(/* translators: %s: field name. */ __('Edit %s', 'spliteezy'), label.toLowerCase())}>
            <svg width="13" height="13" viewBox="0 0 20 20" fill="currentColor">
              <path d="M13.586 3.586a2 2 0 112.828 2.828l-.793.793-2.828-2.828.793-.793zM11.379 5.793L3 14.172V17h2.828l8.38-8.379-2.83-2.828z" />
            </svg>
          </button>
        )}
      </div>
      <span className="eezy-info-card__value">{value}</span>
      {sub && <span className="eezy-info-card__sub">{sub}</span>}
    </div>
  );
}
