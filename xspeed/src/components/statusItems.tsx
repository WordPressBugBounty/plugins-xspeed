import { Component, useSyncExternalStore, type ComponentType, type ErrorInfo, type ReactNode } from 'react';

/**
 * Runtime registry for add-on status items in the top bar (DESIGN.md §24.48).
 *
 * An add-on registers a prop-less component through
 * `window.XSpeedPro.registerStatusItem(id, Component)`; TopBar renders every
 * registered item just before the Hub chip. Same shape as the panel registry
 * in proPanels.tsx, and for the same reason: the add-on's script runs after
 * Free has rendered, so registrations have to reach a component that is
 * already mounted.
 */
export type StatusItemComponent = ComponentType;

export interface StatusItem {
  id: string;
  Component: StatusItemComponent;
  /**
   * Bumped on every registration. TopBar keys the item on it, so replacing a
   * component that threw also resets its error boundary.
   */
  generation: number;
}

// A Map keeps insertion order, and set() on an existing id keeps its slot, so
// a replaced item stays where it was instead of jumping to the end.
const REGISTRY = new Map<string, StatusItem>();
const CHANGE_EVENT = 'xspeed:status-items-changed';
let generation = 0;
// The snapshot must be the same array until something changes, or
// useSyncExternalStore re-renders forever. Rebuilt on each registration.
let snapshot: readonly StatusItem[] = [];

export function registerStatusItem(id: string, component: StatusItemComponent): void {
  if (!id || !component) return;
  generation += 1;
  REGISTRY.set(id, { id, Component: component, generation });
  snapshot = Array.from(REGISTRY.values());
  window.dispatchEvent(new CustomEvent(CHANGE_EVENT, { detail: { id } }));
}

/*
 * A useSyncExternalStore subscription rather than a listener added in
 * useEffect, for the reason given on usePanelsVersion(): an item registered
 * between TopBar's render and its effect would fire its event at nobody and
 * never show. useSyncExternalStore re-reads the snapshot when it subscribes.
 */
function subscribe(onChange: () => void): () => void {
  window.addEventListener(CHANGE_EVENT, onChange);
  return () => window.removeEventListener(CHANGE_EVENT, onChange);
}

function getSnapshot(): readonly StatusItem[] {
  return snapshot;
}

export function useStatusItems(): readonly StatusItem[] {
  return useSyncExternalStore(subscribe, getSnapshot, getSnapshot);
}

/**
 * Contains a throwing add-on item. The item renders nothing and the rest of
 * the bar stays up; without this, one broken add-on blanks the whole
 * dashboard, because TopBar sits above every screen.
 */
export class StatusItemBoundary extends Component<{ id: string; children: ReactNode }, { failed: boolean }> {
  state = { failed: false };

  static getDerivedStateFromError(): { failed: boolean } {
    return { failed: true };
  }

  componentDidCatch(error: unknown, _info: ErrorInfo): void {
    console.warn(`[xspeed] Status item "${this.props.id}" failed to render and was hidden.`, error);
  }

  render() {
    return this.state.failed ? null : this.props.children;
  }
}
