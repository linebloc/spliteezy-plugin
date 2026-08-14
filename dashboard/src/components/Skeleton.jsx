import { __ } from '@wordpress/i18n';

/**
 * Placeholders shaped like the pages they stand in for. The list skeleton is
 * also printed by src/Admin/views/test-list.php so the page paints before the
 * bundle boots — keep the two markups in step, or the mount will shift.
 */

function SrOnly() {
  return <span className="screen-reader-text">{__('Loading…', 'spliteezy')}</span>;
}

export function TestListSkeleton() {
  return (
    <div className="eezy-wrap eezy-skeleton" role="status" aria-busy="true">
      <SrOnly />

      <div className="eezy-header">
        <span className="eezy-skel eezy-skel--logo" />
        <span className="eezy-skel eezy-skel--brand" />
        <span className="eezy-skel eezy-skel--badge" />
        <span className="eezy-skel eezy-skel--btn eezy-skel--push" />
      </div>

      <div className="eezy-stat-grid">
        {[0, 1, 2].map((i) => (
          <div className="eezy-stat-card" key={i}>
            <div className="eezy-stat-card__header">
              <span className="eezy-skel eezy-skel--stat-label" />
              <span className="eezy-skel eezy-skel--stat-icon" />
            </div>
            <span className="eezy-skel eezy-skel--stat-value" />
            <span className="eezy-skel eezy-skel--stat-sub" />
            <span className="eezy-skel eezy-skel--stat-track" />
          </div>
        ))}
      </div>

      <div className="eezy-section-header">
        <span className="eezy-skel eezy-skel--section-title" />
        <div className="eezy-skeleton__actions">
          <span className="eezy-skel eezy-skel--btn" />
          <span className="eezy-skel eezy-skel--btn" />
        </div>
      </div>

      <div className="eezy-tabs">
        {[0, 1, 2, 3, 4].map((i) => <span className="eezy-skel eezy-skel--tab" key={i} />)}
      </div>

      <div className="eezy-table-wrapper">
        <table className="eezy-table eezy-table--skeleton">
          <thead>
            <tr>
              {[0, 1, 2, 3, 4, 5, 6].map((i) => <th key={i}><span className="eezy-skel" /></th>)}
            </tr>
          </thead>
          <tbody>
            {[0, 1, 2, 3, 4].map((row) => (
              <tr key={row}>
                <td><span className="eezy-skel" /><span className="eezy-skel eezy-skel--sub" /></td>
                <td><span className="eezy-skel" /></td>
                <td><span className="eezy-skel" /><span className="eezy-skel eezy-skel--sub" /></td>
                <td><span className="eezy-skel" /></td>
                <td><span className="eezy-skel" /></td>
                <td><span className="eezy-skel" /></td>
                <td><span className="eezy-skel" /></td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </div>
  );
}

export function TestDetailSkeleton() {
  return (
    <div className="eezy-wrap eezy-skeleton" role="status" aria-busy="true">
      <SrOnly />

      <div className="eezy-header">
        <span className="eezy-skel eezy-skel--btn" />
        <div className="eezy-header__title-group">
          <span className="eezy-skel eezy-skel--page-title" />
          <span className="eezy-skel eezy-skel--page-url" />
        </div>
        <div className="eezy-test-actions">
          <span className="eezy-skel eezy-skel--btn" />
          <span className="eezy-skel eezy-skel--btn" />
        </div>
      </div>

      <div className="eezy-info-cards">
        {[0, 1, 2, 3].map((i) => (
          <div className="eezy-info-card" key={i}>
            <span className="eezy-skel eezy-skel--card-label" />
            <span className="eezy-skel eezy-skel--card-value" />
            <span className="eezy-skel eezy-skel--card-sub" />
          </div>
        ))}
      </div>

      <div className="eezy-card eezy-card--flush">
        <div className="eezy-chart-section">
          <span className="eezy-skel eezy-skel--card-title" />
          <div className="eezy-metric-tiles">
            {[0, 1, 2].map((i) => (
              <div className="eezy-metric-tile" key={i}>
                <span className="eezy-skel eezy-skel--tile-label" />
                <span className="eezy-skel eezy-skel--tile-value" />
              </div>
            ))}
          </div>
          <span className="eezy-skel eezy-skel--chart" />
        </div>

        <div className="eezy-skeleton__perf">
          <div className="eezy-perf-header">
            {[0, 1, 2, 3, 4, 5].map((i) => <span className="eezy-skel" key={i} />)}
          </div>
          {[0, 1].map((row) => (
            <div className="eezy-perf-row" key={row}>
              {[0, 1, 2, 3, 4, 5].map((i) => <span className="eezy-skel" key={i} />)}
            </div>
          ))}
        </div>
      </div>
    </div>
  );
}
