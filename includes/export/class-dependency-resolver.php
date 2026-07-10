<?php
/**
 * Resuelve dependencias de exportación.
 *
 * @package ExportacionSelectiva
 */

namespace AHF\ExportacionSelectiva\Export;

use AHF\ExportacionSelectiva\Adapters\Acf_Adapter;
use AHF\ExportacionSelectiva\Adapters\Elementor_Adapter;

defined( 'ABSPATH' ) || exit;

/**
 * Clase Dependency_Resolver.
 */
class Dependency_Resolver {

	/**
	 * Expande IDs de posts con dependencias relacionadas.
	 *
	 * @param int[] $post_ids IDs seleccionados por el usuario.
	 * @return array{
	 *     post_ids: int[],
	 *     attachment_ids: int[],
	 *     term_ids: int[]
	 * }
	 */
	public function resolve( array $post_ids ): array {
		$post_ids = array_values( array_unique( array_map( 'absint', $post_ids ) ) );
		$attachment_ids = array();
		$term_ids = array();

		foreach ( $post_ids as $post_id ) {
			$post = get_post( $post_id );

			if ( ! $post || 'attachment' === $post->post_type ) {
				continue;
			}

			$thumbnail_id = (int) get_post_thumbnail_id( $post_id );

			if ( $thumbnail_id ) {
				$attachment_ids[] = $thumbnail_id;
			}

			$children = get_children(
				array(
					'post_parent'    => $post_id,
					'post_type'      => 'attachment',
					'post_status'    => 'inherit',
					'posts_per_page' => -1,
					'fields'         => 'ids',
				)
			);

			if ( ! empty( $children ) ) {
				$attachment_ids = array_merge( $attachment_ids, $children );
			}

			$content_attachments = $this->extract_attachment_ids_from_content( $post->post_content );

			if ( ! empty( $content_attachments ) ) {
				$attachment_ids = array_merge( $attachment_ids, $content_attachments );
			}

			$attachment_ids = array_merge(
				$attachment_ids,
				Elementor_Adapter::collect_attachment_ids( $post_id ),
				Acf_Adapter::collect_attachment_ids( $post_id )
			);

			$terms = wp_get_object_terms( $post_id, get_object_taxonomies( $post->post_type ) );

			if ( ! is_wp_error( $terms ) ) {
				foreach ( $terms as $term ) {
					$term_ids[] = (int) $term->term_id;
				}
			}
		}

		$attachment_ids = array_values( array_unique( array_map( 'absint', $attachment_ids ) ) );

		return array(
			'post_ids'       => $post_ids,
			'attachment_ids' => $attachment_ids,
			'term_ids'       => array_values( array_unique( array_map( 'absint', $term_ids ) ) ),
		);
	}

	/**
	 * Extrae IDs de adjuntos referenciados en el contenido.
	 *
	 * @param string $content Contenido del post.
	 * @return int[]
	 */
	private function extract_attachment_ids_from_content( string $content ): array {
		$ids = array();

		if ( preg_match_all( '/wp-image-(\d+)/', $content, $matches ) ) {
			$ids = array_merge( $ids, array_map( 'absint', $matches[1] ) );
		}

		if ( preg_match_all( '/"id"\s*:\s*(\d+)/', $content, $matches ) ) {
			foreach ( $matches[1] as $maybe_id ) {
				$attachment_id = absint( $maybe_id );

				if ( $attachment_id && 'attachment' === get_post_type( $attachment_id ) ) {
					$ids[] = $attachment_id;
				}
			}
		}

		return array_values( array_unique( $ids ) );
	}
}
