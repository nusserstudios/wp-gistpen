<?php
namespace WP_Gistpen\Register\Assets;

use WP_Gistpen\Model\Language;

/**
 * Registers the web assets of the plugin.
 *
 * @package    WP_Gistpen
 * @author     James DiGioia <jamesorodig@gmail.com>
 * @link       http://jamesdigioia.com/wp-gistpen/
 * @since      0.5.0
 */
class Settings {

	/**
	 * The ID of this plugin.
	 *
	 * @since    0.5.0
	 * @access   private
	 * @var      string    $plugin_name    The ID of this plugin.
	 */
	private $plugin_name;

	/**
	 * The version of this plugin.
	 *
	 * @since    0.5.0
	 * @access   private
	 * @var      string    $version    The current version of this plugin.
	 */
	private $version;

	/**
	 * The minification string
	 *
	 * @since    0.5.0
	 * @access   private
	 * @var string
	 */
	private $min = '';

	/**
	 * Initialize the class and set its properties.
	 *
	 * @since    0.5.0
	 * @var      string    $plugin_name       The name of this plugin.
	 * @var      string    $version    The version of this plugin.
	 */
	public function __construct( $plugin_name, $version ) {

		$this->plugin_name = $plugin_name;
		$this->version = $version;

		if ( ! defined( 'SCRIPT_DEBUG' ) || SCRIPT_DEBUG !== true ) {
			$this->min = '.min';
		}

	}

	/**
	 * Register the stylesheets for the Dashboard.
	 *
	 * @since    0.5.0
	 */
	public function enqueue_styles() {

		wp_enqueue_style( $this->plugin_name .'-dashboard-styles', WP_GISTPEN_URL . 'assets/css/dashboard' . $this->min . '.css', array(), $this->version, 'all' );

		if ( get_current_screen()->id === 'gistpen' ) {
				wp_enqueue_style( $this->plugin_name .'-editor-styles', WP_GISTPEN_URL . 'assets/css/editor' . $this->min . '.css', array(), $this->version );
			}

	}

	/**
	 * Register the JavaScript for the Dashboard.
	 *
	 * @since    0.5.0
	 */
	public function enqueue_scripts() {

		wp_enqueue_script( 'ajaxq', WP_GISTPEN_URL . 'assets/js/ajaxq' . $this->min . '.js', array( 'jquery' ), $this->version, true );
		wp_enqueue_script( $this->plugin_name . '-settings-script', WP_GISTPEN_URL . 'assets/js/settings' . $this->min . '.js', array( 'jquery', 'jquery-ui-progressbar', 'ajaxq', 'backbone', 'underscore', $this->plugin_name . '-prism' ), $this->version, true );
		wp_localize_script( $this->plugin_name .'-settings-script', 'WP_GISTPEN_URL', WP_GISTPEN_URL );

		$screen = get_current_screen();

		if ( 'gistpen' === $screen->id ) {
			wp_enqueue_script( $this->plugin_name . '-ace-script', WP_GISTPEN_URL . 'assets/js/ace/ace.js', array(), $this->version, false );

			wp_enqueue_script( $this->plugin_name . '-editor-script', WP_GISTPEN_URL . 'assets/js/editor' . $this->min . '.js', array( 'jquery', $this->plugin_name . '-ace-script' ), $this->version, false );
			wp_localize_script( $this->plugin_name . '-editor-script', 'gistpenLanguages', Language::$supported );

			wp_dequeue_script( 'autosave' );
		}

	}

}
