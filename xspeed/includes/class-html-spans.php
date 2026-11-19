<?php
/**
 * Offset-preserving HTML spans shared by section stamping and image ranking.
 *
 * @package XSpeed
 */

declare(strict_types=1);

namespace XSpeed;

defined( 'ABSPATH' ) || exit;

final class Html_Spans {
	private const MASKED_SPANS = '<script\b[^>]*>.*?</script>|<textarea\b[^>]*>.*?</textarea>|<noscript\b[^>]*>.*?</noscript>|<!--.*?-->';

	/** Keep byte offsets stable while hiding raw text and comments from tag walks. */
	public static function mask( string $html ): string {
		return (string) preg_replace_callback(
			'#' . self::MASKED_SPANS . '#is',
			static fn( array $m ): string => str_repeat( "\0", strlen( $m[0] ) ),
			$html
		);
	}

	/** Return the byte after a matching close, or null when containment is uncertain. */
	public static function element_end( string $masked, int $offset ): ?int {
		static $void     = array( 'area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input', 'link', 'meta', 'param', 'source', 'track', 'wbr' );
		static $implicit = array( 'p', 'li', 'dt', 'dd', 'td', 'th', 'tr', 'option', 'optgroup' );

		if ( ! preg_match( '#\G<([a-zA-Z][a-zA-Z0-9-]*)#', $masked, $open, 0, $offset ) ) {
			return null;
		}
		$cursor = self::tag_end( $masked, $offset );
		if ( null === $cursor ) {
			return null;
		}
		$stack = array( strtolower( (string) $open[1] ) );
		while ( preg_match( '#<(/?)([a-zA-Z][a-zA-Z0-9-]*)((?:"[^"]*"|\'[^\']*\'|[^>"\'])*)>#', $masked, $t, PREG_OFFSET_CAPTURE, $cursor ) ) {
			$cursor  = (int) $t[0][1] + strlen( (string) $t[0][0] );
			$closing = '' !== $t[1][0];
			$tag     = strtolower( (string) $t[2][0] );
			if ( in_array( $tag, $void, true ) ) {
				continue;
			}
			if ( ! $closing ) {
				if ( in_array( $tag, $implicit, true ) && end( $stack ) === $tag ) {
					array_pop( $stack );
				}
				$stack[] = $tag;
				continue;
			}
			if ( ! in_array( $tag, $stack, true ) ) {
				if ( in_array( $tag, $implicit, true ) ) {
					continue; // Stray </p>-style close: harmless, skip it.
				}
				return null; // A close for an ancestor: the element never closed.
			}
			while ( ! empty( $stack ) && array_pop( $stack ) !== $tag ) {
				continue;
			}
			if ( empty( $stack ) ) {
				return $cursor;
			}
		}
		return null;
	}

	/** Ignore angle brackets inside quoted attributes while finding an opening tag's end. */
	public static function tag_end( string $masked, int $offset ): ?int {
		if ( ! preg_match( '#\G<(/?)[a-zA-Z][a-zA-Z0-9-]*(?:"[^"]*"|\'[^\']*\'|[^>"\'])*>#', $masked, $m, 0, $offset ) ) {
			return null;
		}
		return $offset + strlen( $m[0] );
	}

}
