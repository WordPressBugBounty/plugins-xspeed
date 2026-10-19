import {
  Component,
  createContext,
  useContext,
  useSyncExternalStore,
  type ComponentType,
  type ErrorInfo,
  type ReactNode,
} from 'react';

/**
 * Runtime registry for add-on cards in the sidebar (DESIGN.md §24.49).
 *
 * An add-on registers a prop-less component through
 * `window.XSpeedPro.registerRailItem(id, Component)`; the Sidebar renders every
 * registered item above the tier slot, in the form the rail has room for
 * (`useRailForm`). Same shape as the status-item registry (statusItems.tsx), for the same
 * reason: the add-on's script runs after Free has rendered.
 */
export type RailItemComponent = ComponentType;

/**
 * How much room the rail has for a card (DESIGN.md §24.49).
 *
 * - `full`: the whole card. Rail 720px tall or more.
 * - `compact`: name, price and button. Rail 580–719px tall.
 * - `row`: one line that links to the add-on's page. Rail under 580px tall,
 *   or an expanded rail in a window under 1024px wide.
 * - `icon`: a 40px tile with the offer in a popover. Collapsed rail.
 */
export type RailForm = 'full' | 'compact' | 'row' | 'icon';

export const RAIL_FULL_MIN_HEIGHT = 720;
export const RAIL_COMPACT_MIN_HEIGHT = 580;

/**
 * Picks the form from the rail's own height, not the window's: the rail sits
 * under the WordPress admin bar and inside the dashboard's frame, so the two
 * differ by a different amount on every host.
 *
 * `desktop` is false below md, where the rail is the drawer. The drawer is
 * never icon-only, so only its height counts.
 *
 * Under 1024px the page needs the room, so the card goes no bigger than a
 * row. The design asked for the icon there, which assumed the rail collapses
 * too; in an expanded 240px rail a lone 40px tile reads as broken, and the
 * row says the same thing with its name on it.
 */
export function railFormFor({
  height,
  collapsed,
  desktop,
  wide,
}: {
  height: number;
  collapsed: boolean;
  desktop: boolean;
  wide: boolean;
}): RailForm {
  if (desktop && collapsed) return 'icon';
  if (desktop && !wide) return 'row';
  if (height >= RAIL_FULL_MIN_HEIGHT) return 'full';
  if (height >= RAIL_COMPACT_MIN_HEIGHT) return 'compact';
  return 'row';
}

const RailFormContext = createContext<RailForm>('full');

export const RailFormProvider = RailFormContext.Provider;

/**
 * The form a card should draw in. A card with no icon form returns null when
 * this is `icon`: a wide card in the 64px rail is worse than none.
 */
export function useRailForm(): RailForm {
  return useContext(RailFormContext);
}

export interface RailItem {
  id: string;
  Component: RailItemComponent;
  /** Bumped on every registration, so a replaced item also resets its boundary. */
  generation: number;
}

const REGISTRY = new Map<string, RailItem>();
const CHANGE_EVENT = 'xspeed:rail-items-changed';
let generation = 0;
// Must stay the same array until something changes, or useSyncExternalStore
// re-renders forever.
let snapshot: readonly RailItem[] = [];

export function registerRailItem(id: string, component: RailItemComponent): void {
  if (!id || !component) return;
  generation += 1;
  REGISTRY.set(id, { id, Component: component, generation });
  snapshot = Array.from(REGISTRY.values());
  window.dispatchEvent(new CustomEvent(CHANGE_EVENT, { detail: { id } }));
}

function subscribe(onChange: () => void): () => void {
  window.addEventListener(CHANGE_EVENT, onChange);
  return () => window.removeEventListener(CHANGE_EVENT, onChange);
}

function getSnapshot(): readonly RailItem[] {
  return snapshot;
}

export function useRailItems(): readonly RailItem[] {
  return useSyncExternalStore(subscribe, getSnapshot, getSnapshot);
}

/**
 * Contains a throwing add-on card. The card renders nothing and the rail stays
 * up; the sidebar is on every screen, so one broken card would otherwise take
 * navigation down with it.
 */
export class RailItemBoundary extends Component<{ id: string; children: ReactNode }, { failed: boolean }> {
  state = { failed: false };

  static getDerivedStateFromError(): { failed: boolean } {
    return { failed: true };
  }

  componentDidCatch(error: unknown, _info: ErrorInfo): void {
    console.warn(`[xspeed] Sidebar item "${this.props.id}" failed to render and was hidden.`, error);
  }

  render() {
    return this.state.failed ? null : this.props.children;
  }
}
