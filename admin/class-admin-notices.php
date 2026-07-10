<?php
/**
 * Avisos del administrador.
 *
 * @package ExportacionSelectiva
 */

namespace AHF\ExportacionSelectiva\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Clase Admin_Notices.
 */
class Admin_Notices {

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'admin_notices', array( $this, 'render_notices' ) );
	}

	/**
	 * Muestra avisos de exportación/importación.
	 *
	 * @return void
	 */
	public function render_notices(): void {
		if ( ! isset( $_GET['ahf_es_error'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}

		$error = sanitize_key( wp_unslash( $_GET['ahf_es_error'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$message = '';

		switch ( $error ) {
			case 'capability':
				$message = __( 'No tienes permisos para exportar contenido.', 'exportacion-selectiva' );
				break;
			case 'nonce':
				$message = __( 'La solicitud de exportación expiró. Inténtalo de nuevo.', 'exportacion-selectiva' );
				break;
			case 'export_failed':
				$message = __( 'No se pudo completar la exportación.', 'exportacion-selectiva' );

				if ( isset( $_GET['ahf_es_msg'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
					$raw_msg = sanitize_text_field( wp_unslash( $_GET['ahf_es_msg'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
					$details = sanitize_text_field( rawurldecode( $raw_msg ) );

					if ( $details ) {
						$message .= ' ' . $details;
					}
				}
				break;
			default:
				$message = __( 'Ocurrió un error durante la exportación.', 'exportacion-selectiva' );
				break;
		}

		printf(
			'<div class="notice notice-error is-dismissible"><p>%s</p></div>',
			esc_html( $message )
		);
	}
}
