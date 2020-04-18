<?php
/**
 * Jetpack's Pre-Connection JITM class.
 *
 * @package automattic/jetpack-jitm
 */

namespace Automattic\Jetpack\JITMS;

use Automattic\Jetpack\Assets;
use Automattic\Jetpack\Connection\Manager as Jetpack_Connection;
use Automattic\Jetpack\Connection\Client;
use Automattic\Jetpack\Assets\Logo as Jetpack_Logo;
use Automattic\Jetpack\Partner;
use Automattic\Jetpack\Tracking;
use Automattic\Jetpack\Connection\Manager;
use Automattic\Jetpack\JITMS\JITM;
use Automattic\Jetpack\JITMS\Engine;

/**
 * Jetpack just in time messaging through out the admin
 *
 * @since 5.6.0
 */
class Pre_Connection_JITM extends JITM {

	const PACKAGE_VERSION = '1.0'; // TODO: Keep in sync with version specified in composer.json.

	/**
	 * Tracking object.
	 *
	 * @var Automattic\Jetpack\Tracking
	 *
	 * @access private
	 */
	public $tracking;

	/**
	 * Determines if JITMs are enabled.
	 *
	 * @return bool Enable JITMs.
	 */
	public function register() {
		add_action( 'current_screen', array( $this, 'prepare_jitms' ) );
		return true;
	}

	/**
	 * Prepare actions according to screen and post type.
	 *
	 * @since 3.8.2
	 *
	 * @uses Jetpack_Autoupdate::get_possible_failures()
	 *
	 * @param \WP_Screen $screen WP Core's screen object.
	 */
	public function prepare_jitms( $screen ) {
		if ( ! in_array(
			$screen->id,
			array(
				'jetpack_page_stats',
				'jetpack_page_akismet-key-config',
				'admin_page_jetpack_modules',
			),
			true
		) ) {
			add_action( 'admin_enqueue_scripts', array( $this, 'jitm_enqueue_files' ) );
			add_action( 'admin_notices', array( $this, 'ajax_message' ) );
			add_action( 'edit_form_top', array( $this, 'ajax_message' ) );
		}
	}

	/**
	 * Asks the wpcom API for the current message to display keyed on query string and message path
	 *
	 * @param string $message_path The message path to ask for.
	 * @param string $query The query string originally from the front end.
	 * @param bool   $full_jp_logo_exists If there is a full Jetpack logo already on the page.
	 *
	 * @return array The JITM's to show, or an empty array if there is nothing to show
	 */
	public function get_messages( $message_path, $query, $full_jp_logo_exists ) {
		$jitm_engine = new Engine();

		$query_string = array();
		if ( isset( $query ) ) {
			foreach ( explode( ',', $query ) as $query_item ) {
				$query_item                     = explode( '=', $query_item );
				$query_string[ $query_item[0] ] = isset( $query_item[1] ) ? $query_item[1] : null;
			}
			unset( $query_item );
		}

		$mobile_browser = jetpack_is_mobile( 'smart' );
		$user           = wp_get_current_user();

		// Unauthenticated or invalid requests just bail.
		if ( ! $user ) {
			return array();
		}

		$user_roles = implode( ',', $user->roles );

		$envelopes = $jitm_engine->get_top_messages( $message_path, $user->ID, $user_roles, $query_string, $mobile_browser );

		if ( ! is_array( $envelopes ) ) {
			return array();
		}

		$hidden_jitms = \Jetpack_Options::get_option( 'hide_jitm' );

		unset( $envelopes['last_response_time'] );

		/**
		 * Allow adding your own custom JITMs after a set of JITMs has been received.
		 *
		 * @since 6.9.0
		 * @since 8.3.0 - Added Message path.
		 *
		 * @param array  $envelopes    array of existing JITMs.
		 * @param string $message_path The message path to ask for.
		 */
		$envelopes = apply_filters( 'jetpack_jitm_received_envelopes', $envelopes, $message_path );

		foreach ( $envelopes as $idx => &$envelope ) {

			$dismissed_feature = isset( $hidden_jitms[ $envelope->feature_class ] ) && is_array( $hidden_jitms[ $envelope->feature_class ] ) ? $hidden_jitms[ $envelope->feature_class ] : null;

			// If the this feature class has been dismissed and the request has not passed the ttl, skip it as it's been dismissed.
			if ( is_array( $dismissed_feature ) && ( time() - $dismissed_feature['last_dismissal'] < $envelope->expires || $dismissed_feature['number'] >= $envelope->max_dismissal ) ) {
				unset( $envelopes[ $idx ] );
				continue;
			}

			$this->tracking->record_user_event(
				'jitm_view_client',
				array(
					'jitm_id' => $envelope->id,
				)
			);

			$normalized_site_url = \Jetpack::build_raw_urls( get_home_url() );

			$url_params = array(
				'source' => "jitm-$envelope->id",
				'site'   => $normalized_site_url,
				'u'      => $user->ID,
			);

			// Get affiliate code and add it to the array of URL parameters.
			$aff = Partner::init()->get_partner_code( Partner::AFFILIATE_CODE );
			if ( '' !== $aff ) {
				$url_params['aff'] = $aff;
			}

			$envelope->url = add_query_arg( $url_params, 'https://jetpack.com/redirect/' );

			$envelope->jitm_stats_url = \Jetpack::build_stats_url( array( 'x_jetpack-jitm' => $envelope->id ) );

			if ( $envelope->cta['hook'] ) {
				$envelope->url = apply_filters( 'jitm_' . $envelope->cta['hook'], $envelope->url );
				unset( $envelope->cta['hook'] );
			}

			if ( isset( $envelope->content['hook'] ) ) {
				$envelope->content = apply_filters( 'jitm_' . $envelope->content['hook'], $envelope->content );
				unset( $envelope->content['hook'] );
			}

			// No point in showing an empty message.
			if ( empty( $envelope->content['message'] ) ) {
				unset( $envelopes[ $idx ] );
				continue;
			}

			switch ( $envelope->content['icon'] ) {
				case 'jetpack':
					$jetpack_logo              = new Jetpack_Logo();
					$envelope->content['icon'] = '<div class="jp-emblem">' . ( ( $full_jp_logo_exists ) ? $jetpack_logo->get_jp_emblem() : $jetpack_logo->get_jp_emblem_larger() ) . '</div>';
					break;
				case 'woocommerce':
					$envelope->content['icon'] = '<div class="jp-emblem"><svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" version="1.1" id="Layer_1" x="0px" y="0px" viewBox="0 0 168 100" xml:space="preserve" enable-background="new 0 0 168 100" width="50" height="30"><style type="text/css">
					.st0{clip-path:url(#SVGID_2_);enable-background:new    ;}
					.st1{clip-path:url(#SVGID_4_);}
					.st2{clip-path:url(#SVGID_6_);}
					.st3{clip-path:url(#SVGID_8_);fill:#8F567F;}
					.st4{clip-path:url(#SVGID_10_);fill:#FFFFFE;}
					.st5{clip-path:url(#SVGID_12_);fill:#FFFFFE;}
					.st6{clip-path:url(#SVGID_14_);fill:#FFFFFE;}
				</style><g><defs><polygon id="SVGID_1_" points="83.8 100 0 100 0 0.3 83.8 0.3 167.6 0.3 167.6 100 "/></defs><clipPath id="SVGID_2_"><use xlink:href="#SVGID_1_" overflow="visible"/></clipPath><g class="st0"><g><defs><rect id="SVGID_3_" width="168" height="100"/></defs><clipPath id="SVGID_4_"><use xlink:href="#SVGID_3_" overflow="visible"/></clipPath><g class="st1"><defs><path id="SVGID_5_" d="M15.6 0.3H152c8.6 0 15.6 7 15.6 15.6v52c0 8.6-7 15.6-15.6 15.6h-48.9l6.7 16.4L80.2 83.6H15.6C7 83.6 0 76.6 0 67.9v-52C0 7.3 7 0.3 15.6 0.3"/></defs><clipPath id="SVGID_6_"><use xlink:href="#SVGID_5_" overflow="visible"/></clipPath><g class="st2"><defs><rect id="SVGID_7_" width="168" height="100"/></defs><clipPath id="SVGID_8_"><use xlink:href="#SVGID_7_" overflow="visible"/></clipPath><rect x="-10" y="-9.7" class="st3" width="187.6" height="119.7"/></g></g></g></g></g><g><defs><path id="SVGID_9_" d="M8.4 14.5c1-1.3 2.4-2 4.3-2.1 3.5-0.2 5.5 1.4 6 4.9 2.1 14.3 4.4 26.4 6.9 36.4l15-28.6c1.4-2.6 3.1-3.9 5.2-4.1 3-0.2 4.9 1.7 5.6 5.7 1.7 9.1 3.9 16.9 6.5 23.4 1.8-17.4 4.8-30 9-37.7 1-1.9 2.5-2.9 4.5-3 1.6-0.1 3 0.3 4.3 1.4 1.3 1 2 2.3 2.1 3.9 0.1 1.2-0.1 2.3-0.7 3.3 -2.7 5-4.9 13.2-6.6 24.7 -1.7 11.1-2.3 19.8-1.9 26.1 0.1 1.7-0.1 3.2-0.8 4.5 -0.8 1.5-2 2.4-3.7 2.5 -1.8 0.1-3.6-0.7-5.4-2.5C52.4 66.7 47.4 57 43.7 44.1c-4.4 8.8-7.7 15.3-9.9 19.7 -4 7.7-7.5 11.7-10.3 11.9 -1.9 0.1-3.5-1.4-4.8-4.7 -3.5-9-7.3-26.3-11.3-52C7.1 17.3 7.5 15.8 8.4 14.5"/></defs><clipPath id="SVGID_10_"><use xlink:href="#SVGID_9_" overflow="visible"/></clipPath><rect x="-2.7" y="-0.6" class="st4" width="90.6" height="86.4"/></g><g><defs><path id="SVGID_11_" d="M155.6 25.2c-2.5-4.3-6.1-6.9-11-7.9 -1.3-0.3-2.5-0.4-3.7-0.4 -6.6 0-11.9 3.4-16.1 10.2 -3.6 5.8-5.3 12.3-5.3 19.3 0 5.3 1.1 9.8 3.3 13.6 2.5 4.3 6.1 6.9 11 7.9 1.3 0.3 2.5 0.4 3.7 0.4 6.6 0 12-3.4 16.1-10.2 3.6-5.9 5.3-12.4 5.3-19.4C159 33.4 157.9 28.9 155.6 25.2zM147 44.2c-0.9 4.5-2.7 7.9-5.2 10.1 -2 1.8-3.9 2.5-5.5 2.2 -1.7-0.3-3-1.8-4-4.4 -0.8-2.1-1.2-4.2-1.2-6.2 0-1.7 0.2-3.4 0.5-5 0.6-2.8 1.8-5.5 3.6-8.1 2.3-3.3 4.7-4.8 7.1-4.2 1.7 0.3 3 1.8 4 4.4 0.8 2.1 1.2 4.2 1.2 6.2C147.5 40.9 147.3 42.6 147 44.2z"/></defs><clipPath id="SVGID_12_"><use xlink:href="#SVGID_11_" overflow="visible"/></clipPath><rect x="109.6" y="6.9" class="st5" width="59.4" height="71.4"/></g><g><defs><path id="SVGID_13_" d="M112.7 25.2c-2.5-4.3-6.1-6.9-11-7.9 -1.3-0.3-2.5-0.4-3.7-0.4 -6.6 0-11.9 3.4-16.1 10.2 -3.5 5.8-5.3 12.3-5.3 19.3 0 5.3 1.1 9.8 3.3 13.6 2.5 4.3 6.1 6.9 11 7.9 1.3 0.3 2.5 0.4 3.7 0.4 6.6 0 12-3.4 16.1-10.2 3.5-5.9 5.3-12.4 5.3-19.4C116 33.4 114.9 28.9 112.7 25.2zM104.1 44.2c-0.9 4.5-2.7 7.9-5.2 10.1 -2 1.8-3.9 2.5-5.5 2.2 -1.7-0.3-3-1.8-4-4.4 -0.8-2.1-1.2-4.2-1.2-6.2 0-1.7 0.2-3.4 0.5-5 0.6-2.8 1.8-5.5 3.6-8.1 2.3-3.3 4.7-4.8 7.1-4.2 1.7 0.3 3 1.8 4 4.4 0.8 2.1 1.2 4.2 1.2 6.2C104.6 40.9 104.4 42.6 104.1 44.2z"/></defs><clipPath id="SVGID_14_"><use xlink:href="#SVGID_13_" overflow="visible"/></clipPath><rect x="66.7" y="6.9" class="st6" width="59.4" height="71.4"/></g></svg></div>';
					break;
				default:
					$envelope->content['icon'] = '';
					break;
			}

			$jetpack = \Jetpack::init();
			$jetpack->stat( 'jitm', $envelope->id . '-viewed-' . JETPACK__VERSION );
			$jetpack->do_stats( 'server_side' );
		}

		return $envelopes;
	}

	/**
	 * Is the current page a block editor page?
	 *
	 * @since 8.0.0
	 */
	public function is_gutenberg_page() {
		$current_screen = get_current_screen();
		return ( method_exists( $current_screen, 'is_block_editor' ) && $current_screen->is_block_editor() );
	}

}
