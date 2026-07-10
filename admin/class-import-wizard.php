<?php
/**
 * Wizard de importación en el administrador.
 *
 * @package ExportacionSelectiva
 */

namespace AHF\ExportacionSelectiva\Admin;

use AHF\ExportacionSelectiva\Capabilities;
use AHF\ExportacionSelectiva\Import\Importer;

defined( 'ABSPATH' ) || exit;

/**
 * Clase Import_Wizard.
 */
class Import_Wizard {

	/**
	 * Slug de la página de importación.
	 */
	public const PAGE_SLUG = 'ahf-es-import';

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'admin_menu', array( $this, 'register_page' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'manage_posts_extra_tablenav', array( $this, 'render_import_button' ), 10, 2 );
		add_action( 'manage_pages_extra_tablenav', array( $this, 'render_import_button' ), 10, 1 );
	}

	/**
	 * Registra la página oculta del wizard.
	 *
	 * @return void
	 */
	public function register_page(): void {
		add_submenu_page(
			'',
			__( 'Importar contenido', 'exportacion-selectiva' ),
			__( 'Importar contenido', 'exportacion-selectiva' ),
			Capabilities::IMPORT,
			self::PAGE_SLUG,
			array( $this, 'render_page' )
		);
	}

	/**
	 * Muestra el botón Importar en los listados.
	 *
	 * @param string      $which     Ubicación del tablenav.
	 * @param string|null $post_type Tipo de contenido (solo en manage_posts_extra_tablenav).
	 * @return void
	 */
	public function render_import_button( string $which, ?string $post_type = null ): void {
		if ( 'top' !== $which || ! Capabilities::current_user_can_import() ) {
			return;
		}

		if ( null === $post_type ) {
			$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
			$post_type = ( $screen && ! empty( $screen->post_type ) ) ? $screen->post_type : '';
		}

		if ( ! $post_type ) {
			return;
		}

		$post_type_object = get_post_type_object( $post_type );

		if ( ! $post_type_object || ! $post_type_object->can_export ) {
			return;
		}

		$url = add_query_arg(
			array(
				'page'      => self::PAGE_SLUG,
				'post_type' => $post_type,
			),
			admin_url( 'admin.php' )
		);

		printf(
			'<a href="%1$s" class="button ahf-es-import-button">%2$s</a>',
			esc_url( $url ),
			esc_html__( 'Importar', 'exportacion-selectiva' )
		);
	}

	/**
	 * Encola assets del wizard.
	 *
	 * @param string $hook_suffix Hook actual.
	 * @return void
	 */
	public function enqueue_assets( string $hook_suffix ): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		if ( 'admin_page_' . self::PAGE_SLUG !== $hook_suffix && ( ! $screen || 'edit' !== $screen->base ) ) {
			return;
		}

		wp_enqueue_style(
			'ahf-es-admin',
			AHF_ES_PLUGIN_URL . 'assets/css/admin.css',
			array(),
			AHF_ES_VERSION
		);

		if ( 'admin_page_' . self::PAGE_SLUG !== $hook_suffix ) {
			return;
		}

		wp_enqueue_script(
			'ahf-es-admin',
			AHF_ES_PLUGIN_URL . 'assets/js/admin.js',
			array(),
			AHF_ES_VERSION,
			true
		);

		$post_type = isset( $_GET['post_type'] ) ? sanitize_key( wp_unslash( $_GET['post_type'] ) ) : 'post'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		wp_localize_script(
			'ahf-es-admin',
			'ahfEsAdmin',
			array(
				'ajaxUrl'  => admin_url( 'admin-ajax.php' ),
				'nonce'    => wp_create_nonce( 'ahf_es_batch' ),
				'mode'     => 'import',
				'postType' => $post_type,
				'listUrl'  => add_query_arg( array( 'post_type' => $post_type ), admin_url( 'edit.php' ) ),
				'i18n'     => array(
					'processing' => __( 'Importando contenido…', 'exportacion-selectiva' ),
					'done'       => __( 'Importación completada.', 'exportacion-selectiva' ),
					'error'      => __( 'Error durante la importación.', 'exportacion-selectiva' ),
					'back'       => __( 'Volver al listado', 'exportacion-selectiva' ),
				),
			)
		);
	}

	/**
	 * Renderiza la página del wizard.
	 *
	 * @return void
	 */
	public function render_page(): void {
		if ( ! Capabilities::current_user_can_import() ) {
			wp_die( esc_html__( 'No tienes permisos para importar contenido.', 'exportacion-selectiva' ) );
		}

		$post_type = isset( $_GET['post_type'] ) ? sanitize_key( wp_unslash( $_GET['post_type'] ) ) : 'post'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		$analysis = null;
		$importer = new Importer();

		if ( isset( $_POST['ahf_es_analyze_submit'] ) ) {
			check_admin_referer( 'ahf_es_import_upload', 'ahf_es_upload_nonce' );

			if ( empty( $_FILES['ahf_es_file']['tmp_name'] ) ) {
				add_settings_error(
					'ahf_es_import',
					'ahf_es_import_missing_file',
					__( 'Debes seleccionar un archivo .wpcontent.', 'exportacion-selectiva' ),
					'error'
				);
			} else {
				$file = $_FILES['ahf_es_file']; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

				if ( ! empty( $file['error'] ) ) {
					add_settings_error(
						'ahf_es_import',
						'ahf_es_import_upload_error',
						__( 'No se pudo subir el archivo.', 'exportacion-selectiva' ),
						'error'
					);
				} else {
					$analysis = $importer->analyze( $file['tmp_name'] );

					if ( is_wp_error( $analysis ) ) {
						add_settings_error( 'ahf_es_import', 'ahf_es_import_analyze_error', $analysis->get_error_message(), 'error' );
						$analysis = null;
					}
				}
			}
		}

		$view_data = array(
			'post_type' => $post_type,
			'analysis'  => $analysis,
		);

		include AHF_ES_PLUGIN_DIR . 'admin/views/import-wizard.php';
	}
}
