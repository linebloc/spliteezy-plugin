import { __, sprintf } from '@wordpress/i18n';
import { useState, useEffect, useRef } from 'react';

const GOAL_TYPES = [
  { type: 'page_reached',    planKey: 'goal_page_reached',    label: () => __('Page reached', 'spliteezy'),         desc: () => __('Lands on a URL', 'spliteezy') },
  { type: 'click',           planKey: 'goal_click',           label: () => __('Click', 'spliteezy'),                desc: () => __('Element or link click', 'spliteezy') },
  { type: 'scroll_depth',    planKey: 'goal_scroll_depth',    label: () => __('Scroll depth', 'spliteezy'),         desc: () => __('Scrolls past a %', 'spliteezy') },
  { type: 'time_on_page',    planKey: 'goal_time_on_page',    label: () => __('Time on page', 'spliteezy'),         desc: () => __('Spends N seconds', 'spliteezy') },
  { type: 'element_view',    planKey: 'goal_element_view',    label: () => __('Element view', 'spliteezy'),         desc: () => __('Element scrolls into view', 'spliteezy') },
  { type: 'video_play',      planKey: 'goal_video_play',      label: () => __('Video play', 'spliteezy'),           desc: () => __('YouTube, Vimeo or WP video', 'spliteezy') },
  { type: 'form_submission', planKey: 'goal_form_submission', label: () => __('Form submission', 'spliteezy'),      desc: () => __('Any form or by selector', 'spliteezy') },
  { type: 'external_event',  planKey: 'goal_external_event',  label: () => __('Custom GA4/GTM event', 'spliteezy'), desc: () => __('Via Spliteezy.trackEvent()', 'spliteezy') },
];

const SCROLL_OPTIONS = [
  { value: 25,  label: () => '25%',  desc: () => __('Quarter page', 'spliteezy') },
  { value: 50,  label: () => '50%',  desc: () => __('Halfway', 'spliteezy')      },
  { value: 75,  label: () => '75%',  desc: () => __('Most of page', 'spliteezy') },
  { value: 100, label: () => '100%', desc: () => __('Full page', 'spliteezy')    },
];

const TIME_OPTIONS = [
  { value: 10, label: () => __('10s', 'spliteezy'),   desc: () => __('Quick glance', 'spliteezy') },
  { value: 30, label: () => __('30s', 'spliteezy'),   desc: () => __('Engaged', 'spliteezy')      },
  { value: 60, label: () => __('1 min', 'spliteezy'), desc: () => __('Deep reader', 'spliteezy')  },
];

const START_OPTIONS = [
  { value: 'now',       label: () => __('Start immediately', 'spliteezy'),  desc: () => __("Activate as soon as it's created", 'spliteezy')  },
  { value: 'scheduled', label: () => __('Schedule for later', 'spliteezy'), desc: () => __('Pick a date and time to go live', 'spliteezy')   },
  { value: 'draft',     label: () => __('Save as draft', 'spliteezy'),      desc: () => __('Set up now, start manually later', 'spliteezy')  },
];

export default function TestCreate({ config, onBack, onOpenTest }) {
  const [step, setStep] = useState('pick');
  const [form, setForm] = useState({
    post:          null,
    name:          '',
    split:         50,
    goalType:      'scroll_depth',
    goalPercent:   50,
    goalUrl:       '',
    goalSelector:  '',
    goalSeconds:   30,
    goalEventName: '',
    goalLinkUrl:   '',
    clickMode:     'element',
    scrollCustom:  false,
    timeCustom:    false,
    startMode:     'now',
    scheduledAt:   '',
  });

  function update(field, value) {
    setForm((f) => ({ ...f, [field]: value }));
  }

  const STEPS = [__('Page', 'spliteezy'), __('Configure', 'spliteezy'), __('Schedule', 'spliteezy')];
  const stepIdx = { pick: 0, configure: 1, schedule: 2 }[step] ?? 0;

  function handleStepClick(i) {
    const names = ['pick', 'configure', 'schedule'];
    if (i < stepIdx) setStep(names[i]);
  }

  return (
    <div className="eezy-wrap">
      <div className="eezy-header">
        <button className="eezy-btn eezy-btn--ghost" onClick={onBack}>{__('← Tests', 'spliteezy')}</button>
        <h1 className="eezy-header__title">{__('New A/B Test', 'spliteezy')}</h1>
      </div>

      <StepIndicator steps={STEPS} current={stepIdx} onStepClick={handleStepClick} />

      {step === 'pick' && (
        <PickStep
          config={config}
          onSelect={(post) => {
            update('post', post);
            update('name', sprintf(/* translators: %s: post title. */ __('%s — A/B Test', 'spliteezy'), post.title));
            setStep('configure');
          }}
        />
      )}
      {step === 'configure' && (
        <ConfigureStep
          config={config}
          form={form}
          update={update}
          onBack={() => setStep('pick')}
          onNext={() => setStep('schedule')}
        />
      )}
      {step === 'schedule' && (
        <ScheduleStep
          config={config}
          form={form}
          update={update}
          onBack={() => setStep('configure')}
          onSuccess={(res) => {
            window.location.href = res.edit_url;
          }}
        />
      )}
    </div>
  );
}

// ── Step indicator ──────────────────────────────────────────────────────────

function StepIndicator({ steps, current, onStepClick }) {
  return (
    <div className="eezy-steps">
      {steps.map((label, i) => (
        <div key={i} className="eezy-steps__item">
          <div className={`eezy-steps__item ${i < current ? 'eezy-steps__item--done' : i === current ? 'eezy-steps__item--active' : ''}`}>
            <button
              type="button"
              className={`eezy-steps__dot${i < current ? ' eezy-steps__dot--done' : ''}${i === current ? ' eezy-steps__dot--active' : ''}${i < current ? ' eezy-steps__dot--clickable' : ''}`}
              onClick={() => i < current && onStepClick && onStepClick(i)}
              disabled={i > current}
            >
              {i < current ? '✓' : i + 1}
            </button>
            <span className="eezy-steps__label">{label}</span>
          </div>
          {i < steps.length - 1 && (
            <div className={`eezy-steps__connector ${i < current ? 'eezy-steps__connector--done' : ''}`} />
          )}
        </div>
      ))}
    </div>
  );
}

// ── Step 1: Pick a post ─────────────────────────────────────────────────────

function PickStep({ config, onSelect }) {
  const [query,          setQuery]          = useState('');
  const [postTypeFilter, setPostTypeFilter] = useState('');
  const [results,        setResults]        = useState([]);
  const [loading,        setLoading]        = useState(false);
  const timerRef = useRef(null);

  const postTypes    = config.post_types || {};
  const hasTypeFilter = Object.keys(postTypes).length > 1;

  async function search(q, typeFilter) {
    setLoading(true);
    const body = new FormData();
    body.append('action', 'spliteezy_search_posts');
    body.append('nonce',  config.nonce);
    body.append('search', q);
    if (typeFilter) body.append('post_type_filter', typeFilter);
    try {
      const res  = await fetch(config.ajax_url, { method: 'POST', body, credentials: 'same-origin' });
      const json = await res.json();
      if (json.success) setResults(json.data.posts ?? []);
    } catch (_) {}
    setLoading(false);
  }

  useEffect(() => { search('', ''); }, []);
  useEffect(() => {
    clearTimeout(timerRef.current);
    timerRef.current = setTimeout(() => search(query, postTypeFilter), 350);
    return () => clearTimeout(timerRef.current);
  }, [query, postTypeFilter]);

  return (
    <div className="eezy-card">
      <div className="eezy-post-picker__header">
        <label className="eezy-post-picker__label">
          {__('Which page or post would you like to test?', 'spliteezy')}
        </label>
        <div className="eezy-post-picker__filters">
          {hasTypeFilter && (
            <select
              value={postTypeFilter}
              onChange={(e) => setPostTypeFilter(e.target.value)}
              className="eezy-post-picker__type-filter"
            >
              <option value="">{__('All types', 'spliteezy')}</option>
              {Object.entries(postTypes).map(([slug, label]) => (
                <option key={slug} value={slug}>{label}</option>
              ))}
            </select>
          )}
          <input
            type="search"
            placeholder={__('Search by title…', 'spliteezy')}
            value={query}
            onChange={(e) => setQuery(e.target.value)}
            autoFocus
            className="eezy-post-picker__search"
          />
        </div>
      </div>

      {loading && (
        <div className="eezy-post-picker__loading">
          <div className="eezy-spinner eezy-spinner--sm" /> {__('Searching…', 'spliteezy')}
        </div>
      )}

      {!loading && results.length === 0 && (
        <div className="eezy-post-picker__empty">
          {query.length >= 2 ? __('No results found.', 'spliteezy') : __('No published posts found.', 'spliteezy')}
        </div>
      )}

      {!loading && results.length > 0 && (
        <ul className="eezy-post-picker__results">
          {results.map((post) => (
            <li key={post.id} className="eezy-post-picker__result">
              <button onClick={() => onSelect(post)} className="eezy-post-picker__btn">
                <div className="eezy-post-picker__btn-inner">
                  <span className="eezy-post-picker__title">{post.title}</span>
                  <span className="eezy-post-type-badge">{post.type_label}</span>
                </div>
                <span className="eezy-post-picker__url">{post.url}</span>
              </button>
            </li>
          ))}
        </ul>
      )}
    </div>
  );
}

// ── Step 2: Configure (name + split + goal) ─────────────────────────────────

function ConfigureStep({ config, form, update, onBack, onNext }) {
  const plan = config.plan ?? {};
  const featureMinPlans = plan.feature_min_plans ?? {};

  function isGoalLocked(g) {
    return g.planKey !== null && !plan[g.planKey];
  }

  // If the pre-selected goal type is locked, reset to first available.
  useEffect(() => {
    const current = GOAL_TYPES.find((g) => g.type === form.goalType);
    if (current && isGoalLocked(current)) {
      const first = GOAL_TYPES.find((g) => !isGoalLocked(g));
      if (first) update('goalType', first.type);
    }
  }, []); // eslint-disable-line react-hooks/exhaustive-deps

  const canNext = (() => {
    if (!form.name.trim()) return false;
    const currentGoal = GOAL_TYPES.find((g) => g.type === form.goalType);
    if (currentGoal && isGoalLocked(currentGoal)) return false;
    if (form.goalType === 'page_reached')   return form.goalUrl.trim().length > 0;
    if (form.goalType === 'click') {
      return form.clickMode === 'element'
        ? form.goalSelector.trim().length > 0
        : form.goalLinkUrl.trim().length > 0;
    }
    if (form.goalType === 'element_view')   return form.goalSelector.trim().length > 0;
    if (form.goalType === 'external_event') return form.goalEventName.trim().length > 0;
    if (form.goalType === 'scroll_depth' && form.scrollCustom) return form.goalPercent >= 1 && form.goalPercent <= 99;
    if (form.goalType === 'time_on_page'  && form.timeCustom)  return form.goalSeconds >= 1;
    return true;
  })();

  const controlPct = 100 - form.split;
  const variantPct = form.split;

  return (
    <div className="eezy-card eezy-card-body">
      <div className="eezy-post-ref">
        <span className="eezy-post-ref__dot" />
        <span className="eezy-post-ref__name">{form.post.title}</span>
        <span className="eezy-post-type-badge">{form.post.type_label}</span>
      </div>

      {/* Test setup */}
      <div className="eezy-configure-section">
        <p className="eezy-configure-section__title">{__('Test setup', 'spliteezy')}</p>

        <div>
          <label className="eezy-form-label">{__('Test name', 'spliteezy')}</label>
          <input
            type="text"
            value={form.name}
            onChange={(e) => update('name', e.target.value)}
            autoFocus
            className="eezy-form-input"
          />
        </div>

        <div>
          <label className="eezy-form-label">{__('Traffic split', 'spliteezy')}</label>
          <div className="eezy-split-control">
            <div className="eezy-split-numbers">
              <div className="eezy-split-number eezy-split-number--control">
                <span className="eezy-split-number__pct">{controlPct}%</span>
                <span className="eezy-split-number__name">{__('Control', 'spliteezy')}</span>
              </div>
              <div className="eezy-split-number eezy-split-number--variant">
                <span className="eezy-split-number__pct">{variantPct}%</span>
                <span className="eezy-split-number__name">{__('Variant A', 'spliteezy')}</span>
              </div>
            </div>
            <input
              type="range"
              min="0" max="100" step="5"
              value={controlPct}
              onChange={(e) => update('split', 100 - Math.max(10, Math.min(90, Number(e.target.value))))}
              className="eezy-split-slider"
              style={{
                background: `linear-gradient(to right, var(--eezy-brand-soft) 0%, var(--eezy-brand-soft) ${controlPct}%, var(--eezy-primary) ${controlPct}%, var(--eezy-primary) 100%)`,
              }}
            />
            <div className="eezy-split-hints">
              <span>{__('Original page', 'spliteezy')}</span>
              <span>{__('Variant page', 'spliteezy')}</span>
            </div>
          </div>
        </div>
      </div>

      {/* Conversion goal */}
      <div className="eezy-configure-section">
        <p className="eezy-configure-section__title">{__('Conversion goal', 'spliteezy')}</p>

        <div>
          <p className="eezy-muted" style={{ fontSize: 14, marginBottom: 12 }}>
            {__('What action counts as a conversion for this test?', 'spliteezy')}
          </p>
          <div className="eezy-goal-grid">
            {GOAL_TYPES.map((g) => {
              const locked = isGoalLocked(g);
              if (locked) {
                return (
                  <a
                    key={g.type}
                    href={config.billing_url}
                    target="_blank"
                    rel="noreferrer"
                    className="eezy-goal-option eezy-goal-option--locked"
                  >
                    <span className="eezy-goal-option__name">{g.label()}</span>
                    <span className="eezy-goal-option__desc">{g.desc()}</span>
                    <span className="eezy-goal-option__upgrade">
                      <svg className="eezy-goal-option__upgrade-icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 640 640" aria-hidden="true">
                        <path d="M256 160L256 224L384 224L384 160C384 124.7 355.3 96 320 96C284.7 96 256 124.7 256 160zM192 224L192 160C192 89.3 249.3 32 320 32C390.7 32 448 89.3 448 160L448 224C483.3 224 512 252.7 512 288L512 512C512 547.3 483.3 576 448 576L192 576C156.7 576 128 547.3 128 512L128 288C128 252.7 156.7 224 192 224z"/>
                      </svg>
                      {sprintf(/* translators: %s: plan name. */ __('Available from %s', 'spliteezy'), featureMinPlans[g.planKey])}
                    </span>
                  </a>
                );
              }
              return (
                <button
                  key={g.type}
                  onClick={() => update('goalType', g.type)}
                  className={`eezy-goal-option ${form.goalType === g.type ? 'eezy-goal-option--active' : ''}`}
                >
                  <span className="eezy-goal-option__name">{g.label()}</span>
                  <span className="eezy-goal-option__desc">{g.desc()}</span>
                </button>
              );
            })}
          </div>
        </div>

        <GoalConfig form={form} update={update} />
      </div>

      <div className="eezy-card-footer">
        <button className="eezy-btn eezy-btn--ghost" onClick={onBack}>{__('← Back', 'spliteezy')}</button>
        <button className="eezy-btn eezy-btn--primary" disabled={!canNext} onClick={onNext}>
          {__('Next →', 'spliteezy')}
        </button>
      </div>
    </div>
  );
}

// ── Goal configuration (conditional per goal type) ──────────────────────────

function GoalConfig({ form, update }) {
  if (form.goalType === 'page_reached') {
    return (
      <div>
        <label className="eezy-form-label">{__('Goal URL', 'spliteezy')}</label>
        <p className="eezy-muted" style={{ fontSize: 12, marginBottom: 6 }}>
          {__('Use', 'spliteezy')} <code>*</code> {__('as a wildcard, e.g.', 'spliteezy')} <code>https://example.com/thank-you*</code>
        </p>
        <input
          type="url"
          placeholder="https://example.com/thank-you"
          value={form.goalUrl}
          onChange={(e) => update('goalUrl', e.target.value)}
          autoFocus
          className="eezy-form-input"
        />
      </div>
    );
  }

  if (form.goalType === 'click') {
    return (
      <div>
        <label className="eezy-form-label">{__('Click type', 'spliteezy')}</label>
        <div className="eezy-click-mode-grid">
          <button
            type="button"
            className={`eezy-click-mode ${form.clickMode === 'element' ? 'eezy-click-mode--active' : ''}`}
            onClick={() => update('clickMode', 'element')}
          >
            <span className="eezy-click-mode__name">{__('Element click', 'spliteezy')}</span>
            <span className="eezy-click-mode__desc">{__('Match any element by CSS selector', 'spliteezy')}</span>
          </button>
          <button
            type="button"
            className={`eezy-click-mode ${form.clickMode === 'link' ? 'eezy-click-mode--active' : ''}`}
            onClick={() => update('clickMode', 'link')}
          >
            <span className="eezy-click-mode__name">{__('Link / URL click', 'spliteezy')}</span>
            <span className="eezy-click-mode__desc">{__('Tracks any link pointing to this URL — works with #anchors too', 'spliteezy')}</span>
          </button>
        </div>

        {form.clickMode === 'element' && (
          <div style={{ marginTop: 12 }}>
            <label className="eezy-form-label">{__('CSS selector', 'spliteezy')}</label>
            <p className="eezy-muted" style={{ fontSize: 12, marginBottom: 6 }}>
              {__('Tracks clicks on any element matching this selector.', 'spliteezy')}
            </p>
            <input
              type="text"
              placeholder=".buy-now-button"
              value={form.goalSelector}
              onChange={(e) => update('goalSelector', e.target.value)}
              autoFocus
              className="eezy-form-input"
            />
          </div>
        )}

        {form.clickMode === 'link' && (
          <div style={{ marginTop: 12 }}>
            <label className="eezy-form-label">{__('URL or anchor', 'spliteezy')}</label>
            <p className="eezy-muted" style={{ fontSize: 12, marginBottom: 6 }}>
              {__('Enter a full URL or a hash anchor (e.g.', 'spliteezy')} <code>#contact</code>).
            </p>
            <input
              type="text"
              placeholder={__('#contact or https://example.com/checkout', 'spliteezy')}
              value={form.goalLinkUrl}
              onChange={(e) => update('goalLinkUrl', e.target.value)}
              autoFocus
              className="eezy-form-input"
            />
          </div>
        )}
      </div>
    );
  }

  if (form.goalType === 'scroll_depth') {
    return (
      <div>
        <label className="eezy-form-label">{__('Scroll threshold', 'spliteezy')}</label>
        <div className="eezy-goal-grid eezy-goal-grid--5" style={{ marginTop: 8 }}>
          {SCROLL_OPTIONS.map((opt) => (
            <button
              key={opt.value}
              onClick={() => { update('goalPercent', opt.value); update('scrollCustom', false); }}
              className={`eezy-goal-option ${!form.scrollCustom && form.goalPercent === opt.value ? 'eezy-goal-option--active' : ''}`}
            >
              <span className="eezy-goal-option__value">{opt.label()}</span>
              <span className="eezy-goal-option__desc">{opt.desc()}</span>
            </button>
          ))}
          {form.scrollCustom ? (
            <div className="eezy-goal-option eezy-goal-option--active eezy-goal-option--custom-active">
              <div className="eezy-goal-option__custom-value">
                <input
                  type="number"
                  min="1"
                  max="99"
                  value={form.goalPercent === 50 ? '' : form.goalPercent}
                  placeholder="40"
                  onChange={(e) => update('goalPercent', Math.min(99, Math.max(1, Number(e.target.value) || 1)))}
                  className="eezy-goal-option__custom-input"
                  autoFocus
                />
                <span className="eezy-goal-option__custom-unit">%</span>
              </div>
              <span className="eezy-goal-option__desc">{__('custom', 'spliteezy')}</span>
            </div>
          ) : (
            <button
              onClick={() => update('scrollCustom', true)}
              className="eezy-goal-option"
            >
              <span className="eezy-goal-option__value">···</span>
              <span className="eezy-goal-option__desc">{__('Custom', 'spliteezy')}</span>
            </button>
          )}
        </div>
      </div>
    );
  }

  if (form.goalType === 'time_on_page') {
    return (
        <div>
            <label className="eezy-form-label">
                {__('Time threshold', 'spliteezy')}
            </label>
            <div
                className="eezy-goal-grid eezy-goal-grid--4"
                style={{ marginTop: 8 }}
            >
                {TIME_OPTIONS.map((opt) => (
                    <button
                        key={opt.value}
                        onClick={() => {
                            update('goalSeconds', opt.value);
                            update('timeCustom', false);
                        }}
                        className={`eezy-goal-option ${!form.timeCustom && form.goalSeconds === opt.value ? 'eezy-goal-option--active' : ''}`}
                    >
                        <span className="eezy-goal-option__value">
                            {opt.label()}
                        </span>
                        <span className="eezy-goal-option__desc">{opt.desc()}</span>
                    </button>
                ))}
                {form.timeCustom ? (
                    <div className="eezy-goal-option eezy-goal-option--active eezy-goal-option--custom-active">
                        <div className="eezy-goal-option__custom-value">
                            <input
                                type="number"
                                min="1"
                                value={form.goalSeconds === 30 ? '' : form.goalSeconds}
                                placeholder="45"
                                onChange={(e) =>
                                    update('goalSeconds', Math.max(1, Number(e.target.value) || 1))
                                }
                                className="eezy-goal-option__custom-input"
                                autoFocus
                            />
                            <span className="eezy-goal-option__custom-unit">s</span>
                        </div>
                        <span className="eezy-goal-option__desc">{__('custom', 'spliteezy')}</span>
                    </div>
                ) : (
                    <button
                        onClick={() => update('timeCustom', true)}
                        className="eezy-goal-option"
                    >
                        <span className="eezy-goal-option__value">···</span>
                        <span className="eezy-goal-option__desc">{__('Custom', 'spliteezy')}</span>
                    </button>
                )}
            </div>
        </div>
    );
  }

  if (form.goalType === 'element_view') {
    return (
      <div>
        <label className="eezy-form-label">{__('CSS selector', 'spliteezy')}</label>
        <p className="eezy-muted" style={{ fontSize: 12, marginBottom: 6 }}>
          {__('Fires when this element scrolls into view (at least 50% visible).', 'spliteezy')}
        </p>
        <input
          type="text"
          placeholder="#pricing-table"
          value={form.goalSelector}
          onChange={(e) => update('goalSelector', e.target.value)}
          autoFocus
          className="eezy-form-input"
        />
      </div>
    );
  }

  if (form.goalType === 'video_play') {
    return (
      <div className="eezy-goal-note">
        {__('Automatically detects YouTube, Vimeo, and native WordPress <video> embeds on the page. No extra setup needed.', 'spliteezy')}
      </div>
    );
  }

  if (form.goalType === 'form_submission') {
    return (
      <div>
        <label className="eezy-form-label">{__('Form selector', 'spliteezy')} <span className="eezy-muted" style={{ fontWeight: 400 }}>{__('(optional)', 'spliteezy')}</span></label>
        <p className="eezy-muted" style={{ fontSize: 12, marginBottom: 6 }}>
          {__('Leave blank to track any form on the page. Enter a CSS selector to target a specific form — works with WPForms, Contact Form 7, Gravity Forms, Elementor, or any HTML form.', 'spliteezy')}
        </p>
        <input
          type="text"
          placeholder="#contact-form, .wpcf7-form, form[action*=checkout]"
          value={form.goalSelector}
          onChange={(e) => update('goalSelector', e.target.value)}
          className="eezy-form-input eezy-form-input--full"
        />
      </div>
    );
  }

  if (form.goalType === 'external_event') {
    const eventName = form.goalEventName || 'purchase';
    return (
      <div>
        <label className="eezy-form-label">{__('Event name', 'spliteezy')}</label>
        <p className="eezy-muted" style={{ fontSize: 12, marginBottom: 6 }}>
          {__('Fire this from your theme, plugin, or Google Tag Manager.', 'spliteezy')}
        </p>
        <input
          type="text"
          placeholder="purchase"
          value={form.goalEventName}
          onChange={(e) => update('goalEventName', e.target.value)}
          autoFocus
          className="eezy-form-input"
        />
        <code className="eezy-code-hint">
          Spliteezy.trackEvent('{eventName}')
        </code>
        <p className="eezy-muted" style={{ fontSize: 12, marginTop: 8 }}>
          {__('GA4 and GTM automatically receive this event if they are present on the page.', 'spliteezy')}
        </p>
      </div>
    );
  }

  return null;
}

// ── Step 3: Schedule + submit ───────────────────────────────────────────────

function ScheduleStep({ config, form, update, onBack, onSuccess }) {
  const [submitting, setSubmitting] = useState(false);
  const [error,      setError]      = useState(null);

  const plan        = config.plan ?? {};
  const canSchedule = plan.scheduling ?? false;
  const schedulingMinPlan = plan.feature_min_plans?.scheduling ?? 'Pro';
  const testsLimit  = plan.tests_limit ?? -1;
  const atTestLimit = testsLimit > 0 && (plan.tests_used ?? 0) >= testsLimit;
  const canSubmit   = form.startMode !== 'scheduled' || form.scheduledAt;

  // All slots busy: starting immediately is off the table, but drafting and
  // scheduling always work — the test simply waits for a free slot.
  useEffect(() => {
    if (atTestLimit && form.startMode === 'now') update('startMode', 'draft');
  }, [atTestLimit]); // eslint-disable-line react-hooks/exhaustive-deps

  const submitLabel = submitting
    ? __('Creating…', 'spliteezy')
    : form.startMode === 'now'       ? __('Create & Start Test', 'spliteezy')
    : form.startMode === 'scheduled' ? __('Schedule Test', 'spliteezy')
    : __('Save as Draft', 'spliteezy');

  async function handleSubmit() {
    setSubmitting(true);
    setError(null);

    const finalSelector = form.goalType === 'click' && form.clickMode === 'link'
      ? (form.goalLinkUrl.startsWith('#')
          ? `a[href="${form.goalLinkUrl}"]`
          : `a[href^="${form.goalLinkUrl}"]`)
      : form.goalSelector;

    const body = new FormData();
    body.append('action', 'spliteezy_create_test');
    body.append('nonce',  config.nonce);
    body.append('data', JSON.stringify({
      post_id:         form.post.id,
      name:            form.name,
      split:           form.split,
      goal_type:       form.goalType,
      goal_percent:    form.goalPercent,
      goal_url:        form.goalUrl,
      goal_selector:   finalSelector,
      goal_seconds:    form.goalSeconds,
      goal_event_name: form.goalEventName,
      start_mode:      form.startMode,
      scheduled_at:    form.startMode === 'scheduled' ? form.scheduledAt : null,
    }));
    try {
      const res  = await fetch(config.ajax_url, { method: 'POST', body, credentials: 'same-origin' });
      const json = await res.json();
      if (json.success) {
        onSuccess(json.data);
      } else {
        setError(json.data?.message || (typeof json.data === 'string' ? json.data : __('Something went wrong. Please try again.', 'spliteezy')));
      }
    } catch (_) {
      setError(__('Network error — please try again.', 'spliteezy'));
    }
    setSubmitting(false);
  }

  return (
    <div className="eezy-card eezy-card-body">
      <div>
        <p className="eezy-form-label">{__('When should the test start?', 'spliteezy')}</p>
        <div className="eezy-start-options">
          {START_OPTIONS.map((opt) => {
            const locked =
              (opt.value === 'scheduled' && !canSchedule) ||
              (opt.value === 'now' && atTestLimit);
            return (
              <div key={opt.value} className={locked ? 'eezy-plan-gate-wrap' : ''}>
                <label className={`eezy-start-option ${form.startMode === opt.value ? 'eezy-start-option--active' : ''} ${locked ? 'eezy-start-option--locked' : ''}`}>
                  <input
                    type="radio"
                    name="startMode"
                    value={opt.value}
                    checked={form.startMode === opt.value}
                    onChange={() => !locked && update('startMode', opt.value)}
                    disabled={locked}
                  />
                  <div>
                    <div className="eezy-start-option__label">{opt.label()}</div>
                    <div className="eezy-start-option__desc">{opt.desc()}</div>
                  </div>
                </label>
                {locked && opt.value === 'now' && (
                  <div className="eezy-plan-gate-overlay">
                    <span className="eezy-plan-gate-overlay__text">
                      {sprintf(/* translators: %d: number of test slots on the plan. */ __('All %d test slots on your plan are in use. Finish or pause a running test to free one, or upgrade — meanwhile you can save this test as a draft and start it later.', 'spliteezy'), testsLimit)}
                    </span>
                  </div>
                )}
                {locked && opt.value === 'scheduled' && (
                  <div className="eezy-plan-gate-overlay">
                    <span className="eezy-plan-gate-overlay__lock">🔒</span>
                    <span className="eezy-plan-gate-overlay__text">{__('Available on', 'spliteezy')} <strong>{schedulingMinPlan}</strong> {__('plan and above', 'spliteezy')}</span>
                  </div>
                )}
              </div>
            );
          })}
        </div>

        {form.startMode === 'scheduled' && (
          <div className="eezy-datetime-field">
            <label className="eezy-form-label--sm">{__('Start date & time', 'spliteezy')}</label>
            <input
              type="datetime-local"
              value={form.scheduledAt}
              onChange={(e) => update('scheduledAt', e.target.value)}
              min={new Date().toISOString().slice(0, 16)}
              className="eezy-form-input eezy-form-input--auto"
            />
          </div>
        )}
      </div>

      {error && <div className="eezy-form-error">{error}</div>}

      <div className="eezy-card-footer">
        <button className="eezy-btn eezy-btn--ghost" disabled={submitting} onClick={onBack}>{__('← Back', 'spliteezy')}</button>
        <button className="eezy-btn eezy-btn--primary" disabled={submitting || !canSubmit} onClick={handleSubmit}>
          {submitLabel}
        </button>
      </div>
    </div>
  );
}
