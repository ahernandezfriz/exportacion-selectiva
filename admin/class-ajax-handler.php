<?php
/**
 * Endpoints AJAX y descarga de exportación.
 *
 * @package ExportacionSelectiva
 */

namespace AHF\ExportacionSelectiva\Admin;

use AHF\ExportacionSelectiva\Batch_Job;
use AHF\ExportacionSelectiva\Capabilities;
use AHF\ExportacionSelectiva\Export\Batch_Exporter;
use AHF\ExportacionSelectiva\Import\Batch_Importer;

defined( 'ABSPATH' ) || exit;

/**
 * Clase Ajax_Handler.
 */
class Ajax_Handler {

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'wp_ajax_ahf_es_process_export', array( $this, 'process_export' ) );
		add_action( 'wp_ajax_ahf_es_start_import', array( $this, 'start_import' ) );
		add_action( 'wp_ajax_ahf_es_process_import', array( $this, 'process_import' ) );
		add_action( 'admin_post_ahf_es_download_export', array( $this, 'download_export' ) );
	}

	/**
	 * Procesa un lote de exportación.
	 *
	 * @return void
	 */
	public function process_export(): void {
		if ( ! Capabilities::current_user_can_export() ) {
			wp_send_json_error( array( 'message' => __( 'No tienes permisos para exportar contenido.', 'exportacion-selectiva' ) ), 403 );
		}

		check_ajax_referer( 'ahf_es_batch', 'nonce' );

		$job_id = isset( $_POST['job_id'] ) ? sanitize_text_field( wp_unslash( $_POST['job_id'] ) ) : '';

		if ( ! $job_id ) {
			wp_send_json_error( array( 'message' => __( 'Trabajo no válido.', 'exportacion-selectiva' ) ), 400 );
		}

		$result = ( new Batch_Exporter() )->process( $job_id );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		wp_send_json_success( $result );
	}

	/**
	 * Inicia un trabajo de importación.
	 *
	 * @return void
	 */
	public function start_import(): void {
		if ( ! Capabilities::current_user_can_import() ) {
			wp_send_json_error( array( 'message' => __( 'No tienes permisos para importar contenido.', 'exportacion-selectiva' ) ), 403 );
		}

		check_ajax_referer( 'ahf_es_batch', 'nonce' );

		$session_key = isset( $_POST['session_key'] ) ? sanitize_text_field( wp_unslash( $_POST['session_key'] ) ) : '';
		$indexes     = isset( $_POST['items'] ) ? array_map( 'absint', (array) wp_unslash( $_POST['items'] ) ) : array();
		$policy      = isset( $_POST['policy'] ) ? sanitize_key( wp_unslash( $_POST['policy'] ) ) : 'skip';
		$post_type   = isset( $_POST['post_type'] ) ? sanitize_key( wp_unslash( $_POST['post_type'] ) ) : 'post';

		$result = ( new Batch_Importer() )->create_job( $session_key, $indexes, $policy, $post_type );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		wp_send_json_success( array( 'job_id' => $result ) );
	}

	/**
	 * Procesa un lote de importación.
	 *
	 * @return void
	 */
	public function process_import(): void {
		if ( ! Capabilities::current_user_can_import() ) {
			wp_send_json_error( array( 'message' => __( 'No tienes permisos para importar contenido.', 'exportacion-selectiva' ) ), 403 );
		}

		check_ajax_referer( 'ahf_es_batch', 'nonce' );

		$job_id = isset( $_POST['job_id'] ) ? sanitize_text_field( wp_unslash( $_POST['job_id'] ) ) : '';

		if ( ! $job_id ) {
			wp_send_json_error( array( 'message' => __( 'Trabajo no válido.', 'exportacion-selectiva' ) ), 400 );
		}

		$result = ( new Batch_Importer() )->process( $job_id );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		wp_send_json_success( $result );
	}

	/**
	 * Descarga el archivo de un trabajo de exportación.
	 *
	 * @return void
	 */
	public function download_export(): void {
		if ( ! Capabilities::current_user_can_export() ) {
			wp_die( esc_html__( 'No tienes permisos para exportar contenido.', 'exportacion-selectiva' ) );
		}

		$job_id = isset( $_GET['job_id'] ) ? sanitize_text_field( wp_unslash( $_GET['job_id'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		check_admin_referer( 'ahf_es_download_' . $job_id );

		$job = Batch_Job::get( $job_id );

		if ( is_wp_error( $job ) || empty( $job['result']['file_path'] ) || ! file_exists( $job['result']['file_path'] ) ) {
			wp_die( esc_html__( 'El archivo de exportación no está disponible.', 'exportacion-selectiva' ) );
		}

		$file = $job['result']['file_path'];
		$name = $job['result']['filename'] ?? basename( $file );

		ahf_es_send_file_download( $file, $name );
	}
}
