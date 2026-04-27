// Flat stroke-only SVG icons — match Kirby/site aesthetic.
// Kept as raw strings so PanoViewer can assign via innerHTML without
// needing a DOM parser. Stroke/fill are set via CSS on `.pano-btn svg`.
export const ICONS = {
  fullscreen: `<svg viewBox="0 0 24 24"><path d="M4 9V4h5M20 9V4h-5M4 15v5h5M20 15v5h-5"/></svg>`,
  exitFull:   `<svg viewBox="0 0 24 24"><path d="M9 4v5H4M15 4v5h5M9 20v-5H4M15 20v-5h5"/></svg>`,
  plus:       `<svg viewBox="0 0 24 24"><path d="M12 5v14M5 12h14"/></svg>`,
  minus:      `<svg viewBox="0 0 24 24"><path d="M5 12h14"/></svg>`,
  rotate:     `<svg viewBox="0 0 24 24"><path d="M4 12a8 8 0 0 1 14-5.3M20 12a8 8 0 0 1-14 5.3M18 3v4h-4M6 21v-4h4"/></svg>`,
  home:       `<svg viewBox="0 0 24 24"><path d="M4 11 12 4l8 7v9h-5v-6h-6v6H4z"/></svg>`,
  dollhouse:  `<svg viewBox="0 0 24 24"><path d="M3 10h18M3 10 12 4l9 6M5 10v10h14V10M10 20v-5h4v5"/></svg>`,
  measure:    `<svg viewBox="0 0 24 24"><path d="M3 8h18v8H3zM7 8v3M11 8v4M15 8v3M19 8v4"/></svg>`,
};
