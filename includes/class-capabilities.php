<?php
/**
 * Gestión de capabilities del plugin.
 *
 * @package ExportacionSelectiva
 */

namespace AHF\ExportacionSelectiva;

defined( 'ABSPATH' ) || exit;

/**
 * Clase Capabilities.
 */
class Capabilities {

	/**
	 * Capability para exportar contenido.
	 */
	public const EXPORT = 'ahf_es_export_content';

	/**
	 * Capability para importar contenido.
	 */
	public const IMPORT = 'ahf_es_import_content';

	/**
	 * Roles que reciben las capabilities al activar.
	 *
	 * @var string[]
	 */
	private static $roles = array( 'administrator', 'editor' );

	/**
	 * Ejecuta tareas de activación.
	 *
	 * @return void
	 */
	public static function activate(): void {
		foreach ( self::$roles as $role_slug ) {
			$role = get_role( $role_slug );

			if ( ! $role ) {
				continue;
			}

			// Limpia capabilities antiguas sin el prefijo completo.
			$role->remove_cap( 'ahf_export_content' );
			$role->remove_cap( 'ahf_import_content' );

			$role->add_cap( self::EXPORT );
			$role->add_cap( self::IMPORT );
		}
	}

	/**
	 * Ejecuta tareas de desactivación.
	 *
	 * @return void
	 */
	public static function deactivate(): void {
		foreach ( self::$roles as $role_slug ) {
			$role = get_role( $role_slug );

			if ( ! $role ) {
				continue;
			}

			$role->remove_cap( 'ahf_export_content' );
			$role->remove_cap( 'ahf_import_content' );
			$role->remove_cap( self::EXPORT );
			$role->remove_cap( self::IMPORT );
		}
	}

	/**
	 * Asegura que las capabilities estén registradas.
	 *
	 * @return void
	 */
	public static function ensure_registered(): void {
		$administrator = get_role( 'administrator' );

		if ( $administrator && ! $administrator->has_cap( self::EXPORT ) ) {
			self::activate();
		}
	}

	/**
	 * Comprueba si el usuario actual puede exportar.
	 *
	 * @return bool
	 */
	public static function current_user_can_export(): bool {
		self::ensure_registered();

		return current_user_can( self::EXPORT );
	}

	/**
	 * Comprueba si el usuario actual puede importar.
	 *
	 * @return bool
	 */
	public static function current_user_can_import(): bool {
		self::ensure_registered();

		return current_user_can( self::IMPORT );
	}
}
