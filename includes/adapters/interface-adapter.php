<?php
/**
 * Interfaz para adaptadores de exportación/importación.
 *
 * @package ExportacionSelectiva
 */

namespace AHF\ExportacionSelectiva\Adapters;

defined( 'ABSPATH' ) || exit;

/**
 * Interface Adapter_Interface.
 */
interface Adapter_Interface {

	/**
	 * Slug único del adaptador.
	 *
	 * @return string
	 */
	public function get_slug(): string;

	/**
	 * Indica si el adaptador soporta el post dado.
	 *
	 * @param \WP_Post $post Post a evaluar.
	 * @return bool
	 */
	public function supports( \WP_Post $post ): bool;

	/**
	 * Exporta datos adicionales del post.
	 *
	 * @param \WP_Post $post Post a exportar.
	 * @return array<string, mixed>
	 */
	public function export( \WP_Post $post ): array;

	/**
	 * Importa datos adicionales del post.
	 *
	 * @param int                  $post_id ID del post importado.
	 * @param array<string, mixed> $data Datos del adaptador.
	 * @param array<int, int>      $id_map Mapa de IDs antiguos a nuevos.
	 * @return void
	 */
	public function import( int $post_id, array $data, array $id_map ): void;
}
