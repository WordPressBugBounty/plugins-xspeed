<?php
/**
 * Cache_Rules — per-post overrides to the global cache policy.
 *
 * Postmeta:
 *   _xspeed_no_cache       (bool) — when true, the post / page is never
 *                                    cached even if the global cache is
 *                                    on. Honored by Cache::should_cache.
 *   _xspeed_expiry_hours   (int)  — when > 0, overrides the global
 *                                    cache_expiry for this post. Honored
 *                                    by Cache::is_expired.
 *
 * Both metas are registered with WordPress core's REST API so the
 * block editor (and other automation) can edit them without going
 * through the classic-editor meta box.
 *
 * The engine reads via this class — never via raw get_post_meta in
 * Cache::should_cache — so future enhancements (caching the
 * resolution, exposing it through a filter, applying parent-page
 * inheritance) have one place to land.
 *
 * @package XSpeed
 */

declare(strict_types=1);

namespace XSpeed;

defined( 'ABSPATH' ) || exit;

final class Cache_Rules {

	public const META_NO_CACHE      = '_xspeed_no_cache';
	public const META_EXPIRY_HOURS  = '_xspeed_expiry_hours';

	/**
	 * Should the renderer skip caching this specific post?
	 *
	 * Returns false when there's no resolvable post (e.g. the current
	 * request is an archive, a 404, or fired before WP_Query has
	 * settled) — the global policy then applies as before.
	 */
	public static function should_skip_for_post( ?int $post_id ): bool {
		if ( ! $post_id || $post_id < 1 ) {
			$skip = false;
		} else {
			$skip = (bool) get_post_meta( $post_id, self::META_NO_CACHE, true );
		}
		/**
		 * Pro extension point — Pro's custom-rules engine can short-
		 * circuit caching for a post that doesn't have postmeta set
		 * but matches a pattern-based rule (category, tag, post_type,
		 * URL glob, etc.).
		 *
		 * @since 1.5.0
		 * @param bool     $skip    Current decision.
		 * @param int|null $post_id Post being evaluated (null when not in a singular context).
		 */
		return (bool) apply_filters( 'xspeed_cache_skip_for_post', $skip, $post_id );
	}

	/**
	 * Per-post expiry in seconds (already multiplied by HOUR_IN_SECONDS)
	 * or null when the global cache_expiry should apply.
	 */
	public static function expiry_override_seconds_for_post( ?int $post_id ): ?int {
		if ( ! $post_id || $post_id < 1 ) {
			$seconds = null;
		} else {
			$hours = (int) get_post_meta( $post_id, self::META_EXPIRY_HOURS, true );
			if ( $hours <= 0 ) {
				$seconds = null;
			} else {
				// Defensive clamp — matches the global field's bounds so any
				// hand-edited postmeta still falls in a sane range.
				$hours   = max( 1, min( 720, $hours ) );
				$seconds = $hours * HOUR_IN_SECONDS;
			}
		}
		/**
		 * Pro extension point — Pro's custom-rules engine can override the
		 * per-post expiry from a pattern rule. Return null to fall back to
		 * the global expiry, or an int of seconds.
		 *
		 * @since 1.5.0
		 * @param int|null $seconds Current override (null = no override).
		 * @param int|null $post_id Post being evaluated.
		 */
		$filtered = apply_filters( 'xspeed_cache_expiry_for_post', $seconds, $post_id );
		return is_int( $filtered ) ? $filtered : null;
	}

	/**
	 * Resolve the post ID for the current request — wp_query's queried
	 * object when it's a post, falling back to get_the_ID(). Returns
	 * null for non-post contexts (archives, search, 404, taxonomies).
	 */
	public static function current_post_id(): ?int {
		if ( ! function_exists( 'is_singular' ) ) {
			return null;
		}
		if ( ! is_singular() ) {
			return null;
		}
		$id = function_exists( 'get_queried_object_id' ) ? (int) get_queried_object_id() : 0;
		if ( $id < 1 && function_exists( 'get_the_ID' ) ) {
			$id = (int) get_the_ID();
		}
		return $id > 0 ? $id : null;
	}

	/**
	 * Register both postmetas with the REST API so external editors
	 * (Gutenberg sidebar, our future custom panel, third-party
	 * automation) can read + write them. Called once from
	 * Cache_Meta_Box::boot on `init`.
	 */
	public static function register_post_meta(): void {
		$post_types = self::supported_post_types();
		foreach ( $post_types as $type ) {
			register_post_meta(
				$type,
				self::META_NO_CACHE,
				array(
					'show_in_rest'  => true,
					'single'        => true,
					'type'          => 'boolean',
					'default'       => false,
					'auth_callback' => static function ( $allowed, $meta_key, $post_id ) {
						return current_user_can( 'edit_post', $post_id );
					},
				)
			);
			register_post_meta(
				$type,
				self::META_EXPIRY_HOURS,
				array(
					'show_in_rest'  => true,
					'single'        => true,
					'type'          => 'integer',
					'default'       => 0,
					'auth_callback' => static function ( $allowed, $meta_key, $post_id ) {
						return current_user_can( 'edit_post', $post_id );
					},
				)
			);
		}
	}

	/**
	 * Which post types get the per-page rules UI. Public post types
	 * only by default — private CPTs and revisions don't need it.
	 * Filterable so site code can opt a private CPT in.
	 *
	 * @return string[]
	 */
	public static function supported_post_types(): array {
		$types = function_exists( 'get_post_types' )
			? get_post_types( array( 'public' => true ), 'names' )
			: array( 'post', 'page' );
		// Strip 'attachment' — caching individual media items via this UI
		// is rarely useful and confuses non-technical users.
		unset( $types['attachment'] );

		/**
		 * Filter: xspeed_per_post_rules_post_types
		 *
		 * @param string[] $types Slugs of post types that show the
		 *                        per-page rules UI + accept the
		 *                        postmeta-driven overrides.
		 */
		return (array) apply_filters( 'xspeed_per_post_rules_post_types', array_values( $types ) );
	}
}
