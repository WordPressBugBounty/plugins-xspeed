/**
 * The Overview landing view is a standalone, top-level screen — NOT a Module.
 * It has no entry in the /modules payload, so it can't live in SIDEBAR_GROUPS
 * (groupSidebarModules drops groups with no resolved module). Instead App.tsx
 * treats this slug as a pseudo-section and Sidebar.tsx renders a pinned row for
 * it. Kept as a shared constant so the router and the sidebar never drift.
 */
export const OVERVIEW_SLUG = 'overview';
