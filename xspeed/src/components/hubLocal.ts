import { __ } from '../i18n';

/**
 * Why a local site has no Connect to Hub (xspeed-hub#370). The Hub refuses a
 * local or private site with the same words, so the two read alike.
 */
export function hubLocalMessage(): string {
  return __("xSpeed Hub needs a public URL to manage a site, so it can't connect a local or private one. To use AI on this site, connect your AI client to the plugin's MCP server directly.");
}
