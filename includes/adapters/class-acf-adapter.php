<?php
/**
 * Adaptador para campos ACF.
 *
 * @package ExportacionSelectiva
 */

namespace AHF\ExportacionSelectiva\Adapters;

use AHF\ExportacionSelectiva\Id_Remapper_Helper;

defined( 'ABSPATH' ) || exit;

/**
 * Clase Acf_Adapter.
 */
class Acf_Adapter implements Adapter_Interface {

	/**
	 * Tipos de campo ACF que referencian IDs.
	 *
	 * @var string[]
	 */
	private $id_field_types = array(
		'image',
		'file',
		'gallery',
		'post_object',
		'relationship',
		'page_link',
	);

	/**
	 * {@inheritDoc}
	 */
	public function get_slug(): string {
		return 'acf';
	}

	/**
	 * {@inheritDoc}
	 */
	public function supports( \WP_Post $post ): bool {
		if ( ! function_exists( 'get_field_objects' ) ) {
			return $this->has_acf_meta_keys( $post->ID );
		}

		$fields = get_field_objects( $post->ID );

		return ! empty( $fields );
	}

	/**
	 * {@inheritDoc}
	 */
	public function export( \WP_Post $post ): array {
		$fields         = array();
		$attachment_ids = array();

		if ( function_exists( 'get_field_objects' ) ) {
			$objects = get_field_objects( $post->ID );

			if ( is_array( $objects ) ) {
				foreach ( $objects as $field ) {
					if ( empty( $field['name'] ) ) {
						continue;
					}

					$fields[ $field['name'] ] = array(
						'key'   => $field['key'] ?? '',
						'type'  => $field['type'] ?? '',
						'value' => $field['value'] ?? null,
					);

					$attachment_ids = array_merge(
						$attachment_ids,
						$this->extract_attachment_ids_from_field( $field )
					);
				}
			}
		}

		return array(
			'active'         => function_exists( 'get_field_objects' ),
			'fields'         => $fields,
			'attachment_ids' => array_values( array_unique( array_map( 'absint', $attachment_ids ) ) ),
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function import( int $post_id, array $data, array $id_map ): void {
		if ( empty( $data['fields'] ) || ! is_array( $data['fields'] ) ) {
			// Sin definición de campos: remapea metas ACF ya importadas.
			$this->remap_existing_acf_meta( $post_id, $id_map );
			return;
		}

		foreach ( $data['fields'] as $field_name => $field_data ) {
			if ( ! is_array( $field_data ) ) {
				continue;
			}

			$value = Id_Remapper_Helper::remap( $field_data['value'] ?? null, $id_map );
			$type  = $field_data['type'] ?? '';

			if ( in_array( $type, array( 'image', 'file' ), true ) ) {
				$value = $this->normalize_single_id_value( $value, $id_map );
			}

			if ( in_array( $type, array( 'gallery', 'relationship', 'post_object' ), true ) ) {
				$value = $this->normalize_id_list_value( $value, $id_map );
			}

			if ( function_exists( 'update_field' ) && ! empty( $field_data['key'] ) ) {
				update_field( $field_data['key'], $value, $post_id );
				continue;
			}

			update_post_meta( $post_id, $field_name, $value );

			if ( ! empty( $field_data['key'] ) ) {
				update_post_meta( $post_id, '_' . $field_name, $field_data['key'] );
			}
		}
	}

	/**
	 * Extrae adjuntos ACF para el resolver de dependencias.
	 *
	 * @param int $post_id ID del post.
	 * @return int[]
	 */
	public static function collect_attachment_ids( int $post_id ): array {
		$adapter = new self();
		$ids     = array();

		if ( function_exists( 'get_field_objects' ) ) {
			$objects = get_field_objects( $post_id );

			if ( is_array( $objects ) ) {
				foreach ( $objects as $field ) {
					$ids = array_merge( $ids, $adapter->extract_attachment_ids_from_field( $field ) );
				}
			}
		}

		return array_values( array_unique( array_map( 'absint', $ids ) ) );
	}

	/**
	 * Detecta metas con referencia ACF sin API disponible.
	 *
	 * @param int $post_id ID del post.
	 * @return bool
	 */
	private function has_acf_meta_keys( int $post_id ): bool {
		$meta = get_post_meta( $post_id );

		foreach ( array_keys( $meta ) as $key ) {
			if ( 0 === strpos( (string) $key, '_' ) ) {
				$ref = get_post_meta( $post_id, $key, true );

				if ( is_string( $ref ) && 0 === strpos( $ref, 'field_' ) ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * Extrae IDs de adjunto desde un campo ACF.
	 *
	 * @param array<string, mixed> $field Campo ACF.
	 * @return int[]
	 */
	private function extract_attachment_ids_from_field( array $field ): array {
		$type  = $field['type'] ?? '';
		$value = $field['value'] ?? null;
		$ids   = array();

		if ( ! in_array( $type, array( 'image', 'file', 'gallery' ), true ) ) {
			return $ids;
		}

		if ( 'gallery' === $type && is_array( $value ) ) {
			foreach ( $value as $item ) {
				$id = $this->extract_id_from_acf_value( $item );

				if ( $id && 'attachment' === get_post_type( $id ) ) {
					$ids[] = $id;
				}
			}

			return $ids;
		}

		$id = $this->extract_id_from_acf_value( $value );

		if ( $id && 'attachment' === get_post_type( $id ) ) {
			$ids[] = $id;
		}

		return $ids;
	}

	/**
	 * Obtiene un ID desde un valor ACF (int, array o URL).
	 *
	 * @param mixed $value Valor del campo.
	 * @return int
	 */
	private function extract_id_from_acf_value( $value ): int {
		if ( is_numeric( $value ) ) {
			return (int) $value;
		}

		if ( is_array( $value ) && isset( $value['ID'] ) ) {
			return (int) $value['ID'];
		}

		if ( is_array( $value ) && isset( $value['id'] ) ) {
			return (int) $value['id'];
		}

		return 0;
	}

	/**
	 * Normaliza un valor de ID único tras el remapeo.
	 *
	 * @param mixed           $value  Valor.
	 * @param array<int, int> $id_map Mapa.
	 * @return mixed
	 */
	private function normalize_single_id_value( $value, array $id_map ) {
		$id = $this->extract_id_from_acf_value( $value );

		if ( ! $id ) {
			return $value;
		}

		return isset( $id_map[ $id ] ) ? $id_map[ $id ] : $id;
	}

	/**
	 * Normaliza listas de IDs tras el remapeo.
	 *
	 * @param mixed           $value  Valor.
	 * @param array<int, int> $id_map Mapa.
	 * @return mixed
	 */
	private function normalize_id_list_value( $value, array $id_map ) {
		if ( ! is_array( $value ) ) {
			return $this->normalize_single_id_value( $value, $id_map );
		}

		$normalized = array();

		foreach ( $value as $item ) {
			$id = $this->extract_id_from_acf_value( $item );
			$normalized[] = ( $id && isset( $id_map[ $id ] ) ) ? $id_map[ $id ] : ( $id ? $id : $item );
		}

		return $normalized;
	}

	/**
	 * Remapea metas ACF ya presentes en el post.
	 *
	 * @param int             $post_id ID del post.
	 * @param array<int, int> $id_map  Mapa.
	 * @return void
	 */
	private function remap_existing_acf_meta( int $post_id, array $id_map ): void {
		$all_meta = get_post_meta( $post_id );

		foreach ( $all_meta as $meta_key => $values ) {
			// Las claves de referencia ACF empiezan por "_".
			if ( 0 === strpos( (string) $meta_key, '_' ) ) {
				continue;
			}

			$field_key = get_post_meta( $post_id, '_' . $meta_key, true );

			if ( ! is_string( $field_key ) || 0 !== strpos( $field_key, 'field_' ) ) {
				continue;
			}

			$current  = maybe_unserialize( $values[0] ?? '' );
			$remapped = Id_Remapper_Helper::remap( $current, $id_map );

			if ( $remapped !== $current ) {
				update_post_meta( $post_id, $meta_key, $remapped );
			}
		}
	}
}
