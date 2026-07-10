<?php
/**
 * Exportador de contenido selectivo.
 *
 * @package ExportacionSelectiva
 */

namespace AHF\ExportacionSelectiva\Export;

use AHF\ExportacionSelectiva\Adapters\Adapter_Interface;
use AHF\ExportacionSelectiva\Adapters\Acf_Adapter;
use AHF\ExportacionSelectiva\Adapters\Elementor_Adapter;
use AHF\ExportacionSelectiva\Adapters\Gutenberg_Adapter;
use AHF\ExportacionSelectiva\Package\Wpcontent_Package;

defined( 'ABSPATH' ) || exit;

/**
 * Clase Exporter.
 */
class Exporter {

	/**
	 * Adaptadores activos.
	 *
	 * @var Adapter_Interface[]
	 */
	private $adapters;

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
	 * Exporta posts seleccionados y fuerza la descarga.
	 *
	 * @param int[] $post_ids IDs seleccionados.
	 * @return void
	 */
	public function export_and_download( array $post_ids ): void {
		$result = $this->export( $post_ids );

		if ( is_wp_error( $result ) ) {
			wp_die( esc_html( $result->get_error_message() ) );
		}

		ahf_es_send_file_download( $result, basename( $result ) );
	}

	/**
	 * Exporta posts seleccionados.
	 *
	 * @param int[] $post_ids IDs seleccionados.
	 * @return string|\WP_Error Ruta del archivo generado.
	 */
	public function export( array $post_ids ) {
		$post_ids = array_values( array_unique( array_map( 'absint', $post_ids ) ) );

		if ( empty( $post_ids ) ) {
			return new \WP_Error(
				'ahf_es_no_posts',
				__( 'No se seleccionaron elementos para exportar.', 'exportacion-selectiva' )
			);
		}

		$resolver   = new Dependency_Resolver();
		$resolved   = $resolver->resolve( $post_ids );
		$posts_data = array();
		$post_types = array();

		foreach ( $resolved['post_ids'] as $post_id ) {
			$serialized = $this->serialize_post( $post_id );

			if ( null === $serialized ) {
				continue;
			}

			$posts_data[] = $serialized;
			$post_types[] = $serialized['post_type'];
		}

		if ( empty( $posts_data ) ) {
			return new \WP_Error(
				'ahf_es_invalid_posts',
				__( 'No se pudo serializar el contenido seleccionado.', 'exportacion-selectiva' )
			);
		}

		$attachments_data = array();

		foreach ( $resolved['attachment_ids'] as $attachment_id ) {
			$attachment = $this->serialize_attachment( $attachment_id );

			if ( null !== $attachment ) {
				$attachments_data[] = $attachment;
			}
		}

		$terms_data = $this->serialize_terms( $resolved['term_ids'] );

		$manifest = array(
			'format_version' => AHF_ES_FORMAT_VERSION,
			'plugin_version' => AHF_ES_VERSION,
			'export_uuid'    => ahf_es_generate_uuid(),
			'exported_at'    => gmdate( 'c' ),
			'source_url'     => home_url(),
			'wp_version'     => get_bloginfo( 'version' ),
			'php_version'    => PHP_VERSION,
			'post_types'     => array_values( array_unique( $post_types ) ),
			'items_count'    => count( $posts_data ),
			'media_count'    => count( $attachments_data ),
			'terms_count'    => count( $terms_data ),
		);

		$package = new Wpcontent_Package();

		return $package->build( $manifest, $posts_data, $attachments_data, $terms_data );
	}

	/**
	 * Serializa un post para exportación.
	 *
	 * @param int $post_id ID del post.
	 * @return array<string, mixed>|null
	 */
	public function serialize_post( int $post_id ): ?array {
		$post = get_post( $post_id );

		if ( ! $post || 'attachment' === $post->post_type || 'auto-draft' === $post->post_status ) {
			return null;
		}

		$meta = array();

		foreach ( get_post_meta( $post_id ) as $meta_key => $values ) {
			if ( '_edit_lock' === $meta_key ) {
				continue;
			}

			$meta[ $meta_key ] = maybe_unserialize( $values[0] );
		}

		$terms = array();
		$taxonomies = get_object_taxonomies( $post->post_type );

		foreach ( $taxonomies as $taxonomy ) {
			$post_terms = wp_get_object_terms( $post_id, $taxonomy );

			if ( is_wp_error( $post_terms ) || empty( $post_terms ) ) {
				continue;
			}

			foreach ( $post_terms as $term ) {
				$terms[] = array(
					'taxonomy' => $taxonomy,
					'slug'     => $term->slug,
					'name'     => $term->name,
				);
			}
		}

		$adapter_data = array();

		foreach ( $this->adapters as $adapter ) {
			if ( $adapter->supports( $post ) ) {
				$adapter_data[ $adapter->get_slug() ] = $adapter->export( $post );
			}
		}

		return array(
			'source_id'      => $post_id,
			'post_type'      => $post->post_type,
			'post_status'    => $post->post_status,
			'post_title'     => $post->post_title,
			'post_name'      => $post->post_name,
			'post_content'   => $post->post_content,
			'post_excerpt'   => $post->post_excerpt,
			'post_parent'    => (int) $post->post_parent,
			'menu_order'     => (int) $post->menu_order,
			'comment_status' => $post->comment_status,
			'ping_status'    => $post->ping_status,
			'post_password'  => $post->post_password,
			'post_date_gmt'  => $post->post_date_gmt,
			'meta'           => $meta,
			'terms'          => $terms,
			'thumbnail_id'   => (int) get_post_thumbnail_id( $post_id ),
			'adapters'       => $adapter_data,
		);
	}

	/**
	 * Serializa un adjunto.
	 *
	 * @param int $attachment_id ID del adjunto.
	 * @return array<string, mixed>|null
	 */
	public function serialize_attachment( int $attachment_id ): ?array {
		$attachment = get_post( $attachment_id );

		if ( ! $attachment || 'attachment' !== $attachment->post_type ) {
			return null;
		}

		$file = get_attached_file( $attachment_id );

		if ( ! $file ) {
			return null;
		}

		$meta = array();

		foreach ( get_post_meta( $attachment_id ) as $meta_key => $values ) {
			$meta[ $meta_key ] = maybe_unserialize( $values[0] );
		}

		return array(
			'source_id' => $attachment_id,
			'post_title' => $attachment->post_title,
			'post_name'  => $attachment->post_name,
			'post_mime_type' => $attachment->post_mime_type,
			'post_content'   => $attachment->post_content,
			'post_excerpt'   => $attachment->post_excerpt,
			'guid'           => $attachment->guid,
			'file'           => _wp_relative_upload_path( $file ) ?: wp_basename( $file ),
			'meta'           => $meta,
		);
	}

	/**
	 * Serializa términos taxonómicos.
	 *
	 * @param int[] $term_ids IDs de términos.
	 * @return array<int, array<string, mixed>>
	 */
	public function serialize_terms( array $term_ids ): array {
		$terms_data = array();

		foreach ( $term_ids as $term_id ) {
			$term = get_term( $term_id );

			if ( ! $term || is_wp_error( $term ) ) {
				continue;
			}

			$terms_data[ $term_id ] = array(
				'source_id'   => (int) $term->term_id,
				'taxonomy'    => $term->taxonomy,
				'slug'        => $term->slug,
				'name'        => $term->name,
				'description' => $term->description,
				'parent'      => (int) $term->parent,
			);
		}

		return $terms_data;
	}
}
