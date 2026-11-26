<?php
/**
 * ReviewWarden: stops fake and spam product reviews and warns you about review attacks.
 *
 * @package ReviewWarden
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Features and screens.
 */
class ReviewWarden_Features {

	const SECRET = 'reviewwarden_secret';
	const ARMED  = 'reviewwarden_armed';
	const FIELD  = 'reviewwarden_pass';
	const MIN    = 3;
	const MAX    = 43200;

	/**
	 * Decision for the review being saved.
	 *
	 * @var array|null
	 */
	private static $pending = null;

	/* ------------------------------------------------------------------
	 * Frame contract
	 * ---------------------------------------------------------------- */

	/**
	 * Tabs.
	 *
	 * @return array
	 */
	public static function tabs() {
		return array(
			'overview' => __( 'Overview', 'reviewwarden' ),
			'reviews'  => __( 'Reviews', 'reviewwarden' ),
			'settings' => __( 'Settings', 'reviewwarden' ),
		);
	}

	/**
	 * Tagline.
	 *
	 * @return string
	 */
	public static function tagline() {
		return __( 'Real reviews from real customers. Spam stays out.', 'reviewwarden' );
	}

	/**
	 * Sections.
	 *
	 * @return array
	 */
	public static function sections() {
		return array( 'settings' => array( 'pass_on', 'hold_unverified', 'block_links', 'per_day', 'words', 'bomb_alert', 'bomb_min', 'notify_to', 'retention_days', 'delete_data' ) );
	}

	/**
	 * Messages.
	 *
	 * @return array
	 */
	public static function messages() {
		return array();
	}

	/**
	 * Pro intro.
	 *
	 * @return string
	 */
	public static function pro_intro() {
		return __( 'Get more real reviews with friendly review requests after delivery, and see how every product is rated.', 'reviewwarden' );
	}

	/**
	 * Pro points.
	 *
	 * @return array
	 */
	public static function pro_points() {
		return array(
			__( 'Review request emails a few days after the order is completed', 'reviewwarden' ),
			__( 'Ratings by product, with verified buyer share', 'reviewwarden' ),
			__( 'Alerts for review attacks and low ratings on Slack, Discord, Telegram or any webhook', 'reviewwarden' ),
			__( 'Export reviews to CSV', 'reviewwarden' ),
		);
	}

	/**
	 * CSS elsewhere.
	 *
	 * @param string $hook Hook.
	 * @return bool
	 */
	public static function needs_css( $hook ) {
		unset( $hook );
		return false;
	}

	/**
	 * Install.
	 *
	 * @return void
	 */
	public static function install() {}

	/**
	 * Activation.
	 *
	 * @return void
	 */
	public static function activate() {}

	/* ------------------------------------------------------------------
	 * Hooks
	 * ---------------------------------------------------------------- */

	/**
	 * Runtime hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'route' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'script' ) );
		add_filter( 'pre_comment_approved', array( __CLASS__, 'judge' ), 20, 2 );
		add_action( 'comment_post', array( __CLASS__, 'after_post' ), 30, 3 );
	}

	/**
	 * Admin hooks.
	 *
	 * @return void
	 */
	public static function admin_hooks() {}

	/* ------------------------------------------------------------------
	 * Invisible pass
	 * ---------------------------------------------------------------- */

	/**
	 * Signing key.
	 *
	 * @return string
	 */
	private static function key() {
		$secret = get_option( self::SECRET );
		if ( ! is_string( $secret ) || strlen( $secret ) < 32 ) {
			$secret = wp_generate_password( 64, true, true );
			update_option( self::SECRET, $secret, false );
		}
		return wp_salt( 'nonce' ) . $secret;
	}

	/**
	 * Visitor's user agent.
	 *
	 * @return string
	 */
	private static function ua() {
		$ua = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '';
		return substr( hash( 'sha256', $ua ), 0, 10 );
	}

	/**
	 * Visitor's IP, hashed.
	 *
	 * @return string
	 */
	public static function ip_hash() {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		$ip = (string) apply_filters( 'reviewwarden_client_ip', $ip );
		return substr( hash_hmac( 'sha256', $ip, wp_salt( 'auth' ) ), 0, 32 );
	}

	/**
	 * Daily counter key for this visitor.
	 *
	 * @return string
	 */
	private static function limit_key() {
		return 'reviewwarden_day_' . self::ip_hash();
	}

	/**
	 * New pass.
	 *
	 * @return string
	 */
	public static function issue() {
		$data = time() . '.' . bin2hex( random_bytes( 8 ) ) . '.' . self::ua();
		return $data . '.' . hash_hmac( 'sha256', $data, self::key() );
	}

	/**
	 * Check a pass.
	 *
	 * @param string $pass Pass.
	 * @return string valid|missing|malformed|forged|expired|too_fast|agent
	 */
	public static function verify( $pass ) {
		if ( ! is_string( $pass ) || '' === $pass ) {
			return 'missing';
		}
		$parts = explode( '.', $pass );
		if ( 4 !== count( $parts ) || ! ctype_digit( $parts[0] ) ) {
			return 'malformed';
		}
		if ( ! hash_equals( hash_hmac( 'sha256', $parts[0] . '.' . $parts[1] . '.' . $parts[2], self::key() ), $parts[3] ) ) {
			return 'forged';
		}
		$age = time() - (int) $parts[0];
		if ( $age > self::MAX ) {
			return 'expired';
		}
		if ( $age < self::MIN ) {
			return 'too_fast';
		}
		if ( ! hash_equals( self::ua(), $parts[2] ) ) {
			return 'agent';
		}
		return 'valid';
	}

	/**
	 * REST route that hands out passes.
	 *
	 * @return void
	 */
	public static function route() {
		register_rest_route(
			'reviewwarden/v1',
			'/pass',
			array(
				'methods'             => 'GET',
				'permission_callback' => '__return_true',
				'callback'            => array( __CLASS__, 'rest_pass' ),
			)
		);
	}

	/**
	 * REST callback.
	 *
	 * @return WP_REST_Response
	 */
	public static function rest_pass() {
		$response = new WP_REST_Response( array( 'pass' => self::issue() ) );
		$response->header( 'Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0' );
		return $response;
	}

	/**
	 * Small script on product pages.
	 *
	 * @return void
	 */
	public static function script() {
		if ( ! ReviewWarden_Settings::on( 'pass_on' ) || ! function_exists( 'is_product' ) || ! is_product() ) {
			return;
		}
		$file = REVIEWWARDEN_PATH . 'assets/js/reviewwarden-pass.js';
		wp_enqueue_script( 'reviewwarden-pass', REVIEWWARDEN_URL . 'assets/js/reviewwarden-pass.js', array(), REVIEWWARDEN_VERSION . '.' . (int) filemtime( $file ), array( 'in_footer' => true, 'strategy' => 'defer' ) );
		wp_localize_script(
			'reviewwarden-pass',
			'reviewwardenData',
			array(
				'passUrl' => esc_url_raw( rest_url( 'reviewwarden/v1/pass' ) ),
				'field'   => self::FIELD,
			)
		);
	}

	/**
	 * Pass decision. Only enforced after one real visitor sent a valid pass,
	 * so a caching or theme problem can never lock out real customers.
	 *
	 * @return string '' or a reason.
	 */
	private static function pass_problem() {
		// WordPress checks its own comment form; this field only carries our signed pass.
		$posted = isset( $_POST[ self::FIELD ] ) ? sanitize_text_field( wp_unslash( $_POST[ self::FIELD ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$result = self::verify( $posted );
		if ( 'valid' === $result ) {
			if ( ! get_option( self::ARMED ) ) {
				update_option( self::ARMED, time(), false );
			}
			return '';
		}
		if ( ! get_option( self::ARMED ) && in_array( $result, array( 'missing', 'expired' ), true ) ) {
			return '';
		}
		return 'pass_' . $result;
	}

	/* ------------------------------------------------------------------
	 * Judging reviews
	 * ---------------------------------------------------------------- */

	/**
	 * Is this a product review?
	 *
	 * @param array $data Comment data.
	 * @return bool
	 */
	private static function is_review( $data ) {
		$post_id = isset( $data['comment_post_ID'] ) ? (int) $data['comment_post_ID'] : 0;
		$type    = isset( $data['comment_type'] ) ? (string) $data['comment_type'] : '';
		return $post_id && 'product' === get_post_type( $post_id ) && in_array( $type, array( '', 'comment', 'review' ), true ) && empty( $data['comment_parent'] );
	}

	/**
	 * Decide a new review.
	 *
	 * @param int|string|WP_Error $approved Current decision.
	 * @param array               $data     Comment data.
	 * @return int|string|WP_Error
	 */
	public static function judge( $approved, $data ) {
		self::$pending = null;
		if ( is_wp_error( $approved ) || 'trash' === $approved || ! self::is_review( $data ) || current_user_can( 'moderate_comments' ) ) {
			return $approved;
		}
		$reason = '';
		if ( ReviewWarden_Settings::on( 'pass_on' ) ) {
			$reason = self::pass_problem();
		}
		$text = isset( $data['comment_content'] ) ? (string) $data['comment_content'] : '';
		if ( '' === $reason && ReviewWarden_Settings::on( 'block_links' ) && ( preg_match( '#https?://|www\.|\[url#i', $text ) || ! empty( $data['comment_author_url'] ) ) ) {
			$reason = 'links';
		}
		if ( '' === $reason ) {
			$words = array_filter( array_map( 'trim', preg_split( '/[\r\n,]+/', (string) ReviewWarden_Settings::get( 'words' ) ) ) );
			foreach ( $words as $word ) {
				if ( '' !== $word && false !== stripos( $text . ' ' . ( isset( $data['comment_author'] ) ? $data['comment_author'] : '' ), $word ) ) {
					$reason = 'words';
					break;
				}
			}
		}
		$post_id = (int) $data['comment_post_ID'];
		if ( '' !== $reason ) {
			self::record( 'spam', $post_id, $reason );
			return 'spam';
		}
		$limit = (int) ReviewWarden_Settings::get( 'per_day' );
		if ( $limit > 0 ) {
			if ( (int) get_transient( self::limit_key() ) >= $limit ) {
				self::record( 'limited', $post_id, 'per_day' );
				return new WP_Error( 'reviewwarden_limit', __( 'Thank you! You have already sent several reviews today. Please try again tomorrow.', 'reviewwarden' ), 429 );
			}
		}
		$email    = isset( $data['comment_author_email'] ) ? (string) $data['comment_author_email'] : '';
		$user_id  = isset( $data['user_id'] ) ? (int) $data['user_id'] : 0;
		$verified = function_exists( 'wc_customer_bought_product' ) && wc_customer_bought_product( $email, $user_id, $post_id );
		self::$pending = array(
			'post'     => $post_id,
			'verified' => $verified,
		);
		if ( ! $verified && ReviewWarden_Settings::on( 'hold_unverified' ) && 1 === (int) $approved ) {
			self::$pending['held'] = true;
			return 0;
		}
		return $approved;
	}

	/**
	 * Log a stopped review.
	 *
	 * @param string $type    spam|limited.
	 * @param int    $post_id Product.
	 * @param string $reason  Reason.
	 * @return void
	 */
	private static function record( $type, $post_id, $reason ) {
		ReviewWarden_Log::add(
			$type,
			array(
				'object_id' => $post_id,
				'user_id'   => get_current_user_id(),
				'field'     => $reason,
				'detail'    => get_the_title( $post_id ),
			)
		);
	}

	/**
	 * After the review is saved: log it with its rating.
	 *
	 * @param int        $comment_id Comment.
	 * @param int|string $approved   Status.
	 * @param array      $data       Data.
	 * @return void
	 */
	public static function after_post( $comment_id, $approved, $data ) {
		unset( $data );
		if ( null === self::$pending || 'spam' === $approved ) {
			return;
		}
		$key = self::limit_key();
		set_transient( $key, (int) get_transient( $key ) + 1, DAY_IN_SECONDS );
		$rating = (int) get_comment_meta( $comment_id, 'rating', true );
		if ( ! $rating && isset( $_POST['rating'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- read only, saved by WooCommerce.
			$rating = min( 5, absint( $_POST['rating'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- read only, saved by WooCommerce.
		}
		ReviewWarden_Log::add(
			! empty( self::$pending['held'] ) ? 'held' : 'review',
			array(
				'object_id' => self::$pending['post'],
				'user_id'   => get_current_user_id(),
				'field'     => self::$pending['verified'] ? 'verified' : 'unverified',
				'amount'    => $rating,
				'new'       => (string) $comment_id,
				'detail'    => get_the_title( self::$pending['post'] ),
			)
		);
		self::$pending = null;
	}

	/* ------------------------------------------------------------------
	 * Review attack watch
	 * ---------------------------------------------------------------- */

	/**
	 * Hourly: many low ratings in 24 hours?
	 *
	 * @return void
	 */
	public static function hourly() {
		if ( ! ReviewWarden_Settings::on( 'bomb_alert' ) ) {
			return;
		}
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- own table, report.
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT object_id, MAX(detail) AS name, COUNT(*) AS n FROM %i WHERE type IN (%s, %s) AND amount BETWEEN 1 AND 2 AND created_at >= %s GROUP BY object_id', ReviewWarden_Log::table(), 'review', 'held', gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS ) ), ARRAY_A );
		$warned = get_option( 'reviewwarden_bombs', array() );
		$warned = is_array( $warned ) ? $warned : array();
		$site   = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		foreach ( (array) $rows as $row ) {
			$id = (int) $row['object_id'];
			if ( (int) $row['n'] < (int) ReviewWarden_Settings::get( 'bomb_min' ) || ( isset( $warned[ $id ] ) && $warned[ $id ] > time() - DAY_IN_SECONDS ) ) {
				continue;
			}
			$warned[ $id ] = time();
			ReviewWarden_Log::add(
				'bomb',
				array(
					'object_id' => $id,
					'user_id'   => 0,
					'amount'    => (int) $row['n'],
					'detail'    => $row['name'],
				)
			);
			self::mail(
				/* translators: 1: product, 2: site */
				sprintf( __( 'Many low ratings for %1$s on %2$s', 'reviewwarden' ), $row['name'], $site ),
				sprintf(
					/* translators: 1: number, 2: product */
					__( '%1$s reviews with one or two stars were left for %2$s in the last 24 hours. This can be a review attack or a real problem with the product. Check the reviews before you approve more.', 'reviewwarden' ),
					number_format_i18n( (int) $row['n'] ),
					$row['name']
				) . "\n\n" . admin_url( 'edit.php?post_type=product&page=product-reviews' )
			);
		}
		update_option( 'reviewwarden_bombs', $warned, false );
	}

	/**
	 * Daily.
	 *
	 * @return void
	 */
	public static function daily() {}

	/**
	 * Email the owner from the store's address.
	 *
	 * @param string $subject Subject.
	 * @param string $body    Body.
	 * @return bool
	 */
	public static function mail( $subject, $body ) {
		$to      = ReviewWarden_Settings::get( 'notify_to' );
		$to      = '' !== $to ? array_map( 'trim', explode( ',', $to ) ) : array( get_option( 'admin_email' ) );
		$headers = array();
		$from    = get_option( 'woocommerce_email_from_address' );
		if ( is_email( $from ) ) {
			$site      = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
			$headers[] = 'From: ' . str_replace( array( "\r", "\n", '<', '>' ), '', (string) get_option( 'woocommerce_email_from_name', $site ) ) . ' <' . $from . '>';
		}
		return wp_mail( $to, $subject, $body, $headers );
	}

	/* ------------------------------------------------------------------
	 * Screens
	 * ---------------------------------------------------------------- */

	/**
	 * Label for a reason.
	 *
	 * @param string $type  Type.
	 * @param string $field Field.
	 * @return string
	 */
	public static function label( $type, $field ) {
		$labels = array(
			'pass_missing'   => __( 'Sent without visiting the page', 'reviewwarden' ),
			'pass_malformed' => __( 'Fake form data', 'reviewwarden' ),
			'pass_forged'    => __( 'Fake form data', 'reviewwarden' ),
			'pass_expired'   => __( 'Page open too long', 'reviewwarden' ),
			'pass_too_fast'  => __( 'Sent too fast for a person', 'reviewwarden' ),
			'pass_agent'     => __( 'Browser changed mid-way', 'reviewwarden' ),
			'links'          => __( 'Contains links', 'reviewwarden' ),
			'words'          => __( 'Blocked word', 'reviewwarden' ),
			'per_day'        => __( 'Too many reviews today', 'reviewwarden' ),
		);
		if ( 'bomb' === $type ) {
			return __( 'Many low ratings', 'reviewwarden' );
		}
		if ( 'held' === $type ) {
			return __( 'Waiting for approval, not a buyer', 'reviewwarden' );
		}
		if ( 'review' === $type ) {
			return 'verified' === $field ? __( 'Verified buyer', 'reviewwarden' ) : __( 'Published', 'reviewwarden' );
		}
		return isset( $labels[ $field ] ) ? $labels[ $field ] : $field;
	}

	/**
	 * Average rating of new reviews.
	 *
	 * @param int $secs Window.
	 * @return float
	 */
	private static function average( $secs ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- dashboard figure.
		return (float) $wpdb->get_var( $wpdb->prepare( 'SELECT COALESCE(AVG(amount),0) FROM %i WHERE type IN (%s, %s) AND amount > 0 AND created_at >= %s', ReviewWarden_Log::table(), 'review', 'held', gmdate( 'Y-m-d H:i:s', time() - $secs ) ) );
	}

	/**
	 * Stars text.
	 *
	 * @param float $n Rating.
	 * @return string
	 */
	private static function stars( $n ) {
		$n = (int) round( $n );
		return $n > 0 ? str_repeat( '★', $n ) . str_repeat( '☆', 5 - $n ) : '';
	}

	/**
	 * Overview.
	 *
	 * @return void
	 */
	public static function render_overview() {
		$a     = 'ReviewWarden_Admin';
		$armed = (bool) get_option( self::ARMED );
		if ( ! ReviewWarden_Settings::on( 'pass_on' ) ) {
			$a::banner( 'is-learning', __( 'Spam check is off', 'reviewwarden' ), __( 'Turn on the invisible check in Settings to stop review bots.', 'reviewwarden' ) );
		} elseif ( ! $armed ) {
			$a::banner( 'is-learning', __( 'Getting ready', 'reviewwarden' ), __( 'The invisible check turns fully on after the first real visitor leaves a review, so your theme and cache are known to work with it.', 'reviewwarden' ) );
		} else {
			$a::banner( 'is-on', __( 'Your reviews are protected', 'reviewwarden' ), __( 'Bots, links and blocked words go to spam. Real customers notice nothing.', 'reviewwarden' ) );
		}
		$avg = self::average( 30 * DAY_IN_SECONDS );
		$a::figures(
			array(
				__( 'Spam stopped, 7 days', 'reviewwarden' ) => number_format_i18n( ReviewWarden_Log::count( array( 'spam', 'limited' ), WEEK_IN_SECONDS ) ),
				__( 'New reviews, 7 days', 'reviewwarden' )  => number_format_i18n( ReviewWarden_Log::count( array( 'review', 'held' ), WEEK_IN_SECONDS ) ),
				__( 'Waiting for you', 'reviewwarden' )      => number_format_i18n( (int) get_comments( array( 'post_type' => 'product', 'status' => 'hold', 'count' => true ) ) ),
				__( 'Average rating, 30 days', 'reviewwarden' ) => $avg > 0 ? number_format_i18n( $avg, 1 ) : '–',
			)
		);
		$rows = array();
		foreach ( ReviewWarden_Log::recent( array( 'types' => array( 'spam', 'limited', 'bomb' ), 'limit' => 25 ) ) as $row ) {
			$rows[] = array(
				'when'      => $a::ago( $row['created_at'] ),
				'product'   => $row['detail'],
				'html_what' => $a::tag( 'bomb' === $row['type'] ? 'is-would' : 'is-blocked', self::label( $row['type'], $row['field'] ) ),
			);
		}
		$a::panel_open( __( 'Recently stopped', 'reviewwarden' ), __( 'Stopped reviews are in Comments, Spam, if you want to look.', 'reviewwarden' ) );
		if ( $rows ) {
			$a::table(
				array(
					'when'      => __( 'When', 'reviewwarden' ),
					'product'   => __( 'Product', 'reviewwarden' ),
					'html_what' => __( 'Why', 'reviewwarden' ),
				),
				$rows
			);
		} else {
			$a::empty_state( __( 'Nothing stopped yet', 'reviewwarden' ), __( 'Spam reviews and review attacks show up here.', 'reviewwarden' ) );
		}
		$a::panel_close();
	}

	/**
	 * Reviews tab.
	 *
	 * @return void
	 */
	public static function render_reviews() {
		$a    = 'ReviewWarden_Admin';
		$rows = array();
		foreach ( ReviewWarden_Log::recent( array( 'types' => array( 'review', 'held' ), 'limit' => 50 ) ) as $row ) {
			$comment = get_comment( (int) $row['new_value'] );
			$rows[]  = array(
				'when'       => $a::ago( $row['created_at'] ),
				'product'    => $row['detail'],
				'stars'      => self::stars( (float) $row['amount'] ),
				'html_buyer' => 'verified' === $row['field'] ? $a::tag( 'is-ok', __( 'Verified buyer', 'reviewwarden' ) ) : $a::tag( '', __( 'Not a buyer', 'reviewwarden' ) ),
				'html_state' => $comment
					? '<a href="' . esc_url( admin_url( 'comment.php?action=editcomment&c=' . $comment->comment_ID ) ) . '">' . esc_html( '1' === $comment->comment_approved ? __( 'Published', 'reviewwarden' ) : ( '0' === $comment->comment_approved ? __( 'Waiting', 'reviewwarden' ) : __( 'Removed', 'reviewwarden' ) ) ) . '</a>'
					: esc_html__( 'Deleted', 'reviewwarden' ),
			);
		}
		$a::panel_open( __( 'Latest reviews', 'reviewwarden' ), __( 'The 50 newest reviews that passed the checks.', 'reviewwarden' ) );
		if ( $rows ) {
			$a::table(
				array(
					'when'       => __( 'When', 'reviewwarden' ),
					'product'    => __( 'Product', 'reviewwarden' ),
					'stars'      => __( 'Rating', 'reviewwarden' ),
					'html_buyer' => __( 'Buyer', 'reviewwarden' ),
					'html_state' => __( 'Status', 'reviewwarden' ),
				),
				$rows
			);
		} else {
			$a::empty_state( __( 'No reviews yet', 'reviewwarden' ), __( 'New product reviews appear here.', 'reviewwarden' ) );
		}
		$a::panel_close();
	}

	/**
	 * Settings.
	 *
	 * @return void
	 */
	public static function render_settings() {
		$a = 'ReviewWarden_Admin';
		$a::form_open( 'settings' );
		$a::panel_open( __( 'Stop spam', 'reviewwarden' ) );
		$a::row(
			__( 'Invisible check', 'reviewwarden' ),
			__( 'Bots that post without opening the page go straight to spam. No puzzles for customers.', 'reviewwarden' ),
			static function () use ( $a ) {
				$a::switch_field( 'pass_on', __( 'Check that a real visitor wrote the review', 'reviewwarden' ) );
				$a::switch_field( 'block_links', __( 'Send reviews with links to spam', 'reviewwarden' ) );
			}
		);
		$a::row(
			__( 'Blocked words', 'reviewwarden' ),
			__( 'One per line. Reviews containing any of them go to spam.', 'reviewwarden' ),
			static function () use ( $a ) {
				$a::text( 'words', __( 'Blocked words', 'reviewwarden' ), "casino\ncrypto", 'textarea' );
			}
		);
		$a::row(
			__( 'Limit', 'reviewwarden' ),
			__( 'Per visitor, per day. Use 0 for no limit.', 'reviewwarden' ),
			static function () use ( $a ) {
				/* translators: %1$s: number */
				$a::sentence( __( 'At most %1$s reviews from one visitor each day.', 'reviewwarden' ), array( 'per_day' => __( 'Reviews', 'reviewwarden' ) ) );
			}
		);
		$a::panel_close();
		$a::panel_open( __( 'Real customers first', 'reviewwarden' ) );
		$a::row(
			__( 'Not a buyer', 'reviewwarden' ),
			__( 'Reviews from people who never bought the product wait for your approval. Buyers are published as usual.', 'reviewwarden' ),
			static function () use ( $a ) {
				$a::switch_field( 'hold_unverified', __( 'Hold reviews from people who did not buy', 'reviewwarden' ) );
			}
		);
		$a::row(
			__( 'Review attacks', 'reviewwarden' ),
			__( 'Many one or two star reviews in a day can mean a competitor attack or a real product problem.', 'reviewwarden' ),
			static function () use ( $a ) {
				$a::switch_field( 'bomb_alert', __( 'Email me about many low ratings', 'reviewwarden' ) );
				/* translators: %1$s: number */
				$a::sentence( __( 'When one product gets %1$s or more in 24 hours.', 'reviewwarden' ), array( 'bomb_min' => __( 'Reviews', 'reviewwarden' ) ) );
				$a::text( 'notify_to', __( 'Email addresses', 'reviewwarden' ), get_option( 'admin_email' ) );
			}
		);
		$a::panel_close();
		$a::panel_open( __( 'Data', 'reviewwarden' ) );
		$a::row(
			__( 'History', 'reviewwarden' ),
			__( 'Only product IDs, ratings and reasons are kept. No names or emails.', 'reviewwarden' ),
			static function () use ( $a ) {
				/* translators: %1$s: days */
				$a::sentence( __( 'Keep the activity log for %1$s days.', 'reviewwarden' ), array( 'retention_days' => __( 'Days', 'reviewwarden' ) ) );
				$a::switch_field( 'delete_data', __( 'Delete settings and data when the plugin is deleted', 'reviewwarden' ) );
			}
		);
		$a::panel_close();
		$a::form_close();
	}
}
