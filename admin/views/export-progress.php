<?php
/**
 * Vista de progreso de exportación.
 *
 * @package ExportacionSelectiva
 *
 * @var array<string, mixed>|\WP_Error|null $job
 * @var string $job_id
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="wrap ahf-es-import-wrap">
	<h1><?php esc_html_e( 'Exportando contenido', 'exportacion-selectiva' ); ?></h1>

	<?php if ( is_wp_error( $job ) || ! $job_id ) : ?>
		<div class="notice notice-error">
			<p><?php esc_html_e( 'No se encontró el trabajo de exportación.', 'exportacion-selectiva' ); ?></p>
		</div>
	<?php else : ?>
		<div class="ahf-es-card" id="ahf-es-progress-card" data-job-id="<?php echo esc_attr( $job_id ); ?>">
			<p class="ahf-es-progress-status"><?php esc_html_e( 'Preparando exportación…', 'exportacion-selectiva' ); ?></p>
			<div class="ahf-es-progress-bar" aria-hidden="true">
				<span class="ahf-es-progress-bar-fill" style="width:0%"></span>
			</div>
			<p class="ahf-es-progress-meta"><span class="ahf-es-progress-percent">0%</span></p>
			<p class="ahf-es-progress-actions" hidden>
				<a class="button button-primary ahf-es-download-link" href="#"><?php esc_html_e( 'Descargar archivo', 'exportacion-selectiva' ); ?></a>
			</p>
		</div>
	<?php endif; ?>
</div>
