<?php
/**
 * Importación por lotes vía AJAX.
 *
 * @package ExportacionSelectiva
 */

namespace AHF\ExportacionSelectiva\Import;

use AHF\ExportacionSelectiva\Batch_Job;
use AHF\ExportacionSelectiva\Capabilities;
use AHF\ExportacionSelectiva\Package\Wpcontent_Package;

defined( 'ABSPATH' ) || exit;

/**
 * Clase Batch_Importer.
 */
class Batch_Importer {

	/**
	 * Crea un trabajo de importación.
	 *
	 * @param string $session_key Sesión del análisis.
	 * @param int[]  $indexes Índices seleccionados.
	 * @param string $policy Política de conflicto.
	 * @param string $target_post_type Post type destino.
	 * @return string|\WP_Error
	 */
	public function create_job( string $session_key, array $indexes, string $policy, string $target_post_type ) {
		if ( ! Capabilities::current_user_can_import() ) {
			return new \WP_Error(
				'ahf_es_capability',
				__( 'No tienes permisos para importar contenido.', 'exportacion-selectiva' )
			);
		}

		$session = get_transient( $session_key );

		if ( ! is_array( $session ) || (int) $session['user_id'] !== get_current_user_id() ) {
			return new \WP_Error(
				'ahf_es_session_expired',
				__( 'La sesión de importación ha expirado. Vuelve a subir el archivo.', 'exportacion-selectiva' )
			);
		}

		$package = new Wpcontent_Package();

		if ( ! empty( $session['extract_dir'] ) && is_dir( $session['extract_dir'] ) ) {
			$data = $package->read_extracted( $session['extract_dir'] );
		} else {
			$data = $package->read( $session['zip_path'] );
		}

		if ( is_wp_error( $data ) ) {
			return $data;
		}

		$indexes = array_values( array_unique( array_map( 'absint', $indexes ) ) );
		$conflicts = new Conflict_Resolver();
		$policy    = $conflicts->sanitize_policy( $policy );

		$total = 1 + count( $data['media'] ) + count( $indexes );

		return Batch_Job::create(
			'import',
			array(
				'session_key'       => $session_key,
				'zip_path'          => $session['zip_path'],
				'extract_dir'       => $data['extract_dir'],
				'indexes'           => $indexes,
				'policy'            => $policy,
				'target_post_type'  => $target_post_type,
				'id_map'            => array(),
				'counts'            => array(
					'created'    => 0,
					'updated'    => 0,
					'skipped'    => 0,
					'duplicated' => 0,
					'compared'   => 0,
				),
				'media_total'       => count( $data['media'] ),
				'total'             => $total,
				'step'              => 'terms',
			)
		);
	}

	/**
	 * Procesa un lote de importación.
	 *
	 * @param string $job_id ID del trabajo.
	 * @return array<string, mixed>|\WP_Error
	 */
	public function process( string $job_id ) {
		$job = Batch_Job::get( $job_id );

		if ( is_wp_error( $job ) ) {
			return $job;
		}

		if ( 'done' === $job['status'] ) {
			return $this->status_payload( $job );
		}

		$importer = new Importer();
		$mapper   = new Id_Mapper();
		$mapper->hydrate( $job['id_map'] ?? array() );
		$package  = new Wpcontent_Package();

		if ( ! empty( $job['extract_dir'] ) && is_dir( $job['extract_dir'] ) ) {
			$data = $package->read_extracted( $job['extract_dir'] );
		} else {
			$data = $package->read( $job['zip_path'] );

			if ( ! is_wp_error( $data ) ) {
				$job['extract_dir'] = $data['extract_dir'];
			}
		}

		if ( is_wp_error( $data ) ) {
			$job['status'] = 'error';
			$job['error']  = $data->get_error_message();
			Batch_Job::save( $job_id, $job );
			return $data;
		}

		$job['status'] = 'running';
		$batch_size    = (int) AHF_ES_BATCH_SIZE;

		if ( 'terms' === $job['step'] ) {
			$importer->import_terms( $data['terms'], $mapper );
			++$job['processed'];
			$job['step']   = 'media';
			$job['cursor'] = 0;
		} elseif ( 'media' === $job['step'] ) {
			$processed = $importer->import_media_batch(
				$data['media'],
				$data['extract_dir'],
				$mapper,
				(int) $job['cursor'],
				$batch_size
			);
			$job['cursor']    += $processed;
			$job['processed'] += $processed;

			if ( $job['cursor'] >= (int) $job['media_total'] ) {
				$job['step']   = 'posts';
				$job['cursor'] = 0;
			}
		} elseif ( 'posts' === $job['step'] ) {
			$slice = array_slice( $job['indexes'], (int) $job['cursor'], $batch_size );

			foreach ( $slice as $index ) {
				if ( ! isset( $data['posts'][ $index ] ) ) {
					++$job['processed'];
					continue;
				}

				$result_key = $importer->import_single_item(
					$data['posts'][ $index ],
					$job['policy'],
					$job['target_post_type'],
					$mapper
				);

				if ( isset( $job['counts'][ $result_key ] ) ) {
					++$job['counts'][ $result_key ];
				} else {
					++$job['counts']['skipped'];
				}

				++$job['processed'];
			}

			$job['cursor'] += count( $slice );

			if ( $job['cursor'] >= count( $job['indexes'] ) ) {
				$job['step'] = 'cleanup';
			}
		} elseif ( 'cleanup' === $job['step'] ) {
			$session = get_transient( $job['session_key'] );
			$session = is_array( $session ) ? $session : array( 'zip_path' => $job['zip_path'] );
			$importer->cleanup_session( $session, $data, $job['session_key'] );
			$job['status']  = 'done';
			$job['message'] = __( 'Importación completada.', 'exportacion-selectiva' );
			$job['result']  = $job['counts'];
			$job['processed'] = (int) $job['total'];
		}

		$job['id_map'] = $mapper->all();
		Batch_Job::save( $job_id, $job );

		return $this->status_payload( $job );
	}

	/**
	 * Payload de estado.
	 *
	 * @param array<string, mixed> $job Trabajo.
	 * @return array<string, mixed>
	 */
	private function status_payload( array $job ): array {
		$payload = array(
			'job_id'    => $job['id'],
			'status'    => $job['status'],
			'step'      => $job['step'],
			'processed' => (int) $job['processed'],
			'total'     => (int) $job['total'],
			'percent'   => Batch_Job::progress_percent( $job ),
			'message'   => $job['message'],
			'counts'    => $job['counts'] ?? array(),
		);

		if ( 'done' === $job['status'] ) {
			$payload['result'] = $job['result'] ?? $job['counts'];
		}

		return $payload;
	}
}
