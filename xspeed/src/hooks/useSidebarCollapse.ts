import { useEffect, useState } from 'react';

const KEY = 'xspeed-sidebar-collapsed';

/**
 * Remembers whether the nav rail is collapsed to icons.
 *
 * Persisted like the theme (DESIGN.md §24.34) because it is a workspace
 * preference, not view state: someone who collapsed the rail to get more room
 * for a settings table wants it collapsed on the next screen and the next
 * visit, not reset by every navigation.
 *
 * Reads defensively — a private window or blocked site data makes
 * localStorage throw rather than return null, and the rail must still render.
 */
export function useSidebarCollapse() {
  const [collapsed, setCollapsed] = useState<boolean>(() => {
    try {
      return localStorage.getItem(KEY) === '1';
    } catch {
      return false;
    }
  });

  useEffect(() => {
    try {
      localStorage.setItem(KEY, collapsed ? '1' : '0');
    } catch {
      // Storage unavailable — the preference just won't survive the session.
    }
  }, [collapsed]);

  const toggle = () => setCollapsed((c) => !c);
  return { collapsed, toggle };
}
