<?php
/**
 * Exportación por lotes vía AJAX.
 *
 * @package ExportacionSelectiva
 */

namespace AHF\ExportacionSelectiva\Export;

use AHF\ExportacionSelectiva\Batch_Job;
use AHF\ExportacionSelectiva\Package\Wpcontent_Package;

defined( 'ABSPATH' ) || exit;

/**
 * Clase Batch_Exporter.
 */
class Batch_Exporter {

	/**
	 * Crea un trabajo de exportación.
	 *
	 * @param int[] $post_ids IDs seleccionados.
	 * @return string|\WP_Error Job ID.
	 */
	public function create_job( array $post_ids ) {
		$post_ids = array_values( array_unique( array_map( 'absint', $post_ids ) ) );

		if ( empty( $post_ids ) ) {
			return new \WP_Error(
				'ahf_es_no_posts',
				__( 'No se seleccionaron elementos para exportar.', 'exportacion-selectiva' )
			);
		}

		$temp_dir = ahf_es_get_temp_dir();

		if ( is_wp_error( $temp_dir ) ) {
			return $temp_dir;
		}

		$work_dir = trailingslashit( $temp_dir ) . 'job-' . wp_generate_password( 12, false );

		if ( ! wp_mkdir_p( $work_dir ) || ! wp_mkdir_p( trailingslashit( $work_dir ) . 'posts' ) || ! wp_mkdir_p( trailingslashit( $work_dir ) . 'media/files' ) ) {
			return new \WP_Error(
				'ahf_es_temp_dir',
				__( 'No se pudo crear el directorio temporal de exportación.', 'exportacion-selectiva' )
			);
		}

		$resolver = new Dependency_Resolver();
		$resolved = $resolver->resolve( $post_ids );

		$total = count( $resolved['post_ids'] ) + count( $resolved['attachment_ids'] ) + 1;

		return Batch_Job::create(
			'export',
			array(
				'post_ids'       => $resolved['post_ids'],
				'attachment_ids' => $resolved['attachment_ids'],
				'term_ids'       => $resolved['term_ids'],
				'work_dir'       => $work_dir,
				'posts_data'     => array(),
				'attachments'    => array(),
				'post_types'     => array(),
				'total'          => $total,
				'step'           => 'posts',
			)
		);
	}

	/**
	 * Procesa un lote del trabajo de exportación.
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

		if ( 'error' === $job['status'] ) {
			return new \WP_Error( 'ahf_es_job_error', $job['error'] ?: __( 'La exportación falló.', 'exportacion-selectiva' ) );
		}

		$job['status'] = 'running';
		$exporter      = new Exporter();
		$batch_size    = (int) AHF_ES_BATCH_SIZE;

		if ( 'posts' === $job['step'] ) {
			$slice = array_slice( $job['post_ids'], (int) $job['cursor'], $batch_size );

			foreach ( $slice as $post_id ) {
				$serialized = $exporter->serialize_post( (int) $post_id );

				if ( null === $serialized ) {
					++$job['processed'];
					continue;
				}

				$file = trailingslashit( $job['work_dir'] ) . 'posts/' . sanitize_file_name( $serialized['post_type'] . '-' . $serialized['source_id'] . '.json' );
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
				file_put_contents( $file, ahf_es_json_encode( $serialized ) );

				$job['posts_data'][] = $serialized['source_id'];
				$job['post_types'][] = $serialized['post_type'];
				++$job['processed'];
			}

			$job['cursor'] += count( $slice );

			if ( $job['cursor'] >= count( $job['post_ids'] ) ) {
				$job['step']   = 'media';
				$job['cursor'] = 0;
			}
		} elseif ( 'media' === $job['step'] ) {
			$slice = array_slice( $job['attachment_ids'], (int) $job['cursor'], $batch_size );

			foreach ( $slice as $attachment_id ) {
				$attachment = $exporter->serialize_attachment( (int) $attachment_id );

				if ( null === $attachment ) {
					++$job['processed'];
					continue;
				}

				$source_path = get_attached_file( (int) $attachment_id );

				if ( $source_path && file_exists( $source_path ) ) {
					$target = trailingslashit( $job['work_dir'] ) . 'media/files/' . $attachment['file'];
					wp_mkdir_p( dirname( $target ) );
					// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_copy
					copy( $source_path, $target );
				}

				$job['attachments'][] = $attachment;
				++$job['processed'];
			}

			$job['cursor'] += count( $slice );

			if ( $job['cursor'] >= count( $job['attachment_ids'] ) ) {
				$job['step']   = 'finalize';
				$job['cursor'] = 0;
			}
		} elseif ( 'finalize' === $job['step'] ) {
			$result = $this->finalize( $job, $exporter );

			if ( is_wp_error( $result ) ) {
				$job['status'] = 'error';
				$job['error']  = $result->get_error_message();
				Batch_Job::save( $job_id, $job );
				return $result;
			}

			$job['status']              = 'done';
			$job['processed']           = (int) $job['total'];
			$job['result']['file_path'] = $result;
			$job['result']['filename']  = basename( $result );
			$job['message']             = __( 'Exportación completada.', 'exportacion-selectiva' );
		}

		Batch_Job::save( $job_id, $job );

		return $this->status_payload( $job );
	}

	/**
	 * Finaliza el paquete ZIP.
	 *
	 * @param array<string, mixed> $job Trabajo.
	 * @param Exporter             $exporter Exportador.
	 * @return string|\WP_Error
	 */
	private function finalize( array $job, Exporter $exporter ) {
		if ( empty( $job['posts_data'] ) ) {
			return new \WP_Error(
				'ahf_es_invalid_posts',
				__( 'No se pudo serializar el contenido seleccionado.', 'exportacion-selectiva' )
			);
		}

		$posts = array();
		$posts_dir = trailingslashit( $job['work_dir'] ) . 'posts';

		foreach ( glob( trailingslashit( $posts_dir ) . '*.json' ) as $post_file ) {
			$decoded = json_decode( (string) file_get_contents( $post_file ), true );

			if ( is_array( $decoded ) ) {
				$posts[] = $decoded;
			}
		}

		$terms_data = $exporter->serialize_terms( $job['term_ids'] );
		wp_mkdir_p( trailingslashit( $job['work_dir'] ) . 'taxonomies' );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		file_put_contents(
			trailingslashit( $job['work_dir'] ) . 'taxonomies/terms.json',
			ahf_es_json_encode( array_values( $terms_data ) )
		);

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		file_put_contents(
			trailingslashit( $job['work_dir'] ) . 'media/index.json',
			ahf_es_json_encode( $job['attachments'] )
		);

		$manifest = array(
			'format_version' => AHF_ES_FORMAT_VERSION,
			'plugin_version' => AHF_ES_VERSION,
			'export_uuid'    => ahf_es_generate_uuid(),
			'exported_at'    => gmdate( 'c' ),
			'source_url'     => home_url(),
			'wp_version'     => get_bloginfo( 'version' ),
			'php_version'    => PHP_VERSION,
			'post_types'     => array_values( array_unique( $job['post_types'] ) ),
			'items_count'    => count( $posts ),
			'media_count'    => count( $job['attachments'] ),
			'terms_count'    => count( $terms_data ),
		);

		$package = new Wpcontent_Package();

		// Reutiliza build leyendo desde arrays ya preparados.
		$zip = $package->build( $manifest, $posts, $job['attachments'], $terms_data );

		if ( ! is_wp_error( $zip ) && ! empty( $job['work_dir'] ) ) {
			ahf_es_delete_directory( $job['work_dir'] );
		}

		return $zip;
	}

	/**
	 * Payload de estado para el cliente.
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
		);

		if ( 'done' === $job['status'] && ! empty( $job['result']['filename'] ) ) {
			$payload['download_url'] = wp_nonce_url(
				add_query_arg(
					array(
						'action' => 'ahf_es_download_export',
						'job_id' => $job['id'],
					),
					admin_url( 'admin-post.php' )
				),
				'ahf_es_download_' . $job['id']
			);
		}

		return $payload;
	}
}
