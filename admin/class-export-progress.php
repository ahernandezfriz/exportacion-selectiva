<?php
/**
 * Página de progreso de exportación.
 *
 * @package ExportacionSelectiva
 */

namespace AHF\ExportacionSelectiva\Admin;

use AHF\ExportacionSelectiva\Batch_Job;
use AHF\ExportacionSelectiva\Capabilities;

defined( 'ABSPATH' ) || exit;

/**
 * Clase Export_Progress.
 */
class Export_Progress {

	/**
	 * Slug de la página.
	 */
	public const PAGE_SLUG = 'ahf-es-export-progress';

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'admin_menu', array( $this, 'register_page' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	/**
	 * Registra la página oculta.
	 *
	 * @return void
	 */
	public function register_page(): void {
		add_submenu_page(
			'',
			__( 'Exportando contenido', 'exportacion-selectiva' ),
			__( 'Exportando contenido', 'exportacion-selectiva' ),
			Capabilities::EXPORT,
			self::PAGE_SLUG,
			array( $this, 'render_page' )
		);
	}

	/**
	 * Encola assets.
	 *
	 * @param string $hook_suffix Hook.
	 * @return void
	 */
	public function enqueue_assets( string $hook_suffix ): void {
		if ( 'admin_page_' . self::PAGE_SLUG !== $hook_suffix ) {
			return;
		}

		wp_enqueue_style(
			'ahf-es-admin',
			AHF_ES_PLUGIN_URL . 'assets/css/admin.css',
			array(),
			AHF_ES_VERSION
		);

		wp_enqueue_script(
			'ahf-es-admin',
			AHF_ES_PLUGIN_URL . 'assets/js/admin.js',
			array(),
			AHF_ES_VERSION,
			true
		);

		$job_id = isset( $_GET['job_id'] ) ? sanitize_text_field( wp_unslash( $_GET['job_id'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		wp_localize_script(
			'ahf-es-admin',
			'ahfEsAdmin',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'ahf_es_batch' ),
				'jobId'   => $job_id,
				'mode'    => 'export',
				'i18n'    => array(
					'processing' => __( 'Procesando exportación…', 'exportacion-selectiva' ),
					'done'       => __( 'Exportación completada.', 'exportacion-selectiva' ),
					'error'      => __( 'Error durante la exportación.', 'exportacion-selectiva' ),
					'download'   => __( 'Descargar archivo', 'exportacion-selectiva' ),
				),
			)
		);
	}

	/**
	 * Renderiza la página.
	 *
	 * @return void
	 */
	public function render_page(): void {
		if ( ! Capabilities::current_user_can_export() ) {
			wp_die( esc_html__( 'No tienes permisos para exportar contenido.', 'exportacion-selectiva' ) );
		}

		$job_id = isset( $_GET['job_id'] ) ? sanitize_text_field( wp_unslash( $_GET['job_id'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$job    = $job_id ? Batch_Job::get( $job_id ) : null;

		include AHF_ES_PLUGIN_DIR . 'admin/views/export-progress.php';
	}
}
