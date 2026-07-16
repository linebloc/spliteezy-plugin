import { __ } from '@wordpress/i18n';

const STATUS_MAP = {
  active:    { label: () => __('Active', 'spliteezy'),    cls: 'eezy-badge--green'  },
  draft:     { label: () => __('Draft', 'spliteezy'),     cls: 'eezy-badge--gray'   },
  scheduled: { label: () => __('Scheduled', 'spliteezy'), cls: 'eezy-badge--blue'   },
  paused:    { label: () => __('Paused', 'spliteezy'),    cls: 'eezy-badge--yellow' },
  ended:     { label: () => __('Ended', 'spliteezy'),     cls: 'eezy-badge--purple' },
  winner:    { label: () => __('Winner', 'spliteezy'),    cls: 'eezy-badge--green'  },
};

export default function StatusBadge({ status }) {
  const entry = STATUS_MAP[status];
  const label = entry ? entry.label() : status;
  const cls = entry ? entry.cls : 'eezy-badge--gray';
  return <span className={`eezy-badge ${cls}`}>{label}</span>;
}
