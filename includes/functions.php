<?php
/**
 * Funciones auxiliares del plugin.
 *
 * @package ExportacionSelectiva
 */

defined( 'ABSPATH' ) || exit;

/**
 * Obtiene un directorio temporal escribible.
 *
 * @return string|\WP_Error
 */
function ahf_es_get_temp_dir() {
	$upload_dir = wp_upload_dir();

	if ( ! empty( $upload_dir['error'] ) ) {
		return new WP_Error(
			'ahf_es_upload_dir',
			$upload_dir['error']
		);
	}

	$temp_dir = trailingslashit( $upload_dir['basedir'] ) . 'exportacion-selectiva-temp';

	if ( ! wp_mkdir_p( $temp_dir ) ) {
		return new WP_Error(
			'ahf_es_temp_dir',
			__( 'No se pudo crear el directorio temporal.', 'exportacion-selectiva' )
		);
	}

	$index_file = trailingslashit( $temp_dir ) . 'index.php';

	if ( ! file_exists( $index_file ) ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		file_put_contents( $index_file, "<?php\n// Silence is golden.\n" );
	}

	return $temp_dir;
}

/**
 * Elimina un directorio de forma recursiva.
 *
 * @param string $directory Ruta del directorio.
 * @return void
 */
function ahf_es_delete_directory( string $directory ): void {
	if ( ! is_dir( $directory ) ) {
		return;
	}

	$iterator = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $directory, FilesystemIterator::SKIP_DOTS ),
		RecursiveIteratorIterator::CHILD_FIRST
	);

	foreach ( $iterator as $file ) {
		if ( $file->isDir() ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
			rmdir( $file->getPathname() );
		} else {
			wp_delete_file( $file->getPathname() );
		}
	}

	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
	rmdir( $directory );
}

/**
 * Envía un archivo al navegador y termina la ejecución.
 *
 * @param string $file_path Ruta del archivo.
 * @param string $filename Nombre de descarga.
 * @return void
 */
function ahf_es_send_file_download( string $file_path, string $filename ): void {
	if ( ! file_exists( $file_path ) ) {
		wp_die( esc_html__( 'El archivo de exportación no existe.', 'exportacion-selectiva' ) );
	}

	nocache_headers();
	header( 'Content-Type: application/octet-stream' );
	header( 'Content-Disposition: attachment; filename="' . sanitize_file_name( $filename ) . '"' );
	header( 'Content-Length: ' . (string) filesize( $file_path ) );
	header( 'Pragma: no-cache' );

	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile
	readfile( $file_path );
	wp_delete_file( $file_path );
	exit;
}

/**
 * Obtiene los post types exportables.
 *
 * @return string[]
 */
function ahf_es_get_exportable_post_types(): array {
	return array_values(
		array_filter(
			get_post_types( array( 'show_ui' => true ), 'names' ),
			static function ( $post_type ) {
				$object = get_post_type_object( $post_type );
				return $object && $object->can_export;
			}
		)
	);
}

/**
 * Genera un UUID v4.
 *
 * @return string
 */
function ahf_es_generate_uuid(): string {
	if ( function_exists( 'wp_generate_uuid4' ) ) {
		return wp_generate_uuid4();
	}

	$data    = random_bytes( 16 );
	$data[6] = chr( ord( $data[6] ) & 0x0f | 0x40 );
	$data[8] = chr( ord( $data[8] ) & 0x3f | 0x80 );

	return vsprintf( '%s%s-%s-%s-%s-%s%s%s', str_split( bin2hex( $data ), 4 ) );
}

/**
 * Codifica datos a JSON de forma segura para exportación.
 *
 * @param mixed $data Datos a codificar.
 * @return string
 */
function ahf_es_json_encode( $data ): string {
	$json = wp_json_encode(
		ahf_es_prepare_for_json( $data ),
		JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE
	);

	if ( false === $json ) {
		return wp_json_encode(
			array(
				'error' => 'json_encode_failed',
			),
			JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE
		);
	}

	return $json;
}

/**
 * Prepara recursivamente datos para serialización JSON.
 *
 * @param mixed $value Valor a normalizar.
 * @return mixed
 */
function ahf_es_prepare_for_json( $value ) {
	if ( is_array( $value ) ) {
		$prepared = array();

		foreach ( $value as $key => $item ) {
			$prepared[ $key ] = ahf_es_prepare_for_json( $item );
		}

		return $prepared;
	}

	if ( is_object( $value ) ) {
		if ( $value instanceof \JsonSerializable ) {
			return ahf_es_prepare_for_json( $value->jsonSerialize() );
		}

		return ahf_es_prepare_for_json( (array) $value );
	}

	if ( is_string( $value ) ) {
		if ( function_exists( 'mb_convert_encoding' ) ) {
			$encoding = mb_detect_encoding( $value, mb_detect_order(), true );

			if ( $encoding && 'UTF-8' !== $encoding ) {
				$converted = mb_convert_encoding( $value, 'UTF-8', $encoding );

				if ( false !== $converted ) {
					return $converted;
				}
			}
		}

		return wp_check_invalid_utf8( $value, true );
	}

	if ( is_resource( $value ) ) {
		return null;
	}

	return $value;
}
