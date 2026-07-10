<?php
/**
 * Adaptador para contenido Elementor.
 *
 * @package ExportacionSelectiva
 */

namespace AHF\ExportacionSelectiva\Adapters;

use AHF\ExportacionSelectiva\Id_Remapper_Helper;

defined( 'ABSPATH' ) || exit;

/**
 * Clase Elementor_Adapter.
 */
class Elementor_Adapter implements Adapter_Interface {

	/**
	 * Metas relevantes de Elementor.
	 *
	 * @var string[]
	 */
	private $meta_keys = array(
		'_elementor_edit_mode',
		'_elementor_template_type',
		'_elementor_version',
		'_elementor_pro_version',
		'_elementor_data',
		'_elementor_page_settings',
		'_elementor_controls_usage',
		'_elementor_css',
	);

	/**
	 * {@inheritDoc}
	 */
	public function get_slug(): string {
		return 'elementor';
	}

	/**
	 * {@inheritDoc}
	 */
	public function supports( \WP_Post $post ): bool {
		$edit_mode = get_post_meta( $post->ID, '_elementor_edit_mode', true );
		$data      = get_post_meta( $post->ID, '_elementor_data', true );

		return ( 'builder' === $edit_mode ) || ( ! empty( $data ) );
	}

	/**
	 * {@inheritDoc}
	 */
	public function export( \WP_Post $post ): array {
		$meta = array();

		foreach ( $this->meta_keys as $meta_key ) {
			$value = get_post_meta( $post->ID, $meta_key, true );

			if ( '' === $value || null === $value ) {
				continue;
			}

			$meta[ $meta_key ] = $value;
		}

		$attachment_ids = array();

		if ( ! empty( $meta['_elementor_data'] ) ) {
			$attachment_ids = Id_Remapper_Helper::extract_ids( $meta['_elementor_data'] );
			$attachment_ids = array_values(
				array_filter(
					$attachment_ids,
					static function ( $id ) {
						return $id && 'attachment' === get_post_type( $id );
					}
				)
			);
		}

		return array(
			'active'            => true,
			'meta'              => $meta,
			'attachment_ids'    => $attachment_ids,
			'elementor_active'  => defined( 'ELEMENTOR_VERSION' ),
			'elementor_version' => defined( 'ELEMENTOR_VERSION' ) ? ELEMENTOR_VERSION : '',
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function import( int $post_id, array $data, array $id_map ): void {
		if ( empty( $data['meta'] ) || ! is_array( $data['meta'] ) ) {
			return;
		}

		foreach ( $data['meta'] as $meta_key => $meta_value ) {
			if ( ! in_array( $meta_key, $this->meta_keys, true ) ) {
				continue;
			}

			$meta_value = Id_Remapper_Helper::remap( $meta_value, $id_map );

			if ( '_elementor_data' === $meta_key && is_string( $meta_value ) ) {
				$meta_value = wp_slash( $meta_value );
			}

			update_post_meta( $post_id, $meta_key, $meta_value );
		}

		// Fuerza regeneración de CSS de Elementor tras remapear IDs.
		delete_post_meta( $post_id, '_elementor_css' );

		if ( class_exists( '\Elementor\Plugin' ) ) {
			$document = \Elementor\Plugin::$instance->documents->get( $post_id );

			if ( $document ) {
				$document->save_template_type();
			}

			if ( isset( \Elementor\Plugin::$instance->files_manager ) ) {
				\Elementor\Plugin::$instance->files_manager->clear_cache();
			}
		}
	}

	/**
	 * Extrae IDs de adjuntos Elementor para el resolver de dependencias.
	 *
	 * @param int $post_id ID del post.
	 * @return int[]
	 */
	public static function collect_attachment_ids( int $post_id ): array {
		$data = get_post_meta( $post_id, '_elementor_data', true );

		if ( empty( $data ) ) {
			return array();
		}

		$ids = Id_Remapper_Helper::extract_ids( $data );

		return array_values(
			array_filter(
				$ids,
				static function ( $id ) {
					return $id && 'attachment' === get_post_type( $id );
				}
			)
		);
	}
}
