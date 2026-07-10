<?php
/**
 * Adaptador básico para contenido Gutenberg.
 *
 * @package ExportacionSelectiva
 */

namespace AHF\ExportacionSelectiva\Adapters;

defined( 'ABSPATH' ) || exit;

/**
 * Clase Gutenberg_Adapter.
 */
class Gutenberg_Adapter implements Adapter_Interface {

	/**
	 * {@inheritDoc}
	 */
	public function get_slug(): string {
		return 'gutenberg';
	}

	/**
	 * {@inheritDoc}
	 */
	public function supports( \WP_Post $post ): bool {
		return function_exists( 'has_blocks' ) && has_blocks( $post );
	}

	/**
	 * {@inheritDoc}
	 */
	public function export( \WP_Post $post ): array {
		$blocks = parse_blocks( $post->post_content );

		return array(
			'has_blocks'   => true,
			'blocks_count' => count( $blocks ),
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function import( int $post_id, array $data, array $id_map ): void {
		$post = get_post( $post_id );

		if ( ! $post || empty( $post->post_content ) ) {
			return;
		}

		$content = $post->post_content;

		foreach ( $id_map as $old_id => $new_id ) {
			$content = str_replace(
				array(
					'wp-image-' . $old_id,
					'"id":' . $old_id,
					'"id": ' . $old_id,
				),
				array(
					'wp-image-' . $new_id,
					'"id":' . $new_id,
					'"id": ' . $new_id,
				),
				$content
			);
		}

		if ( $content !== $post->post_content ) {
			wp_update_post(
				array(
					'ID'           => $post_id,
					'post_content' => $content,
				)
			);
		}
	}
}
