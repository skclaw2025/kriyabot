/** Shown instantly while a workspace page loads its data, so navigation never feels frozen. */
export default function Loading() {
  return <div className="page-loading" role="status" aria-live="polite">
    <div className="page-loading-bar" />
    <div className="page-loading-title" />
    <div className="page-loading-line" />
    <div className="page-loading-grid"><span /><span /><span /></div>
    <span className="sr-only">Loading…</span>
  </div>;
}
