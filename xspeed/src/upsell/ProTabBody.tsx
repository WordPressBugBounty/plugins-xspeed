import { isPro } from './useTier';
import { ProDemo } from './ProDemo';

/**
 * A Pro tab's body: the real Pro sub-panel when licensed, a §24.12
 * ProDemo when not (FBS-83633).
 *
 * Host pages render the same tabs on every tier ("the sidebar doesn't
 * change on upgrade"). Before this, a locked Pro tab showed a bare
 * §24.11 LockedSection; now Free/unlicensed sees a demonstration of the
 * feature instead. Pass the licensed content as `children` (usually a
 * <ProSlot>) and the demo id via `demo`.
 *
 * Note: this reads tier once (not reactive) — the parent host already
 * subscribes to tier changes via useTier()/useHostTabs and re-renders
 * on activate/deactivate, so this re-evaluates with it.
 */
export function ProTabBody({ demo, children }: { demo: string; children: React.ReactNode }) {
  return isPro() ? <>{children}</> : <ProDemo id={demo} />;
}
