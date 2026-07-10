<?php
/**
 * Resuelve conflictos al importar contenido.
 *
 * @package ExportacionSelectiva
 */

namespace AHF\ExportacionSelectiva\Import;

defined( 'ABSPATH' ) || exit;

/**
 * Clase Conflict_Resolver.
 */
class Conflict_Resolver {

	/**
	 * Políticas de conflicto soportadas.
	 */
	public const POLICY_SKIP      = 'skip';
	public const POLICY_UPDATE    = 'update';
	public const POLICY_DUPLICATE = 'duplicate';

	/**
	 * Detecta si existe un conflicto para un item importado.
	 *
	 * @param array<string, mixed> $item Datos del item.
	 * @return array{
	 *     status: string,
	 *     existing_id: int,
	 *     message: string
	 * }
	 */
	public function detect( array $item ): array {
		$existing = get_posts(
			array(
				'name'                   => $item['post_name'],
				'post_type'              => $item['post_type'],
				'post_status'            => 'any',
				'numberposts'            => 1,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			)
		);

		$existing = ! empty( $existing ) ? $existing[0] : null;

		if ( ! $existing ) {
			$existing_posts = get_posts(
				array(
					'post_type'              => $item['post_type'],
					'title'                  => $item['post_title'],
					'post_status'            => 'any',
					'numberposts'            => 1,
					'update_post_meta_cache' => false,
					'update_post_term_cache' => false,
				)
			);

			$existing = ! empty( $existing_posts ) ? $existing_posts[0] : null;
		}

		if ( ! $existing ) {
			return array(
				'status'      => 'new',
				'existing_id' => 0,
				'message'     => __( 'Nuevo', 'exportacion-selectiva' ),
			);
		}

		return array(
			'status'      => 'exists',
			'existing_id' => (int) $existing->ID,
			'message'     => __( 'Ya existe', 'exportacion-selectiva' ),
		);
	}

	/**
	 * Valida una política de conflicto.
	 *
	 * @param string $policy Política solicitada.
	 * @return string
	 */
	public function sanitize_policy( string $policy ): string {
		$allowed = array(
			self::POLICY_SKIP,
			self::POLICY_UPDATE,
			self::POLICY_DUPLICATE,
		);

		return in_array( $policy, $allowed, true ) ? $policy : self::POLICY_SKIP;
	}
}
