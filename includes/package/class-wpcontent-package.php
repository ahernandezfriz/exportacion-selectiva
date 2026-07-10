<?php
/**
 * Empaquetador del formato .wpcontent.
 *
 * @package ExportacionSelectiva
 */

namespace AHF\ExportacionSelectiva\Package;

defined( 'ABSPATH' ) || exit;

/**
 * Clase Wpcontent_Package.
 */
class Wpcontent_Package {

	/**
	 * Crea un paquete .wpcontent y devuelve la ruta del archivo ZIP.
	 *
	 * @param array<string, mixed> $manifest  Datos del manifiesto.
	 * @param array<int, array<string, mixed>> $posts Datos de posts.
	 * @param array<int, array<string, mixed>> $attachments Datos de adjuntos.
	 * @param array<int, array<string, mixed>> $terms Datos de términos.
	 * @return string|\WP_Error Ruta del archivo ZIP o error.
	 */
	public function build( array $manifest, array $posts, array $attachments, array $terms ) {
		if ( ! class_exists( 'ZipArchive' ) ) {
			return new \WP_Error(
				'ahf_es_zip_missing',
				__( 'La extensión ZipArchive de PHP es necesaria para exportar.', 'exportacion-selectiva' )
			);
		}

		$temp_dir = ahf_es_get_temp_dir();

		if ( is_wp_error( $temp_dir ) ) {
			return $temp_dir;
		}

		$work_dir = trailingslashit( $temp_dir ) . 'ahf-es-' . wp_generate_password( 12, false );

		if ( ! wp_mkdir_p( $work_dir ) ) {
			return new \WP_Error(
				'ahf_es_temp_dir',
				__( 'No se pudo crear el directorio temporal de exportación.', 'exportacion-selectiva' )
			);
		}

		$posts_dir  = trailingslashit( $work_dir ) . 'posts';
		$media_dir  = trailingslashit( $work_dir ) . 'media/files';
		$terms_path = trailingslashit( $work_dir ) . 'taxonomies/terms.json';

		wp_mkdir_p( $posts_dir );
		wp_mkdir_p( $media_dir );
		wp_mkdir_p( dirname( $terms_path ) );

		foreach ( $posts as $post_data ) {
			$file = trailingslashit( $posts_dir ) . sanitize_file_name( $post_data['post_type'] . '-' . $post_data['source_id'] . '.json' );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			file_put_contents( $file, ahf_es_json_encode( $post_data ) );
		}

		$media_index = array();

		foreach ( $attachments as $attachment_data ) {
			$source_path = get_attached_file( $attachment_data['source_id'] );

			if ( ! $source_path || ! file_exists( $source_path ) ) {
				continue;
			}

			$relative = $attachment_data['file'];
			$target   = trailingslashit( $media_dir ) . $relative;
			$folder   = dirname( $target );

			if ( ! wp_mkdir_p( $folder ) ) {
				continue;
			}

			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_copy
			copy( $source_path, $target );

			$media_index[] = $attachment_data;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		file_put_contents(
			trailingslashit( $work_dir ) . 'media/index.json',
			ahf_es_json_encode( $media_index )
		);

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		file_put_contents( $terms_path, ahf_es_json_encode( array_values( $terms ) ) );

		$manifest['checksum'] = $this->calculate_checksum( $work_dir );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		file_put_contents(
			trailingslashit( $work_dir ) . 'manifest.json',
			ahf_es_json_encode( $manifest )
		);

		$zip_path = trailingslashit( $temp_dir ) . sanitize_file_name( 'exportacion-selectiva-' . gmdate( 'Ymd-His' ) . '.wpcontent' );
		$zip      = new \ZipArchive();

		if ( true !== $zip->open( $zip_path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE ) ) {
			ahf_es_delete_directory( $work_dir );
			return new \WP_Error(
				'ahf_es_zip_create',
				__( 'No se pudo crear el archivo de exportación.', 'exportacion-selectiva' )
			);
		}

		$this->add_directory_to_zip( $zip, $work_dir, '' );
		$zip->close();

		ahf_es_delete_directory( $work_dir );

		return $zip_path;
	}

	/**
	 * Lee un paquete .wpcontent y devuelve su contenido estructurado.
	 *
	 * @param string $zip_path Ruta del archivo ZIP.
	 * @return array<string, mixed>|\WP_Error
	 */
	public function read( string $zip_path ) {
		if ( ! class_exists( 'ZipArchive' ) ) {
			return new \WP_Error(
				'ahf_es_zip_missing',
				__( 'La extensión ZipArchive de PHP es necesaria para importar.', 'exportacion-selectiva' )
			);
		}

		$temp_dir = ahf_es_get_temp_dir();

		if ( is_wp_error( $temp_dir ) ) {
			return $temp_dir;
		}

		$extract_dir = trailingslashit( $temp_dir ) . 'ahf-es-import-' . wp_generate_password( 12, false );

		if ( ! wp_mkdir_p( $extract_dir ) ) {
			return new \WP_Error(
				'ahf_es_temp_dir',
				__( 'No se pudo crear el directorio temporal de importación.', 'exportacion-selectiva' )
			);
		}

		$zip = new \ZipArchive();

		if ( true !== $zip->open( $zip_path ) ) {
			return new \WP_Error(
				'ahf_es_zip_open',
				__( 'No se pudo abrir el archivo .wpcontent.', 'exportacion-selectiva' )
			);
		}

		$zip->extractTo( $extract_dir );
		$zip->close();

		$manifest_path = trailingslashit( $extract_dir ) . 'manifest.json';

		if ( ! file_exists( $manifest_path ) ) {
			ahf_es_delete_directory( $extract_dir );
			return new \WP_Error(
				'ahf_es_manifest_missing',
				__( 'El paquete no contiene un manifiesto válido.', 'exportacion-selectiva' )
			);
		}

		$manifest = json_decode( (string) file_get_contents( $manifest_path ), true );

		if ( ! is_array( $manifest ) ) {
			ahf_es_delete_directory( $extract_dir );
			return new \WP_Error(
				'ahf_es_manifest_invalid',
				__( 'El manifiesto del paquete no es válido.', 'exportacion-selectiva' )
			);
		}

		$posts = array();
		$posts_dir = trailingslashit( $extract_dir ) . 'posts';

		if ( is_dir( $posts_dir ) ) {
			foreach ( glob( trailingslashit( $posts_dir ) . '*.json' ) as $post_file ) {
				$post_data = json_decode( (string) file_get_contents( $post_file ), true );

				if ( is_array( $post_data ) ) {
					$posts[] = $post_data;
				}
			}
		}

		$terms = array();
		$terms_path = trailingslashit( $extract_dir ) . 'taxonomies/terms.json';

		if ( file_exists( $terms_path ) ) {
			$decoded_terms = json_decode( (string) file_get_contents( $terms_path ), true );
			$terms         = is_array( $decoded_terms ) ? $decoded_terms : array();
		}

		$media = array();
		$media_path = trailingslashit( $extract_dir ) . 'media/index.json';

		if ( file_exists( $media_path ) ) {
			$decoded_media = json_decode( (string) file_get_contents( $media_path ), true );
			$media         = is_array( $decoded_media ) ? $decoded_media : array();
		}

		return array(
			'extract_dir' => $extract_dir,
			'manifest'    => $manifest,
			'posts'       => $posts,
			'terms'       => $terms,
			'media'       => $media,
		);
	}

	/**
	 * Calcula un checksum simple del directorio de trabajo.
	 *
	 * @param string $directory Directorio base.
	 * @return string
	 */
	private function calculate_checksum( string $directory ): string {
		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $directory, \FilesystemIterator::SKIP_DOTS )
		);

		$hash = hash_init( 'sha256' );

		foreach ( $iterator as $file ) {
			if ( $file->isFile() ) {
				hash_update( $hash, $file->getPathname() );
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_get_contents
				hash_update( $hash, (string) file_get_contents( $file->getPathname() ) );
			}
		}

		return 'sha256:' . hash_final( $hash );
	}

	/**
	 * Añade un directorio completo al ZIP.
	 *
	 * @param \ZipArchive $zip Archivo ZIP.
	 * @param string      $source_dir Directorio origen.
	 * @param string      $prefix Prefijo dentro del ZIP.
	 * @return void
	 */
	private function add_directory_to_zip( \ZipArchive $zip, string $source_dir, string $prefix ): void {
		$source_dir = wp_normalize_path( trailingslashit( $source_dir ) );

		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $source_dir, \FilesystemIterator::SKIP_DOTS )
		);

		foreach ( $iterator as $file ) {
			if ( ! $file->isFile() ) {
				continue;
			}

			$pathname = wp_normalize_path( $file->getPathname() );
			$relative = ltrim( substr( $pathname, strlen( $source_dir ) ), '/' );
			$zip->addFile( $pathname, $prefix . $relative );
		}
	}
}
