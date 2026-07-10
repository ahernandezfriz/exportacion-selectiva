<?php
/**
 * Utilidades para remapeo de IDs en estructuras anidadas.
 *
 * @package ExportacionSelectiva
 */

namespace AHF\ExportacionSelectiva;

defined( 'ABSPATH' ) || exit;

/**
 * Clase Id_Remapper_Helper.
 */
class Id_Remapper_Helper {

	/**
	 * Claves que suelen contener un ID de adjunto o post.
	 *
	 * @var string[]
	 */
	private static $id_keys = array(
		'id',
		'ID',
		'attachment_id',
		'image_id',
		'thumbnail_id',
		'post_id',
		'page_id',
	);

	/**
	 * Remapea IDs en un valor arbitrario (array, string JSON o escalar).
	 *
	 * @param mixed           $value  Valor original.
	 * @param array<int, int> $id_map Mapa old => new.
	 * @return mixed
	 */
	public static function remap( $value, array $id_map ) {
		if ( empty( $id_map ) ) {
			return $value;
		}

		if ( is_array( $value ) ) {
			return self::remap_array( $value, $id_map );
		}

		if ( is_string( $value ) ) {
			$trimmed = trim( $value );

			if ( '' === $trimmed ) {
				return $value;
			}

			if ( '{' === $trimmed[0] || '[' === $trimmed[0] ) {
				$decoded = json_decode( $value, true );

				if ( is_array( $decoded ) ) {
					$remapped = self::remap_array( $decoded, $id_map );
					$encoded  = wp_json_encode( $remapped );

					return false !== $encoded ? $encoded : $value;
				}
			}

			return self::remap_string( $value, $id_map );
		}

		if ( is_numeric( $value ) && ! is_float( $value + 0 ) ) {
			$int_value = (int) $value;

			if ( isset( $id_map[ $int_value ] ) ) {
				return is_string( $value ) ? (string) $id_map[ $int_value ] : $id_map[ $int_value ];
			}
		}

		return $value;
	}

	/**
	 * Remapea recursivamente un array.
	 *
	 * @param array<mixed>    $data   Datos.
	 * @param array<int, int> $id_map Mapa.
	 * @return array<mixed>
	 */
	public static function remap_array( array $data, array $id_map ): array {
		$remapped = array();

		foreach ( $data as $key => $value ) {
			if ( is_array( $value ) ) {
				// Galerías ACF / Elementor: listas planas de IDs.
				if ( self::is_list_of_ids( $value ) ) {
					$remapped[ $key ] = array_map(
						static function ( $id ) use ( $id_map ) {
							$id = (int) $id;
							return isset( $id_map[ $id ] ) ? $id_map[ $id ] : $id;
						},
						$value
					);
					continue;
				}

				$remapped[ $key ] = self::remap_array( $value, $id_map );
				continue;
			}

			if ( is_string( $key ) && in_array( $key, self::$id_keys, true ) && is_numeric( $value ) ) {
				$int_value = (int) $value;
				$remapped[ $key ] = isset( $id_map[ $int_value ] ) ? $id_map[ $int_value ] : $value;
				continue;
			}

			$remapped[ $key ] = self::remap( $value, $id_map );
		}

		return $remapped;
	}

	/**
	 * Remplaza referencias textuales de IDs.
	 *
	 * @param string          $value  Cadena.
	 * @param array<int, int> $id_map Mapa.
	 * @return string
	 */
	public static function remap_string( string $value, array $id_map ): string {
		foreach ( $id_map as $old_id => $new_id ) {
			$value = str_replace(
				array(
					'wp-image-' . $old_id,
					'"id":' . $old_id,
					'"id": ' . $old_id,
					'"id":"' . $old_id . '"',
					'"attachment_id":' . $old_id,
					'"attachment_id": ' . $old_id,
				),
				array(
					'wp-image-' . $new_id,
					'"id":' . $new_id,
					'"id": ' . $new_id,
					'"id":"' . $new_id . '"',
					'"attachment_id":' . $new_id,
					'"attachment_id": ' . $new_id,
				),
				$value
			);
		}

		return $value;
	}

	/**
	 * Extrae IDs numéricos candidatos de una estructura.
	 *
	 * @param mixed $value Valor a inspeccionar.
	 * @return int[]
	 */
	public static function extract_ids( $value ): array {
		$ids = array();

		if ( is_array( $value ) ) {
			foreach ( $value as $key => $item ) {
				if ( is_string( $key ) && in_array( $key, self::$id_keys, true ) && is_numeric( $item ) ) {
					$ids[] = (int) $item;
				}

				if ( is_array( $item ) ) {
					$ids = array_merge( $ids, self::extract_ids( $item ) );
				} elseif ( is_numeric( $item ) && self::is_list_of_ids( array( $item ) ) ) {
					$ids[] = (int) $item;
				} elseif ( is_string( $item ) ) {
					$ids = array_merge( $ids, self::extract_ids_from_string( $item ) );
				}
			}
		} elseif ( is_string( $value ) ) {
			$ids = array_merge( $ids, self::extract_ids_from_string( $value ) );
		} elseif ( is_numeric( $value ) ) {
			$ids[] = (int) $value;
		}

		return array_values( array_unique( array_filter( array_map( 'absint', $ids ) ) ) );
	}

	/**
	 * Extrae IDs desde una cadena JSON o serializada.
	 *
	 * @param string $value Cadena.
	 * @return int[]
	 */
	private static function extract_ids_from_string( string $value ): array {
		$ids     = array();
		$trimmed = trim( $value );

		if ( '' === $trimmed ) {
			return $ids;
		}

		if ( '{' === $trimmed[0] || '[' === $trimmed[0] ) {
			$decoded = json_decode( $value, true );

			if ( is_array( $decoded ) ) {
				return self::extract_ids( $decoded );
			}
		}

		if ( preg_match_all( '/"id"\s*:\s*"?(\d+)"?/', $value, $matches ) ) {
			$ids = array_merge( $ids, array_map( 'absint', $matches[1] ) );
		}

		return $ids;
	}

	/**
	 * Detecta arrays listados de enteros (galerías).
	 *
	 * @param array<mixed> $value Array a evaluar.
	 * @return bool
	 */
	private static function is_list_of_ids( array $value ): bool {
		if ( empty( $value ) ) {
			return false;
		}

		foreach ( $value as $key => $item ) {
			if ( ! is_int( $key ) && ! ctype_digit( (string) $key ) ) {
				return false;
			}

			if ( ! is_numeric( $item ) || (int) $item <= 0 ) {
				return false;
			}
		}

		return true;
	}
}
