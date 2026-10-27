import type { RewriteProbe } from './types';

/**
 * Whether the probe proved the static rewrite is not serving cache hits.
 *
 * A pending probe (nothing cached yet, or the result expired) and an
 * inconclusive one (blocked loopback, TLS error, a CDN answering) say nothing
 * about the server config, so neither may be read as a failure. Every
 * dashboard check that nags about the rewrite goes through here. (FBS-84012, #766)
 */
export function rewriteProvenInactive(probe: RewriteProbe | null | undefined): boolean {
  return !!probe && !probe.active && !probe.pending && !probe.inconclusive;
}
