import React from 'react';
import * as ReactDOM from 'react-dom';
import { createRoot } from 'react-dom/client';
import { App } from './App';
import { OnboardingApp } from './onboarding/OnboardingApp';
import { installProPanelRegistry } from './components/proPanels';
import './styles.css';

// Expose React + ReactDOM on window so the Pro bundle (which lists
// them as externals in its Vite config) can resolve them at runtime.
// Critical: Pro panels rendered inside Free's tree MUST use the same
// React instance, otherwise hooks throw "Invalid hook call".
(window as unknown as { React: typeof React; ReactDOM: typeof ReactDOM }).React = React;
(window as unknown as { React: typeof React; ReactDOM: typeof ReactDOM }).ReactDOM = ReactDOM;

// Pro plugin's bundle (loaded after this script via enqueue dependency)
// calls window.XSpeedPro.registerPanel(slug, Component) to inject its
// dashboard surfaces without coupling the Free build to Pro source.
installProPanelRegistry();

// Body and #xspeed-app classes (xspeed-page, xspeed-dark, .dark) are set
// server-side from the xspeed_theme cookie — see Admin::user_theme() and
// Admin::admin_body_class. We don't touch them here, otherwise the
// initial paint would flash between light and dark.
const el = document.getElementById('xspeed-app');
if (el) {
  const isOnboarding =
    window.XSpeedConfig?.mode === 'onboarding' || el.classList.contains('xspeed-onboarding-host');

  createRoot(el).render(
    <React.StrictMode>{isOnboarding ? <OnboardingApp /> : <App />}</React.StrictMode>
  );
}
