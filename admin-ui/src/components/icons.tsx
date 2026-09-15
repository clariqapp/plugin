import React from '@wordpress/element';
import type { ReactNode } from 'react';

/**
 * Small, dependency-free line icons (no icon library in the plugin bundle).
 * All inherit `currentColor` and default to 1em so callers control size via CSS.
 */

type P = { className?: string };

const svg = (children: ReactNode) => ({ className }: P) => (
  <svg
    className={className}
    viewBox="0 0 24 24"
    fill="none"
    stroke="currentColor"
    strokeWidth={1.8}
    strokeLinecap="round"
    strokeLinejoin="round"
    aria-hidden="true"
  >
    {children}
  </svg>
);

export const CloudIcon = svg(
  <path d="M17.5 19a4.5 4.5 0 0 0 .5-8.97A6 6 0 0 0 6.34 9.4 4 4 0 0 0 7 17.32" />
);

export const ShieldIcon = svg(
  <>
    <path d="M12 3l7 3v5c0 4.5-3 7.5-7 9-4-1.5-7-4.5-7-9V6l7-3z" />
    <path d="M9.2 12l1.9 1.9 3.7-3.9" />
  </>
);

export const CheckIcon = svg(<path d="M4.5 12.5l5 5 10-11" />);

export const LinkIcon = svg(
  <>
    <path d="M9 15l6-6" />
    <path d="M10.5 6.5l1.2-1.2a4 4 0 0 1 5.7 5.7l-1.2 1.2" />
    <path d="M13.5 17.5l-1.2 1.2a4 4 0 0 1-5.7-5.7l1.2-1.2" />
  </>
);

export const PlugIcon = svg(
  <>
    <path d="M9 3v5M15 3v5" />
    <path d="M7 8h10v3a5 5 0 0 1-10 0V8z" />
    <path d="M12 16v5" />
  </>
);

export const SyncIcon = svg(
  <>
    <path d="M4 12a8 8 0 0 1 13.7-5.7L20 8" />
    <path d="M20 4v4h-4" />
    <path d="M20 12a8 8 0 0 1-13.7 5.7L4 16" />
    <path d="M4 20v-4h4" />
  </>
);

export const PulseIcon = svg(
  <path d="M3 12h4l2.5-7 5 14L17 12h4" />
);

export const CopyIcon = svg(
  <>
    <rect x="9" y="9" width="11" height="11" rx="2" />
    <path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1" />
  </>
);

export const EyeIcon = svg(
  <>
    <path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7-10-7-10-7z" />
    <circle cx="12" cy="12" r="3" />
  </>
);

export const EyeOffIcon = svg(
  <>
    <path d="M17.9 17.9A10.6 10.6 0 0 1 12 19c-6.5 0-10-7-10-7a18.4 18.4 0 0 1 5-5.9" />
    <path d="M9.9 4.2A9.1 9.1 0 0 1 12 4c6.5 0 10 7 10 7a18.5 18.5 0 0 1-2.2 3.2" />
    <path d="M9.5 9.5a3 3 0 0 0 4.2 4.2" />
    <path d="M2 2l20 20" />
  </>
);

export const InfoIcon = svg(
  <>
    <circle cx="12" cy="12" r="9" />
    <path d="M12 11v5M12 8h.01" />
  </>
);

export const AlertIcon = svg(
  <>
    <path d="M12 3l9 16H3l9-16z" />
    <path d="M12 10v4M12 17h.01" />
  </>
);

export const SparkIcon = svg(
  <path d="M12 3l1.8 5.2L19 10l-5.2 1.8L12 17l-1.8-5.2L5 10l5.2-1.8L12 3z" />
);

export const ArrowRightIcon = svg(<path d="M5 12h14M13 6l6 6-6 6" />);
