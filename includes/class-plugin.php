<?php
/**
 * Clase principal del plugin.
 *
 * @package ExportacionSelectiva
 */

namespace AHF\ExportacionSelectiva;

use AHF\ExportacionSelectiva\Admin\Admin_Notices;
use AHF\ExportacionSelectiva\Admin\Ajax_Handler;
use AHF\ExportacionSelectiva\Admin\Bulk_Export;
use AHF\ExportacionSelectiva\Admin\Export_Progress;
use AHF\ExportacionSelectiva\Admin\Import_Wizard;

defined( 'ABSPATH' ) || exit;

/**
 * Clase Plugin.
 */
final class Plugin {

	/**
	 * Instancia singleton.
	 *
	 * @var Plugin|null
	 */
	private static $instance = null;

	/**
	 * Obtiene la instancia del plugin.
	 *
	 * @return Plugin
	 */
	public static function instance(): Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Constructor privado.
	 */
	private function __construct() {
		$this->init_components();
	}

	/**
	 * Inicializa los componentes del plugin.
	 *
	 * @return void
	 */
	private function init_components(): void {
		add_filter( 'plugin_row_meta', array( $this, 'plugin_row_meta' ), 10, 2 );

		new Ajax_Handler();

		if ( is_admin() ) {
			new Bulk_Export();
			new Import_Wizard();
			new Export_Progress();
			new Admin_Notices();
		}
	}

	/**
	 * Añade enlaces de autor y soporte en la fila del plugin.
	 *
	 * @param string[] $links Enlaces meta existentes.
	 * @param string   $file  Archivo del plugin.
	 * @return string[]
	 */
	public function plugin_row_meta( array $links, string $file ): array {
		if ( plugin_basename( AHF_ES_PLUGIN_FILE ) !== $file ) {
			return $links;
		}

		$links[] = sprintf(
			'<a href="%1$s">%2$s</a>',
			esc_url( 'https://arielhf.cl' ),
			esc_html__( 'Author site', 'exportacion-selectiva' )
		);

		$links[] = sprintf(
			'<a href="%1$s">%2$s</a>',
			esc_url( 'mailto:hola@arielhf.cl' ),
			esc_html__( 'Support', 'exportacion-selectiva' )
		);

		return $links;
	}
}
