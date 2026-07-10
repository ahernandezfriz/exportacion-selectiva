<?php
/**
 * Autoloader PSR-4 para el plugin.
 *
 * @package ExportacionSelectiva
 */

namespace AHF\ExportacionSelectiva;

defined( 'ABSPATH' ) || exit;

/**
 * Clase Autoloader.
 */
class Autoloader {

	/**
	 * Registra el autoloader.
	 *
	 * @return void
	 */
	public static function register(): void {
		spl_autoload_register( array( __CLASS__, 'autoload' ) );
	}

	/**
	 * Carga clases del namespace del plugin.
	 *
	 * @param string $class Nombre completo de la clase.
	 * @return void
	 */
	public static function autoload( string $class ): void {
		$prefix = __NAMESPACE__ . '\\';

		if ( 0 !== strpos( $class, $prefix ) ) {
			return;
		}

		$relative   = substr( $class, strlen( $prefix ) );
		$segments   = explode( '\\', $relative );
		$class_name = array_pop( $segments );
		$directory  = strtolower( implode( '/', $segments ) );

		if ( '_Interface' === substr( $class_name, -strlen( '_Interface' ) ) ) {
			$short_name = substr( $class_name, 0, -strlen( '_Interface' ) );
			$file_name  = 'interface-' . str_replace( '_', '-', strtolower( $short_name ) ) . '.php';
		} else {
			$file_name = 'class-' . str_replace( '_', '-', strtolower( $class_name ) ) . '.php';
		}

		if ( 'admin' === $directory ) {
			$path = AHF_ES_PLUGIN_DIR . 'admin/' . $file_name;
		} elseif ( $directory ) {
			$path = AHF_ES_PLUGIN_DIR . 'includes/' . $directory . '/' . $file_name;
		} else {
			$path = AHF_ES_PLUGIN_DIR . 'includes/' . $file_name;
		}

		if ( is_readable( $path ) ) {
			require_once $path;
		}
	}
}
