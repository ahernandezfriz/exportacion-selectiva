<?php
/**
 * Importador de contenido selectivo.
 *
 * @package ExportacionSelectiva
 */

namespace AHF\ExportacionSelectiva\Import;

use AHF\ExportacionSelectiva\Adapters\Adapter_Interface;
use AHF\ExportacionSelectiva\Adapters\Acf_Adapter;
use AHF\ExportacionSelectiva\Adapters\Elementor_Adapter;
use AHF\ExportacionSelectiva\Adapters\Gutenberg_Adapter;
use AHF\ExportacionSelectiva\Capabilities;
use AHF\ExportacionSelectiva\Package\Wpcontent_Package;
use AHF\ExportacionSelectiva\Url_Remapper;

defined( 'ABSPATH' ) || exit;

/**
 * Clase Importer.
 */
class Importer {

	/**
	 * Adaptadores activos.
	 *
	 * @var Adapter_Interface[]
	 */
	private $adapters;

	/**
	 * Remapeador de URLs de dominio (opcional).
	 *
	 * @var Url_Remapper|null
	 */
	private $url_remapper = null;

	/**
	 * URL origen del paquete (para armar rutas antiguas de uploads).
	 *
	 * @var string
	 */
	private $source_url = '';

	/**
	 * Mapa de URLs de medios antiguas => nuevas.
	 *
	 * @var array<string, string>
	 */
	private $media_url_map = array();

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->adapters = array(
			new Gutenberg_Adapter(),
			new Elementor_Adapter(),
			new Acf_Adapter(),
		);
	}

	/**
	 * Configura el remapeo de URLs origen → destino.
	 *
	 * @param string $source_url URL origen.
	 * @param string $target_url URL destino.
	 * @return void
	 */
	public function set_url_remapper( string $source_url, string $target_url ): void {
		$this->source_url = $source_url;
		$remapper         = new Url_Remapper( $source_url, $target_url );
		$this->url_remapper = $remapper->has_replacements() ? $remapper : null;
	}

	/**
	 * Guarda la URL origen aunque no haya remapeo de dominio.
	 *
	 * @param string $source_url URL origen.
	 * @return void
	 */
	public function set_source_url( string $source_url ): void {
		$this->source_url = $source_url;
	}

	/**
	 * Limpia el remapeador de URLs.
	 *
	 * @return void
	 */
	public function clear_url_remapper(): void {
		$this->url_remapper = null;
	}

	/**
	 * Construye el mapa de URLs de medios a partir del paquete y el Id_Mapper.
	 *
	 * @param array<int, array<string, mixed>> $media Lista de medios del paquete.
	 * @param Id_Mapper                        $mapper Mapa de IDs.
	 * @return void
	 */
	public function prepare_media_url_map( array $media, Id_Mapper $mapper ): void {
		$pairs = array();

		foreach ( $media as $attachment_data ) {
			if ( empty( $attachment_data['source_id'] ) ) {
				continue;
			}

			$new_id = $mapper->get( (int) $attachment_data['source_id'] );

			if ( ! $new_id ) {
				continue;
			}

			$new_url = wp_get_attachment_url( $new_id );

			if ( ! $new_url ) {
				continue;
			}

			$pairs = array_merge( $pairs, $this->build_attachment_url_pairs( $attachment_data, $new_url, $new_id ) );
		}

		uksort(
			$pairs,
			static function ( $a, $b ) {
				return strlen( (string) $b ) <=> strlen( (string) $a );
			}
		);

		$this->media_url_map = $pairs;
	}

	/**
	 * Aplica remapeo de IDs, URLs de medios y dominio a una cadena.
	 *
	 * @param string    $value Cadena.
	 * @param Id_Mapper $mapper Mapa.
	 * @return string
	 */
	private function remap_content_string( string $value, Id_Mapper $mapper ): string {
		$value = $this->replace_ids_in_string( $value, $mapper );

		if ( ! empty( $this->media_url_map ) ) {
			$value = strtr( $value, $this->media_url_map );
		}

		if ( $this->url_remapper ) {
			$value = $this->url_remapper->remap_string( $value );
		}

		return $value;
	}

	/**
	 * Genera pares de URL antigua => nueva para un adjunto.
	 *
	 * @param array<string, mixed> $attachment_data Datos del paquete.
	 * @param string               $new_url URL nueva del adjunto.
	 * @param int                  $new_id ID nuevo.
	 * @return array<string, string>
	 */
	private function build_attachment_url_pairs( array $attachment_data, string $new_url, int $new_id ): array {
		$pairs     = array();
		$file      = isset( $attachment_data['file'] ) ? ltrim( str_replace( '\\', '/', (string) $attachment_data['file'] ), '/' ) : '';
		$new_meta  = wp_get_attachment_metadata( $new_id );
		$old_meta  = array();

		if ( ! empty( $attachment_data['meta']['_wp_attachment_metadata'] ) && is_array( $attachment_data['meta']['_wp_attachment_metadata'] ) ) {
			$old_meta = $attachment_data['meta']['_wp_attachment_metadata'];
		}

		$old_candidates = array();

		if ( ! empty( $attachment_data['guid'] ) ) {
			$old_candidates[] = (string) $attachment_data['guid'];
		}

		if ( $file ) {
			$old_candidates[] = '/wp-content/uploads/' . $file;
			$old_candidates[] = 'wp-content/uploads/' . $file;
			$old_candidates[] = untrailingslashit( home_url() ) . '/wp-content/uploads/' . $file;

			if ( $this->source_url ) {
				$old_candidates[] = untrailingslashit( $this->source_url ) . '/wp-content/uploads/' . $file;
			}

			if ( $this->url_remapper ) {
				foreach ( array_keys( $this->url_remapper->get_replacements() ) as $from_base ) {
					$old_candidates[] = untrailingslashit( $from_base ) . '/wp-content/uploads/' . $file;
				}
			}
		}

		foreach ( array_unique( array_filter( $old_candidates ) ) as $old_url ) {
			$pairs[ $old_url ] = $new_url;
			$pairs[ str_replace( '/', '\/', $old_url ) ] = str_replace( '/', '\/', $new_url );
		}

		// Tamaños derivados (thumbnail, medium, etc.).
		if ( $file && ! empty( $old_meta['sizes'] ) && is_array( $old_meta['sizes'] ) && ! empty( $new_meta['sizes'] ) && is_array( $new_meta['sizes'] ) ) {
			$old_dir = trailingslashit( dirname( $file ) );
			$new_dir_url = trailingslashit( dirname( $new_url ) );

			foreach ( $old_meta['sizes'] as $size => $info ) {
				if ( empty( $info['file'] ) || empty( $new_meta['sizes'][ $size ]['file'] ) ) {
					continue;
				}

				$old_size_rel = ( '.' === $old_dir || './' === $old_dir ) ? $info['file'] : $old_dir . $info['file'];
				$old_size_rel = ltrim( str_replace( '\\', '/', $old_size_rel ), '/' );
				$new_size_url = $new_dir_url . $new_meta['sizes'][ $size ]['file'];

				$size_candidates = array(
					'/wp-content/uploads/' . $old_size_rel,
					'wp-content/uploads/' . $old_size_rel,
					untrailingslashit( home_url() ) . '/wp-content/uploads/' . $old_size_rel,
				);

				if ( $this->source_url ) {
					$size_candidates[] = untrailingslashit( $this->source_url ) . '/wp-content/uploads/' . $old_size_rel;
				}

				if ( ! empty( $attachment_data['guid'] ) ) {
					$size_candidates[] = trailingslashit( dirname( (string) $attachment_data['guid'] ) ) . $info['file'];
				}

				if ( $this->url_remapper ) {
					foreach ( array_keys( $this->url_remapper->get_replacements() ) as $from_base ) {
						$size_candidates[] = untrailingslashit( $from_base ) . '/wp-content/uploads/' . $old_size_rel;
					}
				}

				foreach ( array_unique( array_filter( $size_candidates ) ) as $old_size_url ) {
					$pairs[ $old_size_url ] = $new_size_url;
					$pairs[ str_replace( '/', '\/', $old_size_url ) ] = str_replace( '/', '\/', $new_size_url );
				}
			}
		}

		return $pairs;
	}

	/**
	 * Analiza un paquete y devuelve un preview para el wizard.
	 *
	 * @param string $zip_path Ruta del archivo subido.
	 * @return array<string, mixed>|\WP_Error
	 */
	public function analyze( string $zip_path ) {
		$package = new Wpcontent_Package();
		$data    = $package->read( $zip_path );

		if ( is_wp_error( $data ) ) {
			return $data;
		}

		$conflicts = new Conflict_Resolver();
		$preview   = array();

		foreach ( $data['posts'] as $index => $item ) {
			$conflict = $conflicts->detect( $item );

			$preview[] = array(
				'index'       => $index,
				'source_id'   => (int) $item['source_id'],
				'post_type'   => $item['post_type'],
				'post_title'  => $item['post_title'],
				'post_name'   => $item['post_name'],
				'status'      => $conflict['status'],
				'existing_id' => $conflict['existing_id'],
				'message'     => $conflict['message'],
				'comparison'  => $conflict['comparison'] ?? null,
			);
		}

		$session_key = 'ahf_es_import_' . wp_generate_password( 16, false );
		$upload_dir  = ahf_es_get_temp_dir();

		if ( is_wp_error( $upload_dir ) ) {
			ahf_es_delete_directory( $data['extract_dir'] );
			return $upload_dir;
		}

		$persistent_zip = trailingslashit( $upload_dir ) . $session_key . '.wpcontent';

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_copy
		copy( $zip_path, $persistent_zip );

		set_transient(
			$session_key,
			array(
				'zip_path'     => $persistent_zip,
				'extract_dir'  => $data['extract_dir'],
				'manifest'     => $data['manifest'],
				'user_id'      => get_current_user_id(),
				'created_at'   => time(),
			),
			HOUR_IN_SECONDS
		);

		return array(
			'session_key' => $session_key,
			'manifest'    => $data['manifest'],
			'items'       => $preview,
		);
	}

	/**
	 * Ejecuta la importación según la selección del usuario.
	 *
	 * @param string               $session_key Clave de sesión temporal.
	 * @param int[]                $indexes Índices seleccionados.
	 * @param string               $policy Política de conflicto.
	 * @param string               $target_post_type Post type destino.
	 * @return array<string, int>|\WP_Error
	 */
	public function import( string $session_key, array $indexes, string $policy, string $target_post_type ) {
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
		$data    = $package->read( $session['zip_path'] );

		if ( is_wp_error( $data ) ) {
			return $data;
		}

		$source_url = (string) ( $data['manifest']['source_url'] ?? ( $session['manifest']['source_url'] ?? '' ) );

		if ( $source_url ) {
			$this->set_source_url( $source_url );
			$this->set_url_remapper( $source_url, home_url() );
		}

		$conflicts = new Conflict_Resolver();
		$policy    = $conflicts->sanitize_policy( $policy );
		$mapper    = new Id_Mapper();
		$counts    = array(
			'created'    => 0,
			'updated'    => 0,
			'skipped'    => 0,
			'duplicated' => 0,
			'compared'   => 0,
		);

		$this->import_terms( $data['terms'], $mapper );
		$this->import_media( $data['media'], $data['extract_dir'], $mapper );
		$this->prepare_media_url_map( $data['media'], $mapper );

		$indexes = array_values( array_unique( array_map( 'absint', $indexes ) ) );

		foreach ( $indexes as $index ) {
			if ( ! isset( $data['posts'][ $index ] ) ) {
				continue;
			}

			$result_key = $this->import_single_item( $data['posts'][ $index ], $policy, $target_post_type, $mapper );

			if ( isset( $counts[ $result_key ] ) ) {
				++$counts[ $result_key ];
			} else {
				++$counts['skipped'];
			}
		}

		$this->cleanup_session( $session, $data, $session_key );

		return $counts;
	}

	/**
	 * Importa términos taxonómicos.
	 *
	 * @param array<int, array<string, mixed>> $terms Términos del paquete.
	 * @param Id_Mapper                        $mapper Mapa de IDs.
	 * @return void
	 */
	public function import_terms( array $terms, Id_Mapper $mapper ): void {
		foreach ( $terms as $term_data ) {
			$existing = term_exists( $term_data['slug'], $term_data['taxonomy'] );

			if ( is_array( $existing ) ) {
				$mapper->set( (int) $term_data['source_id'], (int) $existing['term_id'] );
				continue;
			}

			$result = wp_insert_term(
				$term_data['name'],
				$term_data['taxonomy'],
				array(
					'slug'        => $term_data['slug'],
					'description' => $term_data['description'] ?? '',
					'parent'      => $mapper->get( (int) ( $term_data['parent'] ?? 0 ) ),
				)
			);

			if ( ! is_wp_error( $result ) ) {
				$mapper->set( (int) $term_data['source_id'], (int) $result['term_id'] );
			}
		}
	}

	/**
	 * Importa un lote de archivos multimedia.
	 *
	 * @param array<int, array<string, mixed>> $media Lista de medios.
	 * @param string                           $extract_dir Directorio extraído.
	 * @param Id_Mapper                        $mapper Mapa de IDs.
	 * @param int                              $offset Offset.
	 * @param int                              $limit Límite.
	 * @return int Cantidad procesada en este lote.
	 */
	public function import_media_batch( array $media, string $extract_dir, Id_Mapper $mapper, int $offset, int $limit ): int {
		$slice     = array_slice( $media, $offset, $limit );
		$processed = 0;

		foreach ( $slice as $attachment_data ) {
			$this->import_single_media( $attachment_data, $extract_dir, $mapper );
			++$processed;
		}

		return $processed;
	}

	/**
	 * Importa archivos multimedia del paquete.
	 *
	 * @param array<int, array<string, mixed>> $media Lista de medios.
	 * @param string                           $extract_dir Directorio extraído.
	 * @param Id_Mapper                        $mapper Mapa de IDs.
	 * @return void
	 */
	public function import_media( array $media, string $extract_dir, Id_Mapper $mapper ): void {
		$this->import_media_batch( $media, $extract_dir, $mapper, 0, count( $media ) );
	}

	/**
	 * Importa un adjunto individual.
	 *
	 * @param array<string, mixed> $attachment_data Datos.
	 * @param string               $extract_dir Directorio.
	 * @param Id_Mapper            $mapper Mapa.
	 * @return void
	 */
	public function import_single_media( array $attachment_data, string $extract_dir, Id_Mapper $mapper ): void {
		$source_file = trailingslashit( $extract_dir ) . 'media/files/' . $attachment_data['file'];

		if ( ! file_exists( $source_file ) ) {
			return;
		}

		$upload = wp_upload_bits(
			basename( $source_file ),
			null,
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			file_get_contents( $source_file )
		);

		if ( ! empty( $upload['error'] ) ) {
			return;
		}

		$attachment = array(
			'post_title'     => $attachment_data['post_title'],
			'post_name'      => $attachment_data['post_name'],
			'post_mime_type' => $attachment_data['post_mime_type'],
			'post_content'   => $attachment_data['post_content'] ?? '',
			'post_excerpt'   => $attachment_data['post_excerpt'] ?? '',
			'post_status'    => 'inherit',
			'guid'           => $upload['url'],
		);

		$attachment_id = wp_insert_attachment( $attachment, $upload['file'] );

		if ( is_wp_error( $attachment_id ) || ! $attachment_id ) {
			return;
		}

		require_once ABSPATH . 'wp-admin/includes/image.php';

		$metadata = wp_generate_attachment_metadata( $attachment_id, $upload['file'] );
		wp_update_attachment_metadata( $attachment_id, $metadata );

		if ( ! empty( $attachment_data['meta'] ) && is_array( $attachment_data['meta'] ) ) {
			// No pisar ruta/metadatos locales: el archivo se subió a una ruta nueva.
			$skip_meta = array(
				'_wp_attached_file',
				'_wp_attachment_metadata',
				'_wp_attachment_tmp',
			);

			foreach ( $attachment_data['meta'] as $meta_key => $meta_value ) {
				if ( in_array( $meta_key, $skip_meta, true ) ) {
					continue;
				}

				update_post_meta( $attachment_id, $meta_key, $meta_value );
			}
		}

		$mapper->set( (int) $attachment_data['source_id'], (int) $attachment_id );
	}

	/**
	 * Importa un ítem de post según la política de conflicto.
	 *
	 * @param array<string, mixed> $item Datos del item.
	 * @param string               $policy Política.
	 * @param string               $target_post_type Post type.
	 * @param Id_Mapper            $mapper Mapa.
	 * @return string Resultado: created|updated|skipped|duplicated|compared.
	 */
	public function import_single_item( array $item, string $policy, string $target_post_type, Id_Mapper $mapper ): string {
		$conflicts = new Conflict_Resolver();
		$policy    = $conflicts->sanitize_policy( $policy );
		$conflict  = $conflicts->detect( $item );

		if ( 'exists' === $conflict['status'] && in_array( $policy, array( Conflict_Resolver::POLICY_SKIP, Conflict_Resolver::POLICY_COMPARE ), true ) ) {
			$mapper->set( (int) $item['source_id'], $conflict['existing_id'] );
			return Conflict_Resolver::POLICY_COMPARE === $policy ? 'compared' : 'skipped';
		}

		$postarr = $this->build_postarr( $item, $target_post_type, $mapper );
		$result  = 'created';

		if ( 'exists' === $conflict['status'] && Conflict_Resolver::POLICY_UPDATE === $policy ) {
			$postarr['ID'] = $conflict['existing_id'];
			$post_id       = wp_update_post( $postarr, true );
			$result        = 'updated';
		} elseif ( 'exists' === $conflict['status'] && Conflict_Resolver::POLICY_DUPLICATE === $policy ) {
			unset( $postarr['ID'] );
			$postarr['post_title'] = $item['post_title'] . ' duplicated';
			$postarr['post_name']  = wp_unique_post_slug(
				$item['post_name'] . '-duplicated',
				0,
				$item['post_status'],
				$target_post_type,
				0
			);
			$post_id = wp_insert_post( $postarr, true );
			$result  = 'duplicated';
		} else {
			$post_id = wp_insert_post( $postarr, true );
			$result  = 'created';
		}

		if ( is_wp_error( $post_id ) || ! $post_id ) {
			return 'skipped';
		}

		$mapper->set( (int) $item['source_id'], (int) $post_id );
		$this->import_post_meta( (int) $post_id, $item['meta'] ?? array(), $mapper );
		$this->import_post_terms( (int) $post_id, $item['terms'] ?? array() );
		$this->import_thumbnail( (int) $post_id, (int) ( $item['thumbnail_id'] ?? 0 ), $mapper );

		$adapters_data = $item['adapters'] ?? array();
		$adapters_data = $this->remap_content_value( $adapters_data, $mapper );

		$this->import_adapters( (int) $post_id, is_array( $adapters_data ) ? $adapters_data : array(), $mapper );

		return $result;
	}

	/**
	 * Remapea recursivamente valores mixtos (arrays/strings).
	 *
	 * @param mixed     $value Valor.
	 * @param Id_Mapper $mapper Mapa.
	 * @return mixed
	 */
	private function remap_content_value( $value, Id_Mapper $mapper ) {
		if ( is_array( $value ) ) {
			$remapped = array();

			foreach ( $value as $key => $item ) {
				$remapped[ $key ] = $this->remap_content_value( $item, $mapper );
			}

			return $remapped;
		}

		if ( is_string( $value ) ) {
			return $this->remap_content_string( $value, $mapper );
		}

		return $value;
	}

	/**
	 * Limpia archivos temporales de una sesión de importación.
	 *
	 * @param array<string, mixed> $session Sesión.
	 * @param array<string, mixed> $data Datos del paquete leído.
	 * @param string               $session_key Clave transient.
	 * @return void
	 */
	public function cleanup_session( array $session, array $data, string $session_key ): void {
		delete_transient( $session_key );

		if ( ! empty( $session['zip_path'] ) && file_exists( $session['zip_path'] ) ) {
			wp_delete_file( $session['zip_path'] );
		}

		if ( ! empty( $data['extract_dir'] ) ) {
			ahf_es_delete_directory( $data['extract_dir'] );
		}

		if ( ! empty( $session['extract_dir'] ) && is_dir( $session['extract_dir'] ) ) {
			ahf_es_delete_directory( $session['extract_dir'] );
		}
	}

	/**
	 * Construye el array para insertar/actualizar un post.
	 *
	 * @param array<string, mixed> $item Datos del item.
	 * @param string               $target_post_type Post type destino.
	 * @param Id_Mapper            $mapper Mapa de IDs.
	 * @return array<string, mixed>
	 */
	private function build_postarr( array $item, string $target_post_type, Id_Mapper $mapper ): array {
		$parent_id = $mapper->get( (int) ( $item['post_parent'] ?? 0 ) );

		return array(
			'post_type'      => $target_post_type,
			'post_status'    => $item['post_status'],
			'post_title'     => $item['post_title'],
			'post_name'      => $item['post_name'],
			'post_content'   => $this->remap_content_string( $item['post_content'] ?? '', $mapper ),
			'post_excerpt'   => $this->remap_content_string( (string) ( $item['post_excerpt'] ?? '' ), $mapper ),
			'post_parent'    => $parent_id,
			'menu_order'     => (int) ( $item['menu_order'] ?? 0 ),
			'comment_status' => $item['comment_status'] ?? 'closed',
			'ping_status'    => $item['ping_status'] ?? 'closed',
			'post_password'  => $item['post_password'] ?? '',
		);
	}

	/**
	 * Importa metadatos de un post.
	 *
	 * @param int                  $post_id ID del post.
	 * @param array<string, mixed> $meta Metadatos.
	 * @param Id_Mapper            $mapper Mapa de IDs.
	 * @return void
	 */
	private function import_post_meta( int $post_id, array $meta, Id_Mapper $mapper ): void {
		foreach ( $meta as $meta_key => $meta_value ) {
			// La destacada se asigna después con set_post_thumbnail() y el mapa de IDs.
			if ( in_array( $meta_key, array( '_edit_lock', '_thumbnail_id' ), true ) ) {
				continue;
			}

			if ( is_string( $meta_value ) ) {
				$meta_value = $this->remap_content_string( $meta_value, $mapper );
			} elseif ( is_array( $meta_value ) ) {
				$meta_value = $this->remap_content_value( $meta_value, $mapper );
			}

			update_post_meta( $post_id, $meta_key, $meta_value );
		}
	}

	/**
	 * Importa términos de un post.
	 *
	 * @param int                              $post_id ID del post.
	 * @param array<int, array<string, string>> $terms Términos.
	 * @return void
	 */
	private function import_post_terms( int $post_id, array $terms ): void {
		$grouped = array();

		foreach ( $terms as $term ) {
			if ( empty( $term['taxonomy'] ) || empty( $term['slug'] ) ) {
				continue;
			}

			$grouped[ $term['taxonomy'] ][] = $term['slug'];
		}

		foreach ( $grouped as $taxonomy => $slugs ) {
			wp_set_object_terms( $post_id, $slugs, $taxonomy, false );
		}
	}

	/**
	 * Asigna la imagen destacada.
	 *
	 * @param int       $post_id ID del post.
	 * @param int       $old_thumbnail_id ID antiguo del thumbnail.
	 * @param Id_Mapper $mapper Mapa de IDs.
	 * @return void
	 */
	private function import_thumbnail( int $post_id, int $old_thumbnail_id, Id_Mapper $mapper ): void {
		if ( $old_thumbnail_id <= 0 ) {
			return;
		}

		$new_thumbnail_id = $mapper->get( $old_thumbnail_id );

		if ( ! $new_thumbnail_id ) {
			return;
		}

		// Confirma que el adjunto existe y es válido en el destino.
		if ( 'attachment' !== get_post_type( $new_thumbnail_id ) ) {
			return;
		}

		set_post_thumbnail( $post_id, $new_thumbnail_id );
	}

	/**
	 * Ejecuta adaptadores tras la importación.
	 *
	 * @param int                              $post_id ID del post.
	 * @param array<string, array<string,mixed>> $adapters_data Datos de adaptadores.
	 * @param Id_Mapper                        $mapper Mapa de IDs.
	 * @return void
	 */
	private function import_adapters( int $post_id, array $adapters_data, Id_Mapper $mapper ): void {
		foreach ( $this->adapters as $adapter ) {
			$slug = $adapter->get_slug();

			if ( isset( $adapters_data[ $slug ] ) ) {
				$adapter->import( $post_id, $adapters_data[ $slug ], $mapper->all() );
			}
		}
	}

	/**
	 * Reemplaza referencias de IDs en una cadena.
	 *
	 * @param string    $value Cadena original.
	 * @param Id_Mapper $mapper Mapa de IDs.
	 * @return string
	 */
	private function replace_ids_in_string( string $value, Id_Mapper $mapper ): string {
		foreach ( $mapper->all() as $old_id => $new_id ) {
			$value = str_replace(
				array(
					'wp-image-' . $old_id,
					'"id":' . $old_id,
					'"id": ' . $old_id,
					's:' . strlen( (string) $old_id ) . ':"' . $old_id . '"',
				),
				array(
					'wp-image-' . $new_id,
					'"id":' . $new_id,
					'"id": ' . $new_id,
					's:' . strlen( (string) $new_id ) . ':"' . $new_id . '"',
				),
				$value
			);
		}

		return $value;
	}
}
