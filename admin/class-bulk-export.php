<?php
/**
 * Acción en lote para exportar contenido.
 *
 * @package ExportacionSelectiva
 */

namespace AHF\ExportacionSelectiva\Admin;

use AHF\ExportacionSelectiva\Capabilities;
use AHF\ExportacionSelectiva\Export\Exporter;

defined( 'ABSPATH' ) || exit;

/**
 * Clase Bulk_Export.
 */
class Bulk_Export {

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'admin_init', array( $this, 'register_bulk_actions' ) );
	}

	/**
	 * Registra acciones en lote en todos los post types exportables.
	 *
	 * @return void
	 */
	public function register_bulk_actions(): void {
		foreach ( ahf_es_get_exportable_post_types() as $post_type ) {
			add_filter( 'bulk_actions-edit-' . $post_type, array( $this, 'add_bulk_action' ) );
			add_filter( 'handle_bulk_actions-edit-' . $post_type, array( $this, 'handle_bulk_action' ), 10, 3 );
		}
	}

	/**
	 * Añade la acción Exportar al desplegable.
	 *
	 * @param array<string, string> $actions Acciones existentes.
	 * @return array<string, string>
	 */
	public function add_bulk_action( array $actions ): array {
		if ( ! Capabilities::current_user_can_export() ) {
			return $actions;
		}

		$actions[ AHF_ES_BULK_ACTION ] = __( 'Exportar', 'exportacion-selectiva' );

		return $actions;
	}

	/**
	 * Procesa la exportación en lote.
	 *
	 * @param string $redirect_url URL de redirección.
	 * @param string $action Acción seleccionada.
	 * @param int[]  $post_ids IDs seleccionados.
	 * @return string
	 */
	public function handle_bulk_action( string $redirect_url, string $action, array $post_ids ): string {
		if ( AHF_ES_BULK_ACTION !== $action ) {
			return $redirect_url;
		}

		if ( ! Capabilities::current_user_can_export() ) {
			return add_query_arg( 'ahf_es_error', 'capability', $redirect_url );
		}

		if ( ! wp_verify_nonce( isset( $_REQUEST['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['_wpnonce'] ) ) : '', 'bulk-posts' ) ) {
			return add_query_arg( 'ahf_es_error', 'nonce', $redirect_url );
		}

		try {
			$post_ids = array_map( 'absint', $post_ids );
			$exporter = new Exporter();
			$exporter->export_and_download( $post_ids );
		} catch ( \Throwable $exception ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				error_log( 'Exportación Selectiva: ' . $exception->getMessage() );
			}

			return add_query_arg(
				array(
					'ahf_es_error' => 'export_failed',
					'ahf_es_msg'   => rawurlencode( $exception->getMessage() ),
				),
				$redirect_url
			);
		}

		return $redirect_url;
	}
}
