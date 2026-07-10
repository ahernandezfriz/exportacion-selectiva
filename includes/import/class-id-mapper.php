<?php
/**
 * Mapea IDs antiguos a nuevos durante la importación.
 *
 * @package ExportacionSelectiva
 */

namespace AHF\ExportacionSelectiva\Import;

defined( 'ABSPATH' ) || exit;

/**
 * Clase Id_Mapper.
 */
class Id_Mapper {

	/**
	 * Mapa de IDs.
	 *
	 * @var array<int, int>
	 */
	private $map = array();

	/**
	 * Registra un mapeo.
	 *
	 * @param int $old_id ID original.
	 * @param int $new_id ID nuevo.
	 * @return void
	 */
	public function set( int $old_id, int $new_id ): void {
		if ( $old_id > 0 && $new_id > 0 ) {
			$this->map[ $old_id ] = $new_id;
		}
	}

	/**
	 * Obtiene el ID nuevo para un ID antiguo.
	 *
	 * @param int $old_id ID original.
	 * @return int
	 */
	public function get( int $old_id ): int {
		return $this->map[ $old_id ] ?? 0;
	}

	/**
	 * Devuelve el mapa completo.
	 *
	 * @return array<int, int>
	 */
	public function all(): array {
		return $this->map;
	}

	/**
	 * Hidrata el mapa desde un array serializado.
	 *
	 * @param array<int|string, int> $map Mapa.
	 * @return void
	 */
	public function hydrate( array $map ): void {
		foreach ( $map as $old_id => $new_id ) {
			$this->set( (int) $old_id, (int) $new_id );
		}
	}
}
