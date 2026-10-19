import { useEffect, useState } from 'react';

type Theme = 'light' | 'dark';
const KEY = 'xspeed-theme';
// Mirror the preference into a cookie so PHP can render the `.dark` class
// on the first paint, eliminating the light→dark flash.
const COOKIE = 'xspeed_theme';

function writeCookie(value: Theme) {
  // 1 year, root path, same-site lax so it travels with admin navigation.
  document.cookie = `${COOKIE}=${value}; path=/; max-age=31536000; SameSite=Lax`;
}

export function useTheme() {
  const [theme, setTheme] = useState<Theme>(() => {
    const saved = localStorage.getItem(KEY);
    return saved === 'dark' ? 'dark' : 'light';
  });

  useEffect(() => {
    const root = document.getElementById('xspeed-app');
    if (!root) return;
    root.classList.toggle('dark', theme === 'dark');
    document.body.classList.toggle('xspeed-dark', theme === 'dark');
    localStorage.setItem(KEY, theme);
    writeCookie(theme);
  }, [theme]);

  const toggle = () => setTheme((t) => (t === 'dark' ? 'light' : 'dark'));
  return { theme, toggle };
}
