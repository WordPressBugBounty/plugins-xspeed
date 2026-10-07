<?php
/**
 * Connect from the Hub: the site side (xspeed-hub#307).
 *
 * A person types this site's URL into the Hub. The Hub asks connect_info()
 * whether the site supports it, then sends the browser to the consent page
 * here. An admin approves; we hand the browser back to the configured Hub
 * with a single-use code bound to the Hub's PKCE challenge. The Hub then
 * redeems the code, with its verifier, on /mcp/attach for this site's token.
 *
 * @package XSpeed
 */

declare(strict_types=1);

namespace XSpeed\Modules\Mcp;

defined( 'ABSPATH' ) || exit;

/**
 * Consent page, single-use codes and their redemption.
 *
 * Why a code plus PKCE rather than the attach nonce: the code travels in a
 * browser URL (history, referrer, logs). Alone it is worth nothing; the
 * verifier that unlocks it never leaves the Hub's server.
 *
 * Why the browser only ever goes back to Mcp_Hub::hub_url(): taking the
 * return address from the request would let anyone who can get an admin to
 * click a link send this site's code to a server of their choosing.
 */
final class Mcp_Hub_Connect {

	/** The admin.php?page= slug of the consent screen. Not in any menu. */
	public const PAGE_SLUG = 'xspeed-hub-connect';

	/** Nonce action for the Approve / Deny form. */
	private const NONCE_ACTION = 'xspeed_hub_connect';

	/** Seconds a code stays redeemable. The Hub redeems it at once. */
	private const CODE_TTL = 300;

	/** Transient prefix; the key is the code's SHA-256, never the code. */
	private const CODE_KEY = 'xspeed_hubc_';

	/** What the Hub reads before sending anyone here. */
	public static function connect_info(): array {
		return array(
			'plugin'      => 'xspeed',
			// Bumped if the consent or redemption contract changes shape.
			'hub_connect' => 1,
			'site_url'    => Mcp_Hub::site_url_canonical(),
			'connect_url' => admin_url( 'admin.php?page=' . self::PAGE_SLUG ),
		);
	}

	/** A Hub `state`: opaque base64url, long enough to be unguessable. */
	public static function valid_state( string $state ): bool {
		return 1 === preg_match( '/^[A-Za-z0-9_-]{32,128}$/', $state );
	}

	/** An S256 challenge: base64url of a SHA-256, so exactly 43 characters. */
	public static function valid_challenge( string $challenge ): bool {
		return 1 === preg_match( '/^[A-Za-z0-9_-]{43}$/', $challenge );
	}

	/** The S256 challenge a verifier answers to (RFC 7636 §4.2). */
	public static function challenge_for( string $verifier ): string {
		return rtrim( strtr( base64_encode( hash( 'sha256', $verifier, true ) ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- base64url of a hash, per RFC 7636.
	}

	/**
	 * Issue a single-use code for the admin who approved.
	 *
	 * @param string $challenge The Hub's S256 challenge.
	 * @param int    $user_id   The approving admin, recorded on redemption.
	 */
	public static function issue_code( string $challenge, int $user_id ): string {
		$code = bin2hex( random_bytes( 32 ) );
		set_transient(
			self::CODE_KEY . hash( 'sha256', $code ),
			array(
				'challenge' => $challenge,
				'user_id'   => $user_id,
				'expires'   => time() + self::CODE_TTL,
			),
			self::CODE_TTL
		);
		return $code;
	}

	/**
	 * Redeem a code with its verifier. Single use: the code is gone after the
	 * first attempt, right or wrong, so it cannot be guessed at.
	 *
	 * @return array{site_url:string,site_token:string,user_id:int}|null
	 */
	public static function redeem_code( string $code, string $verifier ): ?array {
		if ( 1 !== preg_match( '/^[a-f0-9]{64}$/', $code ) ) {
			return null;
		}
		// RFC 7636 §4.1: 43–128 unreserved characters.
		if ( 1 !== preg_match( '/^[A-Za-z0-9._~-]{43,128}$/', $verifier ) ) {
			return null;
		}
		$key   = self::CODE_KEY . hash( 'sha256', $code );
		$entry = get_transient( $key );
		delete_transient( $key );
		if ( ! is_array( $entry ) || ! isset( $entry['challenge'], $entry['user_id'], $entry['expires'] ) ) {
			return null;
		}
		if ( (int) $entry['expires'] < time() ) {
			return null;
		}
		if ( ! hash_equals( (string) $entry['challenge'], self::challenge_for( $verifier ) ) ) {
			return null;
		}
		return Mcp_Hub::grant_attach_credential( (int) $entry['user_id'] );
	}

	/** Where the browser goes back to on the Hub, with the outcome in the query. */
	public static function return_url( array $args ): string {
		return Mcp_Hub::hub_url() . '/attach/return?' . http_build_query( $args );
	}

	/**
	 * Register the consent screen as a page with no menu entry. wp-admin
	 * refuses an unregistered ?page= with a 403 before admin_init runs.
	 */
	public static function register_page(): void {
		add_submenu_page(
			'',
			__( 'Connect to xSpeed Hub', 'xspeed' ),
			'',
			'manage_options',
			self::PAGE_SLUG,
			'__return_null'
		);
	}

	/**
	 * Serve the consent page on admin_init, before admin chrome is sent.
	 *
	 * admin_init runs after wp-admin's auth_redirect(), so a signed-out
	 * visitor has already been sent to wp-login and back by the time we look.
	 */
	public static function maybe_handle_page(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- routing only; the form below is nonce-checked.
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		if ( self::PAGE_SLUG !== $page ) {
			return;
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			self::emit_page(
				__( 'Not allowed', 'xspeed' ),
				'<p>' . esc_html__( 'Only an administrator of this site can connect it to xSpeed Hub. Sign in as an administrator and start again from the Hub.', 'xspeed' ) . '</p>',
				403
			);
		}

		$is_post = isset( $_SERVER['REQUEST_METHOD'] ) && 'POST' === strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) );
		// phpcs:disable WordPress.Security.NonceVerification -- GET renders; POST is verified below before anything is acted on.
		$source    = $is_post ? $_POST : $_GET;
		$state     = isset( $source['state'] ) ? sanitize_text_field( wp_unslash( $source['state'] ) ) : '';
		$challenge = isset( $source['code_challenge'] ) ? sanitize_text_field( wp_unslash( $source['code_challenge'] ) ) : '';
		$method    = isset( $source['code_challenge_method'] ) ? sanitize_text_field( wp_unslash( $source['code_challenge_method'] ) ) : 'S256';
		$account   = isset( $source['account'] ) ? sanitize_email( wp_unslash( $source['account'] ) ) : '';
		// phpcs:enable

		if ( ! self::valid_state( $state ) || ! self::valid_challenge( $challenge ) || 'S256' !== $method ) {
			self::emit_page(
				__( 'This link is not valid', 'xspeed' ),
				'<p>' . esc_html__( 'Start again from xSpeed Hub: Add site, then Connect with WordPress.', 'xspeed' ) . '</p>',
				400
			);
		}

		if ( $is_post ) {
			check_admin_referer( self::NONCE_ACTION );
			$approved = ! empty( $_POST['approve'] );
			$args     = $approved
				? array(
					'state'    => $state,
					'code'     => self::issue_code( $challenge, get_current_user_id() ),
					'site_url' => Mcp_Hub::site_url_canonical(),
				)
				: array(
					'state' => $state,
					'error' => 'access_denied',
				);
			// Not wp_safe_redirect: the Hub is off-site by design. The address is
			// the configured hub_url(), never anything from this request.
			wp_redirect( self::return_url( $args ) ); // phpcs:ignore WordPress.Security.SafeRedirect -- configured Hub URL, not request input.
			exit;
		}

		self::emit_consent( $state, $challenge, $account );
	}

	/** The Approve / Deny screen. */
	private static function emit_consent( string $state, string $challenge, string $account ): void {
		$hub_host  = (string) wp_parse_url( Mcp_Hub::hub_url(), PHP_URL_HOST );
		$site_host = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		$user      = wp_get_current_user();

		$rows  = '<div class="row"><span>' . esc_html__( 'Site', 'xspeed' ) . '</span><span>' . esc_html( $site_host ) . '</span></div>';
		$rows .= '<div class="row"><span>' . esc_html__( 'Connecting to', 'xspeed' ) . '</span><span>' . esc_html( $hub_host ) . '</span></div>';
		if ( '' !== $account ) {
			$rows .= '<div class="row"><span>' . esc_html__( 'Hub account', 'xspeed' ) . '</span><span>' . esc_html( $account ) . '</span></div>';
		}
		$rows .= '<div class="row"><span>' . esc_html__( 'Signed in as', 'xspeed' ) . '</span><span>' . esc_html( $user->user_login ) . '</span></div>';
		$rows .= '<div class="row"><span>' . esc_html__( 'Access', 'xspeed' ) . '</span><span>' . esc_html__( 'Purge caches, change settings and run speed tests on this site.', 'xspeed' ) . '</span></div>';

		$form  = '<form method="post" action="' . esc_url( admin_url( 'admin.php?page=' . self::PAGE_SLUG ) ) . '">';
		$form .= wp_nonce_field( self::NONCE_ACTION, '_wpnonce', true, false );
		$form .= '<input type="hidden" name="state" value="' . esc_attr( $state ) . '" />';
		$form .= '<input type="hidden" name="code_challenge" value="' . esc_attr( $challenge ) . '" />';
		$form .= '<input type="hidden" name="code_challenge_method" value="S256" />';
		$form .= '<div class="actions">';
		$form .= '<button class="deny" name="deny" value="1">' . esc_html__( 'Deny', 'xspeed' ) . '</button>';
		$form .= '<button class="approve" name="approve" value="1">' . esc_html__( 'Approve', 'xspeed' ) . '</button>';
		$form .= '</div></form>';

		self::emit_page(
			__( 'Connect this site to xSpeed Hub', 'xspeed' ),
			'<p class="sub">' . esc_html__( 'xSpeed Hub lets you and your AI tools manage this site’s cache alongside your other sites.', 'xspeed' ) . '</p>' . $rows . $form,
			200
		);
	}

	/**
	 * Emit a standalone page in the consent style and stop.
	 *
	 * @param string $title Plain text.
	 * @param string $body  Already escaped HTML.
	 * @param int    $status HTTP status.
	 */
	private static function emit_page( string $title, string $body, int $status ): void {
		status_header( $status );
		nocache_headers();
		header( 'Content-Type: text/html; charset=utf-8' );
		// The page carries a live nonce and a state; it must not be framed.
		header( 'X-Frame-Options: DENY' );
		echo '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . esc_html( $title ) . '</title>';
		echo '<style>' . McpModule::CONSENT_CSS . '</style></head><body><div class="card">'; // phpcs:ignore WordPress.Security.EscapeOutput -- static stylesheet constant.
		echo '<h1>' . esc_html( $title ) . '</h1>';
		echo $body; // phpcs:ignore WordPress.Security.EscapeOutput -- every part is escaped where it is built.
		echo '</div></body></html>';
		exit;
	}
}
