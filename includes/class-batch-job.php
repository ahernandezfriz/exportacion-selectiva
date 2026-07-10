<?php
/**
 * Gestión de trabajos por lotes (export/import).
 *
 * @package ExportacionSelectiva
 */

namespace AHF\ExportacionSelectiva;

defined( 'ABSPATH' ) || exit;

/**
 * Clase Batch_Job.
 */
class Batch_Job {

	/**
	 * Prefijo de transient.
	 */
	private const PREFIX = 'ahf_es_job_';

	/**
	 * TTL del trabajo.
	 */
	private const TTL = DAY_IN_SECONDS;

	/**
	 * Crea un trabajo nuevo.
	 *
	 * @param string               $type Tipo: export|import.
	 * @param array<string, mixed> $data Datos del trabajo.
	 * @return string Job ID.
	 */
	public static function create( string $type, array $data ): string {
		$job_id = wp_generate_password( 20, false );

		$job = array_merge(
			array(
				'id'         => $job_id,
				'type'       => $type,
				'user_id'    => get_current_user_id(),
				'status'     => 'pending',
				'step'       => 'init',
				'cursor'     => 0,
				'total'      => 0,
				'processed'  => 0,
				'created_at' => time(),
				'message'    => '',
				'error'      => '',
				'result'     => array(),
			),
			$data
		);

		self::save( $job_id, $job );

		return $job_id;
	}

	/**
	 * Obtiene un trabajo.
	 *
	 * @param string $job_id ID.
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function get( string $job_id ) {
		$job = get_transient( self::PREFIX . $job_id );

		if ( ! is_array( $job ) ) {
			return new \WP_Error(
				'ahf_es_job_missing',
				__( 'El trabajo ha expirado o no existe.', 'exportacion-selectiva' )
			);
		}

		if ( (int) $job['user_id'] !== get_current_user_id() && ! current_user_can( 'manage_options' ) ) {
			return new \WP_Error(
				'ahf_es_job_forbidden',
				__( 'No tienes permiso para este trabajo.', 'exportacion-selectiva' )
			);
		}

		return $job;
	}

	/**
	 * Guarda un trabajo.
	 *
	 * @param string               $job_id ID.
	 * @param array<string, mixed> $job Datos.
	 * @return void
	 */
	public static function save( string $job_id, array $job ): void {
		set_transient( self::PREFIX . $job_id, $job, self::TTL );
	}

	/**
	 * Elimina un trabajo.
	 *
	 * @param string $job_id ID.
	 * @return void
	 */
	public static function delete( string $job_id ): void {
		delete_transient( self::PREFIX . $job_id );
	}

	/**
	 * Calcula el porcentaje de progreso.
	 *
	 * @param array<string, mixed> $job Trabajo.
	 * @return int
	 */
	public static function progress_percent( array $job ): int {
		$total = (int) ( $job['total'] ?? 0 );

		if ( $total <= 0 ) {
			return 'done' === ( $job['status'] ?? '' ) ? 100 : 0;
		}

		return (int) min( 100, floor( ( (int) $job['processed'] / $total ) * 100 ) );
	}
}
