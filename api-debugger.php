<?php
/**
 * Plugin Name: API Debugger
 * Plugin URI:  https://wp-rocket.me/
 * Description: Log API calls, easy to debug license responses.
 * Author:      Ahmed Saeed
 * Author URI:  https://wp-rocket.me/
 * Version:     0.1
 * License:     GPLv2 or later (license.txt)
 */

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

class API_Debugger {

	private static $_instance = null;

    private $settings = [];

	public static function get_instance() {
		if ( is_null( self::$_instance ) ) {
			self::$_instance = new self();
		}

		return self::$_instance;
	}

	public function add_hooks() {
        $this->settings = get_option( 'api_log_settings', [
            'urls' => [
                'wp-rocket.me',
            ],
        ] );

		add_action( 'init', [ $this, 'register_post_type' ] );
        add_action('admin_menu', [ $this, 'register_settings_page' ], 9);
        add_action( 'admin_init', [ $this, 'register_settings_fields' ] );

		add_action( 'http_api_debug', [ $this, 'log_api' ], 10, 5 );
		add_action( 'add_meta_boxes', [ $this, 'add_meta_box' ] );
	}

	public function register_post_type() {
		$labels = array(
			'name'          => _x( 'API Logs', 'Post type general name', 'api-debugger' ),
			'singular_name' => _x( 'Log', 'Post type singular name', 'api-debugger' ),
		);
		$args = array(
			'labels'             => $labels,
			'description'        => 'API logs.',
			'public'             => false,
			'publicly_queryable' => false,
			'show_ui'            => true,
			'show_in_menu'       => true,
			'query_var'          => true,
			'capability_type'    => 'post',
			'has_archive'        => false,
			'hierarchical'       => false,
			'menu_position'      => 9999,
			'supports'           => array( 'title' ),
			'show_in_rest'       => true
		);

		register_post_type( 'api_log', $args );
	}

    public function register_settings_page() {
        add_submenu_page( 'edit.php?post_type=api_log', 'API Debugger Settings', 'Settings', 'manage_options', 'api-debugger-settings', [ $this, 'settings_page' ] );
    }

    public function settings_page() {
        echo '<div class="wrap">';

        printf( '<h1>%s</h1>', __('API Debugger Settings', 'api-debugger' ) );

        echo '<form method="post" action="options.php">';

        settings_fields( 'api-debugger-settings' );

        do_settings_sections( 'api-debugger-settings-page' );

        submit_button();

        echo '</form></div>';
    }

    public function register_settings_fields() {
        register_setting(
            'api-debugger-settings', // Option group
            'api_log_settings', // Option name
            [ $this, 'sanitize' ] // Sanitize
        );

        add_settings_section(
            'api-debugger-settings-match-section', // ID
            __('Match Settings', 'api-debugger'), // Title
            array( $this, 'print_match_section' ), // Callback
            'api-debugger-settings-page' // Page
        );

        add_settings_field(
            'urls', // ID
            __('Match Urls', 'api-debugger'), // Title
            array( $this, 'print_match_urls_field' ), // Callback
            'api-debugger-settings-page', // Page
            'api-debugger-settings-match-section' // Section
        );
    }

    public function print_match_section() {
        esc_html_e( 'Decide which API calls will be caught.', 'api-debugger' );
    }

    public function print_match_urls_field() {
        $urls = is_array( $this->settings['urls'] ) ? implode( "\n", $this->settings['urls'] ) : '';
        ?>
        <textarea name="api_log_settings[urls]" id="api_debugger_match_urls" cols="30" rows="10" style="width: 100%;"><?php echo esc_textarea( $urls ); ?></textarea>
        <?php
    }

    public function sanitize( $settings ) {
        $new_settings = [];
        if ( ! empty( $settings['urls'] ) ) {
            $new_settings['urls'] = is_array( $settings['urls'] ) ? $settings['urls'] : explode( "\n", $settings['urls'] );
            $new_settings['urls'] = array_map( 'sanitize_text_field', (array) $new_settings['urls'] );
        }
        return $new_settings;
    }

	public function log_api( $response, $context, $class, $parsed_args, $url ) {
        if ( ! empty( $this->settings['urls'] ) ) {
            $urls = array_map( 'preg_quote', $this->settings['urls'] );
            $urls_regex = implode( '|', $urls );
        } else {
            $urls_regex = '.*';
        }

		if ( ! preg_match( '/' . $urls_regex . '/i', $url ) ) {
			return;
		}

		$success = ! is_wp_error( $response );

		// Create post object
		$log_post = array(
			'post_title'    => esc_url( $url ) . ' - ' . ( $success ? 'Success' : 'Failure' ),
			'post_status'   => 'publish',
			'post_type'     =>  'api_log'
		);

		$log_post_id = @wp_insert_post( $log_post );
		if ( is_wp_error( $log_post_id ) ) {
			return;
		}

		add_post_meta( $log_post_id, '_api_request_parsed_args', var_export( $parsed_args, true ) );
		add_post_meta( $log_post_id, '_api_response', var_export( $response, true ) );
        add_post_meta( $log_post_id, '_api_debug_trace', wp_debug_backtrace_summary() );
        if ( ! $success ) {
            return;
        }
		add_post_meta( $log_post_id, '_api_response_code', var_export( wp_remote_retrieve_response_code( $response ), true ) );
		add_post_meta( $log_post_id, '_api_response_body', var_export( wp_remote_retrieve_body( $response ), true ) );
	}

	public function add_meta_box( $post_type ) {
		if ( 'api_log' !== $post_type ) {
			return;
		}

		add_meta_box(
			'api_log_metabox',
			__( 'API Request/Response Details', 'api-debugger' ),
			array( $this, 'render_meta_box_content' ),
			$post_type
		);
	}

	public function render_meta_box_content( $post ) {
		$parsed_args   = get_post_meta( $post->ID, '_api_request_parsed_args', true );
		$response      = get_post_meta( $post->ID, '_api_response', true );
        $debug_trace   = get_post_meta( $post->ID, '_api_debug_trace', true );
		$response_code = get_post_meta( $post->ID, '_api_response_code', true );
		$response_body = get_post_meta( $post->ID, '_api_response_body', true );

		?>
		<div>
            <label for="parsed_args"><strong>Parsed Args</strong></label>
            <textarea id="parsed_args" cols="30" rows="10" style="width: 100%;"><?php echo esc_textarea( $parsed_args ); ?></textarea>
		</div>
        <hr>
        <div>
            <label for="response"><strong>Full Response</strong></label>
            <textarea id="response" cols="30" rows="10" style="width: 100%;"><?php echo esc_textarea( $response ); ?></textarea>
        </div>

        <?php if ( ! empty( $response_code ) ) { ?>
        <hr>
        <div>
            <label for="response_code"><strong>Response Code</strong></label>
            <textarea id="response_code" cols="30" rows="10" style="width: 100%;"><?php echo esc_textarea( $response_code ); ?></textarea>
        </div>
        <?php } ?>

        <?php if ( ! empty( $response_body ) ) { ?>
        <hr>
        <div>
            <label for="response_body"><strong>Response Body</strong></label>
            <textarea id="response_body" cols="30" rows="10" style="width: 100%;"><?php echo esc_textarea( $response_body ); ?></textarea>
        </div>
        <?php } ?>

        <?php if ( ! empty( $debug_trace ) ) { ?>
        <hr>
        <div>
            <label for="response_body"><strong>Debug Trace</strong></label>
            <textarea id="response_body" cols="30" rows="10" style="width: 100%;"><?php echo esc_textarea( $debug_trace ); ?></textarea>
        </div>
        <?php } ?>
		<?php
	}

	public function deactivate() {
		//Delete all posts in this post type: wpt_api_log
		$allposts= get_posts( array('post_type'=>'api_log','numberposts'=>-1) );
		foreach ($allposts as $eachpost) {
			wp_delete_post( $eachpost->ID, true );
		}
	}

}

add_action( 'plugins_loaded', [ API_Debugger::get_instance(), 'add_hooks' ] );
register_deactivation_hook( __FILE__, [ API_Debugger::get_instance(), 'deactivate' ] );
