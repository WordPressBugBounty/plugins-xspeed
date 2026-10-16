<?php
/**
 * Owner approval for destructive MCP actions.
 *
 * A destructive action used to need a confirm_token that the refusal itself
 * handed out, so the same assistant could read it and send the call again:
 * a second round trip, but nobody decided anything. Now the first call files
 * a request, the site owner approves or denies it in wp-admin, and only an
 * approved request lets the exact same call run, once.
 *
 * @package XSpeed
 */

declare(strict_types=1);

namespace XSpeed\Modules\Mcp;

defined( 'ABSPATH' ) || exit;

/**
 * One option row per request (autoload off), plus a small autoloaded index
 * of open ids so the notice costs no query when nothing is waiting.
 */
final class Mcp_Approvals {

	/** Option-name prefix for one request row. */
	private const ROW_PREFIX = 'xspeed_mcp_approval_';

	/** Autoloaded list of open request ids; deleted when empty. */
	private const INDEX = 'xspeed_mcp_approvals';

	/** admin-post action for Approve / Deny. */
	public const ACTION = 'xspeed_mcp_approval';

	/** How long a request waits for the owner. */
	public const PENDING_TTL = 15 * MINUTE_IN_SECONDS;

	/** How long an approval stays usable once given. */
	public const APPROVED_TTL = 10 * MINUTE_IN_SECONDS;

	/** Open requests allowed at once, so a looping client can't flood wp-admin. */
	public const MAX_OPEN = 10;

	/** Per-user transient carrying the decision message across the redirect. */
	private const RESULT_KEY = 'xspeed_mcp_approval_result_';

	/** Arguments that steer the gate and never reach a handler. */
	private const CONTROL_ARGS = array( 'approval_id', 'confirm_token' );

	public static function boot(): void {
		add_action( 'admin_post_' . self::ACTION, array( __CLASS__, 'handle_decision' ) );
		// xSpeed's own screens re-route core notices to xspeed_admin_notices
		// (see Admin), so both hooks are needed for the request to show
		// everywhere an owner might be.
		add_action( 'admin_notices', array( __CLASS__, 'render_notices' ) );
		add_action( 'xspeed_admin_notices', array( __CLASS__, 'render_notices' ) );
	}

	/**
	 * Whether destructive actions wait for the owner.
	 *
	 * On by default. A filter rather than a setting on purpose: an MCP client
	 * with write access can change settings, and must not be able to switch
	 * off the approval that guards it.
	 */
	public static function required(): bool {
		/**
		 * Whether destructive MCP actions need the site owner's approval.
		 *
		 * Return false to fall back to the confirm_token round trip, for a
		 * site driven by unattended automation. Set it in code; it is not a
		 * setting an MCP client can reach.
		 *
		 * @param bool $required Default true.
		 */
		return (bool) apply_filters( 'xspeed_mcp_require_approval', true );
	}

	/**
	 * Fingerprint of one exact call: the tool and every argument except the
	 * gate's own. An approval covers that call and nothing else, so changing
	 * an option after the owner approved needs a new approval.
	 *
	 * @param string $tool Tool name.
	 * @param array  $args Decoded arguments.
	 */
	public static function fingerprint( string $tool, array $args ): string {
		return hash( 'sha256', $tool . "\n" . (string) wp_json_encode( self::canonical_args( $args ) ) );
	}

	/**
	 * Arguments with the gate's own removed and keys sorted at every level,
	 * so the same call always produces the same fingerprint.
	 *
	 * @param array $args Decoded arguments.
	 * @return array
	 */
	private static function canonical_args( array $args ): array {
		foreach ( self::CONTROL_ARGS as $key ) {
			unset( $args[ $key ] );
		}
		$sort = static function ( $value ) use ( &$sort ) {
			if ( ! is_array( $value ) ) {
				return $value;
			}
			if ( array_keys( $value ) !== range( 0, count( $value ) - 1 ) ) {
				ksort( $value );
			}
			return array_map( $sort, $value );
		};
		return $sort( $args );
	}

	/**
	 * The arguments as the owner will read them: secrets redacted, short.
	 *
	 * @param array $args Decoded arguments.
	 */
	private static function display_args( array $args ): string {
		$redact = static function ( $value ) use ( &$redact ) {
			if ( ! is_array( $value ) ) {
				return $value;
			}
			foreach ( $value as $key => $item ) {
				$value[ $key ] = is_string( $key ) && Mcp_Activity_Log::is_secret_key( $key ) ? '[redacted]' : $redact( $item );
			}
			return $value;
		};
		$args = $redact( self::canonical_args( $args ) );
		if ( empty( $args ) ) {
			return '';
		}
		$text = (string) wp_json_encode( $args, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		return strlen( $text ) > 300 ? substr( $text, 0, 297 ) . '...' : $text;
	}

	/**
	 * File a request for the owner, or return the one already open for the
	 * same call so a retry doesn't stack duplicates.
	 *
	 * @param string $tool      Tool name the call arrived on.
	 * @param string $canonical "<command> <action>" being asked for.
	 * @param array  $args      Decoded arguments.
	 * @param string $channel   mcp or broker.
	 * @return array{id:string,expires:int}|\WP_Error
	 */
	public static function request( string $tool, string $canonical, array $args, string $channel ) {
		$fingerprint = self::fingerprint( $tool, $args );
		$open        = self::open();

		foreach ( $open as $row ) {
			if ( ! hash_equals( (string) $row['fingerprint'], $fingerprint ) ) {
				continue;
			}
			if ( 'pending' === $row['status'] ) {
				return array(
					'id'      => $row['id'],
					'expires' => (int) $row['expires'],
				);
			}
			// A fresh request for a call the owner just denied would put the
			// same question back in front of them at once.
			if ( 'denied' === $row['status'] ) {
				return new \WP_Error(
					'xspeed_mcp_approval_denied',
					__( 'The site owner denied this request. Do not run it again unless they ask you to.', 'xspeed' ),
					array( 'status' => 403 )
				);
			}
		}

		self::sweep_expired();

		if ( count( $open ) >= self::MAX_OPEN ) {
			return new \WP_Error(
				'xspeed_mcp_approval_backlog',
				__( 'Too many requests are already waiting for the site owner. Wait for them to be approved, denied or expire, then try again.', 'xspeed' ),
				array( 'status' => 429 )
			);
		}

		$id  = strtolower( wp_generate_password( 16, false ) );
		$row = array(
			'id'          => $id,
			'tool'        => $tool,
			'action'      => $canonical,
			'args'        => self::display_args( $args ),
			'fingerprint' => $fingerprint,
			'channel'     => sanitize_key( $channel ),
			'status'      => 'pending',
			'created'     => time(),
			'expires'     => time() + self::PENDING_TTL,
			'decided_by'  => 0,
		);
		add_option( self::ROW_PREFIX . $id, wp_json_encode( $row ), '', 'no' );
		self::index_add( $id );

		return array(
			'id'      => $id,
			'expires' => (int) $row['expires'],
		);
	}

	/**
	 * Check an approval_id against the call it is sent with.
	 *
	 * With $consume, an approved request is claimed and can't be used again.
	 * Without it, nothing changes, so a caller with a second check to pass
	 * can look first and claim only when everything else has passed.
	 *
	 * @param string $id          approval_id from the caller.
	 * @param string $tool        Tool name.
	 * @param array  $args        Decoded arguments.
	 * @param bool   $consume     Claim an approved request.
	 * @return true|\WP_Error
	 */
	public static function check( string $id, string $tool, array $args, bool $consume = true ) {
		$row = self::valid_id( $id ) ? self::read( $id ) : null;
		if ( null === $row || (int) $row['expires'] < time() ) {
			return new \WP_Error(
				'xspeed_mcp_approval_invalid',
				__( 'That approval_id is unknown or has expired. Repeat the call without approval_id to ask the site owner again.', 'xspeed' ),
				array( 'status' => 400 )
			);
		}

		if ( ! hash_equals( (string) $row['fingerprint'], self::fingerprint( $tool, $args ) ) ) {
			return new \WP_Error(
				'xspeed_mcp_approval_mismatch',
				__( 'That approval_id was given for a different call. Repeat the exact call the site owner approved, with the same arguments.', 'xspeed' ),
				array( 'status' => 409 )
			);
		}

		if ( 'pending' === $row['status'] ) {
			return new \WP_Error(
				'xspeed_mcp_approval_pending',
				__( 'The site owner has not decided yet. Try the same call again after they approve it.', 'xspeed' ),
				array( 'status' => 409 )
			);
		}

		if ( 'approved' !== $row['status'] ) {
			// Left in place until it expires, so a retry keeps hearing "denied"
			// rather than an "unknown id" that reads like a reason to ask again.
			return new \WP_Error(
				'xspeed_mcp_approval_denied',
				__( 'The site owner denied this request. Do not run it again unless they ask you to.', 'xspeed' ),
				array( 'status' => 403 )
			);
		}

		if ( $consume && ! self::claim( $row ) ) {
			// Another call claimed it a moment ago: one approval, one run.
			return new \WP_Error(
				'xspeed_mcp_approval_invalid',
				__( 'That approval has already been used. Repeat the call without approval_id to ask the site owner again.', 'xspeed' ),
				array( 'status' => 400 )
			);
		}

		return true;
	}

	/**
	 * Record the owner's decision on a pending request.
	 *
	 * @param string $id      Request id.
	 * @param bool   $approve Approve or deny.
	 * @param int    $user_id The deciding user.
	 * @return array|null The updated request, or null when it can't be decided.
	 */
	public static function decide( string $id, bool $approve, int $user_id ): ?array {
		$row = self::valid_id( $id ) ? self::read( $id ) : null;
		if ( null === $row || 'pending' !== $row['status'] || (int) $row['expires'] < time() ) {
			return null;
		}
		unset( $row['_raw'] );
		$row['status']     = $approve ? 'approved' : 'denied';
		$row['decided_by'] = $user_id;
		$row['expires']    = time() + self::APPROVED_TTL;
		update_option( self::ROW_PREFIX . $id, wp_json_encode( $row ), false );

		// The owner's decision belongs in the same trail as the call it
		// answers.
		Mcp_Activity_Log::record(
			'mcp_approval',
			array(
				'request'  => $row['action'],
				'decision' => $row['status'],
			),
			true,
			'',
			'write',
			'dashboard'
		);

		return $row;
	}

	/**
	 * Requests still open: pending, or approved and not yet used. Expired
	 * ones are cleared on the way.
	 *
	 * @return array<int, array<string,mixed>>
	 */
	public static function open(): array {
		$ids = get_option( self::INDEX, array() );
		if ( ! is_array( $ids ) || empty( $ids ) ) {
			return array();
		}

		$open = array();
		$keep = array();
		foreach ( $ids as $id ) {
			$id  = (string) $id;
			$row = self::valid_id( $id ) ? self::read( $id ) : null;
			if ( null === $row ) {
				continue;
			}
			if ( (int) $row['expires'] < time() ) {
				delete_option( self::ROW_PREFIX . $id );
				continue;
			}
			$open[] = $row;
			$keep[] = $id;
		}

		if ( $keep !== array_values( array_map( 'strval', $ids ) ) ) {
			self::index_write( $keep );
		}

		return $open;
	}

	/** Approve / Deny from the notice. */
	public static function handle_decision(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to decide on AI requests.', 'xspeed' ), '', array( 'response' => 403 ) );
		}

		// The nonce is bound to the request id, so the id is read first.
		$id = isset( $_POST['id'] ) ? sanitize_key( wp_unslash( $_POST['id'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- needed to name the nonce action checked on the next line.
		check_admin_referer( self::ACTION . '_' . $id );
		$decision = isset( $_POST['decision'] ) ? sanitize_key( wp_unslash( $_POST['decision'] ) ) : '';

		$row = in_array( $decision, array( 'approve', 'deny' ), true )
			? self::decide( $id, 'approve' === $decision, get_current_user_id() )
			: null;

		if ( null === $row ) {
			$message = __( 'That request has expired or was already decided.', 'xspeed' );
		} elseif ( 'approved' === $row['status'] ) {
			$message = sprintf(
				/* translators: 1: the action, such as "xspeed migrate apply". 2: minutes. */
				__( 'Approved. The assistant can run "%1$s" once in the next %2$d minutes.', 'xspeed' ),
				$row['action'],
				(int) ( self::APPROVED_TTL / MINUTE_IN_SECONDS )
			);
		} else {
			$message = sprintf(
				/* translators: %s: the action, such as "xspeed migrate apply". */
				__( 'Denied. The assistant can\'t run "%s".', 'xspeed' ),
				$row['action']
			);
		}
		set_transient( self::RESULT_KEY . get_current_user_id(), $message, MINUTE_IN_SECONDS );

		$back = wp_get_referer();
		wp_safe_redirect( $back ? $back : admin_url( 'admin.php?page=xspeed' ) );
		exit;
	}

	/** The waiting requests, and the result of the last decision. */
	public static function render_notices(): void {
		// xSpeed screens relay both admin_notices and all_admin_notices to
		// xspeed_admin_notices, so this can be called twice on one page.
		static $done = false;
		if ( $done || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$done = true;

		$result_key = self::RESULT_KEY . get_current_user_id();
		$result     = get_transient( $result_key );
		if ( is_string( $result ) && '' !== $result ) {
			delete_transient( $result_key );
			printf( '<div class="notice notice-info is-dismissible"><p>%s</p></div>', esc_html( $result ) );
		}

		foreach ( self::open() as $row ) {
			if ( 'pending' !== $row['status'] ) {
				continue;
			}
			self::render_request( $row );
		}
	}

	/**
	 * One waiting request.
	 *
	 * @param array<string,mixed> $row Request row.
	 */
	private static function render_request( array $row ): void {
		$minutes_left = max( 1, (int) ceil( ( (int) $row['expires'] - time() ) / MINUTE_IN_SECONDS ) );
		$nonce        = wp_create_nonce( self::ACTION . '_' . $row['id'] );
		$form         = static function ( string $decision, string $label, string $class ) use ( $row, $nonce ): string {
			return sprintf(
				'<form method="post" action="%1$s" style="display:inline-block;margin-right:6px">'
					. '<input type="hidden" name="action" value="%2$s" />'
					. '<input type="hidden" name="id" value="%3$s" />'
					. '<input type="hidden" name="decision" value="%4$s" />'
					. '<input type="hidden" name="_wpnonce" value="%5$s" />'
					. '<input type="hidden" name="_wp_http_referer" value="%6$s" />'
					. '<button type="submit" class="button %7$s">%8$s</button>'
				. '</form>',
				esc_url( admin_url( 'admin-post.php' ) ),
				esc_attr( self::ACTION ),
				esc_attr( (string) $row['id'] ),
				esc_attr( $decision ),
				esc_attr( $nonce ),
				esc_attr( isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '' ),
				esc_attr( $class ),
				esc_html( $label )
			);
		};

		echo '<div class="notice notice-warning"><p><strong>';
		printf(
			/* translators: %s: the action, such as "xspeed migrate apply". */
			esc_html__( 'An AI assistant wants to run "%s" on this site, and it can\'t be undone.', 'xspeed' ),
			esc_html( (string) $row['action'] )
		);
		echo '</strong></p>';
		if ( '' !== $row['args'] ) {
			printf(
				'<p>%1$s <code>%2$s</code></p>',
				esc_html__( 'With:', 'xspeed' ),
				esc_html( (string) $row['args'] )
			);
		}
		printf(
			'<p>%s</p><p>',
			esc_html(
				sprintf(
					/* translators: %d: minutes until the request expires. */
					_n( 'Nothing happens until you approve. The request expires in %d minute.', 'Nothing happens until you approve. The request expires in %d minutes.', $minutes_left, 'xspeed' ),
					$minutes_left
				)
			)
		);
		echo $form( 'approve', __( 'Approve', 'xspeed' ), 'button-primary' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- every part is escaped in $form.
		echo $form( 'deny', __( 'Deny', 'xspeed' ), '' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- every part is escaped in $form.
		echo '</p></div>';
	}

	/**
	 * Delete expired request rows the index no longer names.
	 *
	 * The index is read, changed and written without a lock, so two requests
	 * filed together can drop an id. Its row would never show in the notice
	 * and never be cleared; this catches those.
	 */
	private static function sweep_expired(): void {
		global $wpdb;
		if ( ! is_object( $wpdb ) || ! method_exists( $wpdb, 'get_col' ) ) {
			return;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- a prefix scan of our own autoload-off rows; there is no API for it.
		$keys = $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( self::ROW_PREFIX ) . '%' ) );
		foreach ( (array) $keys as $key ) {
			$id  = substr( (string) $key, strlen( self::ROW_PREFIX ) );
			$row = self::valid_id( $id ) ? self::read( $id ) : null;
			if ( null === $row || (int) $row['expires'] < time() ) {
				delete_option( (string) $key );
			}
		}
	}

	/** Request ids are 16 lower-case alphanumerics. */
	private static function valid_id( string $id ): bool {
		return 1 === preg_match( '/^[a-z0-9]{16}$/', $id );
	}

	/**
	 * Read one row straight from the database.
	 *
	 * Not get_option(): with a persistent object cache it can keep answering
	 * "pending" after the owner approved, or "approved" after another call
	 * claimed it.
	 *
	 * @param string $id Request id.
	 * @return array<string,mixed>|null
	 */
	private static function read( string $id ): ?array {
		global $wpdb;
		if ( ! is_object( $wpdb ) ) {
			return null;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- the cache is exactly what this read must not trust.
		$raw = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", self::ROW_PREFIX . $id ) );
		$row = is_string( $raw ) ? json_decode( $raw, true ) : null;
		if ( ! is_array( $row ) || ! isset( $row['id'], $row['fingerprint'], $row['status'], $row['expires'] ) ) {
			return null;
		}
		// The stored text, so claim() can match the row exactly as read.
		$row['_raw'] = $raw;
		return $row;
	}

	/**
	 * Claim a row: delete it only if it still holds what was read. Exactly one
	 * concurrent caller gets a row affected, the same atomic claim the
	 * confirm_token uses.
	 *
	 * @param array<string,mixed> $row Row as read(), carrying `_raw`.
	 */
	private static function claim( array $row ): bool {
		global $wpdb;
		if ( ! is_object( $wpdb ) ) {
			return false;
		}
		$key = self::ROW_PREFIX . $row['id'];
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- the atomic claim needs rows_affected from the server.
		$claimed = $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s",
				$key,
				(string) ( $row['_raw'] ?? '' )
			)
		);
		wp_cache_delete( $key, 'options' );
		self::index_write( array_values( array_diff( self::index(), array( (string) $row['id'] ) ) ) );
		return 1 === (int) $claimed;
	}

	/** @return string[] */
	private static function index(): array {
		$ids = get_option( self::INDEX, array() );
		return is_array( $ids ) ? array_values( array_map( 'strval', $ids ) ) : array();
	}

	private static function index_add( string $id ): void {
		$ids   = self::index();
		$ids[] = $id;
		self::index_write( array_values( array_unique( $ids ) ) );
	}

	/** @param string[] $ids Open ids. */
	private static function index_write( array $ids ): void {
		if ( empty( $ids ) ) {
			delete_option( self::INDEX );
			return;
		}
		update_option( self::INDEX, $ids, true );
	}
}
