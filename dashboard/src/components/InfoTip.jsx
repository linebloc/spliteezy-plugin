import { __ } from '@wordpress/i18n';

export default function InfoTip({ text }) {
  return (
    <span className="eezy-info-tip">
      <button type="button" className="eezy-info-tip__trigger" aria-label={__('More info', 'spliteezy')}>
        <svg width="14" height="14" viewBox="0 0 20 20" fill="currentColor">
          <path fillRule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM9 9a1 1 0 112 0v4a1 1 0 11-2 0V9zm1-4a1 1 0 100 2 1 1 0 000-2z" clipRule="evenodd" />
        </svg>
      </button>
      <span className="eezy-info-tip__bubble" role="tooltip">{text}</span>
    </span>
  );
}
