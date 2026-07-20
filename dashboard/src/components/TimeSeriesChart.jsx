import { __ } from '@wordpress/i18n';
import { useLayoutEffect, useMemo, useRef, useState } from 'react';

function valueAt(seriesObj, date) {
  return seriesObj.points.find((p) => p.date === date)?.value ?? 0;
}

function dayOverDayChange(seriesObj, date, prevDate) {
  if (!prevDate) {
    return null;
  }

  const prev = valueAt(seriesObj, prevDate);

  if (prev === 0) {
    return null;
  }

  const curr = valueAt(seriesObj, date);

  return ((curr - prev) / prev) * 100;
}

/**
 * Minimal SVG line chart — no charting library dependency.
 * Measures its container on mount and on resize, so the viewBox always matches
 * the actual pixel width. This avoids text/stroke distortion from
 * preserveAspectRatio="none" at varying screen widths.
 *
 * @param {{ series: Array<{ label: string, color: string, points: Array<{ date: string, value: number }> }>, unit: string }} props
 */
export default function TimeSeriesChart({ series = [], unit = '' }) {
  const containerRef = useRef(null);
  const [W, setW] = useState(640);
  const [hoverIndex, setHoverIndex] = useState(null);

  useLayoutEffect(() => {
    const el = containerRef.current;
    if (!el) return;
    const measure = () => setW(Math.floor(el.getBoundingClientRect().width) || 640);
    measure();
    const ro = new ResizeObserver(measure);
    ro.observe(el);
    return () => ro.disconnect();
  }, []);

  const H = 240;
  const PAD = { top: 16, right: 24, bottom: 36, left: 52 };

  const allPoints = series.flatMap((s) => s.points);
  const allValues = allPoints.map((p) => p.value);
  const maxValue  = Math.max(1, ...allValues);
  const dates     = [...new Set(allPoints.map((p) => p.date))].sort();

  // Round the axis top up to a whole tick step so every gridline and label
  // stays inside the chart (the SVG overflow is visible).
  const tickStep  = Math.ceil(maxValue / 4);
  const domainMax = Math.ceil(maxValue / tickStep) * tickStep;

  const scaleX = (i) => PAD.left + (i / Math.max(1, dates.length - 1)) * (W - PAD.left - PAD.right);
  const scaleY = (v) => H - PAD.bottom - (v / domainMax) * (H - PAD.top - PAD.bottom);
  const xStep = (W - PAD.left - PAD.right) / Math.max(1, dates.length - 1);

  const yTicks = useMemo(() => {
    const ticks = [];
    for (let t = 0; t <= domainMax; t += tickStep) {
      ticks.push(t);
    }
    return ticks;
  }, [domainMax, tickStep]);

  if (!dates.length) {
    return (
      <div className="eezy-chart eezy-chart--empty" ref={containerRef}>
        <span>{__('No data yet', 'spliteezy')}</span>
      </div>
    );
  }

  return (
    <div className="eezy-chart" ref={containerRef}>
      <svg
        viewBox={`0 0 ${W} ${H}`}
        width={W}
        height={H}
        className="eezy-chart__svg"
        onMouseLeave={() => setHoverIndex(null)}
      >
        {/* Y grid lines */}
        {yTicks.map((tick) => (
          <line
            key={tick}
            x1={PAD.left}
            x2={W - PAD.right}
            y1={scaleY(tick)}
            y2={scaleY(tick)}
            className="eezy-chart__grid"
          />
        ))}

        {/* Y axis labels */}
        {yTicks.map((tick) => (
          <text key={tick} x={PAD.left - 6} y={scaleY(tick) + 4} className="eezy-chart__tick" textAnchor="end">
            {tick}{unit}
          </text>
        ))}

        {/* X axis labels (show up to 7) */}
        {dates
          .filter((_, i) => i % Math.ceil(dates.length / 7) === 0)
          .map((date) => (
            <text
              key={date}
              x={scaleX(dates.indexOf(date))}
              y={H - PAD.bottom + 16}
              className="eezy-chart__tick"
              textAnchor="middle"
            >
              {new Date(date).toLocaleDateString(undefined, { month: 'short', day: 'numeric' })}
            </text>
          ))}

        {/* Series lines */}
        {series.map((s) => {
          const points = dates.map((d, i) => {
            const pt = s.points.find((p) => p.date === d);
            return `${scaleX(i)},${scaleY(pt ? pt.value : 0)}`;
          });

          return (
            <polyline
              key={s.label}
              points={points.join(' ')}
              fill="none"
              stroke={s.color}
              strokeWidth="2"
              strokeLinecap="round"
              strokeLinejoin="round"
            />
          );
        })}

        {/* Dots on data points */}
        {series.map((s) =>
          dates.map((d, i) => {
            const pt = s.points.find((p) => p.date === d);
            if (!pt) return null;
            return (
              <circle
                key={`${s.label}-${d}`}
                cx={scaleX(i)}
                cy={scaleY(pt.value)}
                r={hoverIndex === i ? '5.5' : '4'}
                fill={s.color}
              />
            );
          })
        )}

        {/* Hover guide line */}
        {hoverIndex !== null && (
          <line
            x1={scaleX(hoverIndex)}
            x2={scaleX(hoverIndex)}
            y1={PAD.top}
            y2={H - PAD.bottom}
            className="eezy-chart__grid"
            strokeOpacity="0.5"
          />
        )}

        {/* Hover hit areas — wider than the visible dots so hovering is forgiving */}
        {dates.map((d, i) => (
          <rect
            key={d}
            className="eezy-chart__hit"
            x={scaleX(i) - xStep / 2}
            y={PAD.top}
            width={xStep}
            height={H - PAD.top - PAD.bottom}
            onMouseEnter={() => setHoverIndex(i)}
          />
        ))}
      </svg>

      {/* Hover tooltip */}
      {hoverIndex !== null && (
        <div
          className="eezy-chart__tooltip"
          style={{
            left: `${(scaleX(hoverIndex) / W) * 100}%`,
            top: `${(PAD.top / H) * 100}%`,
            transform: `translate(${scaleX(hoverIndex) < W / 2 ? '8px' : 'calc(-100% - 8px)'}, 0)`,
          }}
        >
          <div className="eezy-chart__tooltip-date">
            {new Date(dates[hoverIndex]).toLocaleDateString(undefined, { weekday: 'short', month: 'short', day: 'numeric' })}
          </div>
          {series.map((s) => {
            const value = valueAt(s, dates[hoverIndex]);
            const delta = dayOverDayChange(s, dates[hoverIndex], dates[hoverIndex - 1]);

            return (
              <div key={s.label} className="eezy-chart__tooltip-row">
                <span className="eezy-chart__tooltip-dot" style={{ background: s.color }} />
                <span className="eezy-chart__tooltip-label">{s.label}</span>
                <span className="eezy-chart__tooltip-value">{value}{unit}</span>
                {delta !== null && (
                  <span className={`eezy-chart__tooltip-delta ${delta >= 0 ? 'eezy-chart__tooltip-delta--up' : 'eezy-chart__tooltip-delta--down'}`}>
                    {delta >= 0 ? '↑' : '↓'}{Math.abs(delta).toFixed(1)}%
                  </span>
                )}
              </div>
            );
          })}
          {hoverIndex > 0 && (
            <div className="eezy-chart__tooltip-note">{__('vs previous day', 'spliteezy')}</div>
          )}
        </div>
      )}

      {/* Legend
      <div className="eezy-chart__legend">
        {series.map((s) => (
          <span key={s.label} className="eezy-chart__legend-item">
            <span className="eezy-chart__legend-dot" style={{ background: s.color }} />
            {s.label}
          </span>
        ))}
      </div>*/}
    </div>
  );
}
