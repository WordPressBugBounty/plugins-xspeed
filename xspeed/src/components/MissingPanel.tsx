import { Callout } from './Callout';
import { usePageLoaded } from './proPanels';
import { __ } from '../i18n';

/**
 * What to show when a module declares a custom panel that is not registered.
 *
 * Before the page has loaded, Pro's script may simply not have run yet
 * (it registers its panels after Free renders), so this is a neutral
 * placeholder. Once the page has loaded and the panel is still missing,
 * it never will arrive: that is a bug every time, so say so (§13 danger
 * Callout) rather than falling back to the module's raw schema fields,
 * which read as a deliberate, finished screen.
 *
 * Callers re-render through usePanelsVersion(), so a panel that registers
 * at any point replaces whatever this rendered.
 */
export function MissingPanel() {
  const loaded = usePageLoaded();
  if (!loaded) {
    return (
      <div role="status" aria-label={__('Loading')} className="space-y-2.5 py-2">
        <div className="h-3 w-1/3 rounded bg-line motion-safe:animate-pulse" />
        <div className="h-2.5 w-full rounded bg-panel motion-safe:animate-pulse" />
        <div className="h-2.5 w-5/6 rounded bg-panel motion-safe:animate-pulse" />
        <span className="sr-only">{__('Loading…')}</span>
      </div>
    );
  }
  return (
    <Callout
      tone="danger"
      title={__('This screen did not load.')}
      body={__('Reload the page. If it happens again, update xSpeed and xSpeed Cache Pro to their latest versions.')}
    />
  );
}
