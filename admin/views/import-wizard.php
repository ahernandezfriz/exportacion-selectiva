<?php
/**
 * Vista del wizard de importación.
 *
 * @package ExportacionSelectiva
 *
 * @var array<string, mixed> $view_data
 */

defined( 'ABSPATH' ) || exit;

$post_type = $view_data['post_type'];
$analysis  = $view_data['analysis'];
$result    = $view_data['result'];
$post_type_object = get_post_type_object( $post_type );
$post_type_label  = $post_type_object ? $post_type_object->labels->name : $post_type;
$list_url = add_query_arg( array( 'post_type' => $post_type ), admin_url( 'edit.php' ) );
?>
<div class="wrap ahf-es-import-wrap">
	<h1><?php esc_html_e( 'Importar contenido', 'exportacion-selectiva' ); ?></h1>

	<p class="description">
		<?php
		printf(
			/* translators: %s: post type label */
			esc_html__( 'Importa contenido selectivo al listado de %s.', 'exportacion-selectiva' ),
			esc_html( $post_type_label )
		);
		?>
	</p>

	<?php settings_errors( 'ahf_es_import' ); ?>

	<?php if ( is_array( $result ) ) : ?>
		<div class="notice notice-success">
			<p>
				<?php
				printf(
					/* translators: 1: created count, 2: updated count, 3: duplicated count, 4: skipped count */
					esc_html__( 'Importación completada. Creados: %1$d. Actualizados: %2$d. Duplicados: %3$d. Omitidos: %4$d.', 'exportacion-selectiva' ),
					(int) $result['created'],
					(int) $result['updated'],
					(int) $result['duplicated'],
					(int) $result['skipped']
				);
				?>
			</p>
		</div>
		<p>
			<a class="button button-primary" href="<?php echo esc_url( $list_url ); ?>">
				<?php esc_html_e( 'Volver al listado', 'exportacion-selectiva' ); ?>
			</a>
		</p>
	<?php elseif ( is_array( $analysis ) ) : ?>
		<div class="ahf-es-card">
			<h2><?php esc_html_e( 'Contenido detectado', 'exportacion-selectiva' ); ?></h2>

			<?php if ( ! empty( $analysis['manifest'] ) ) : ?>
				<ul class="ahf-es-manifest">
					<li>
						<strong><?php esc_html_e( 'Origen:', 'exportacion-selectiva' ); ?></strong>
						<?php echo esc_html( $analysis['manifest']['source_url'] ?? '' ); ?>
					</li>
					<li>
						<strong><?php esc_html_e( 'Exportado:', 'exportacion-selectiva' ); ?></strong>
						<?php echo esc_html( $analysis['manifest']['exported_at'] ?? '' ); ?>
					</li>
					<li>
						<strong><?php esc_html_e( 'Elementos:', 'exportacion-selectiva' ); ?></strong>
						<?php echo esc_html( (string) ( $analysis['manifest']['items_count'] ?? 0 ) ); ?>
					</li>
				</ul>
			<?php endif; ?>

			<form method="post">
				<?php wp_nonce_field( 'ahf_es_import', 'ahf_es_import_nonce' ); ?>
				<input type="hidden" name="ahf_es_session_key" value="<?php echo esc_attr( $analysis['session_key'] ); ?>" />

				<table class="widefat striped ahf-es-items-table">
					<thead>
						<tr>
							<td class="check-column">
								<input type="checkbox" class="ahf-es-check-all" checked="checked" />
							</td>
							<th><?php esc_html_e( 'Título', 'exportacion-selectiva' ); ?></th>
							<th><?php esc_html_e( 'Slug', 'exportacion-selectiva' ); ?></th>
							<th><?php esc_html_e( 'Estado', 'exportacion-selectiva' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $analysis['items'] as $item ) : ?>
							<tr>
								<th class="check-column">
									<input type="checkbox" name="ahf_es_items[]" value="<?php echo esc_attr( (string) $item['index'] ); ?>" checked="checked" />
								</th>
								<td><?php echo esc_html( $item['post_title'] ); ?></td>
								<td><code><?php echo esc_html( $item['post_name'] ); ?></code></td>
								<td>
									<span class="ahf-es-status ahf-es-status-<?php echo esc_attr( $item['status'] ); ?>">
										<?php echo esc_html( $item['message'] ); ?>
									</span>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>

				<h2><?php esc_html_e( 'Si el contenido ya existe', 'exportacion-selectiva' ); ?></h2>
				<fieldset>
					<label>
						<input type="radio" name="ahf_es_conflict_policy" value="skip" checked="checked" />
						<?php esc_html_e( 'Omitir', 'exportacion-selectiva' ); ?>
					</label><br />
					<label>
						<input type="radio" name="ahf_es_conflict_policy" value="update" />
						<?php esc_html_e( 'Actualizar', 'exportacion-selectiva' ); ?>
					</label><br />
					<label>
						<input type="radio" name="ahf_es_conflict_policy" value="duplicate" />
						<?php esc_html_e( 'Duplicar', 'exportacion-selectiva' ); ?>
					</label>
				</fieldset>

				<p class="submit">
					<button type="submit" name="ahf_es_import_submit" class="button button-primary">
						<?php esc_html_e( 'Importar selección', 'exportacion-selectiva' ); ?>
					</button>
					<a class="button" href="<?php echo esc_url( add_query_arg( array( 'page' => 'ahf-es-import', 'post_type' => $post_type ), admin_url( 'admin.php' ) ) ); ?>">
						<?php esc_html_e( 'Cancelar', 'exportacion-selectiva' ); ?>
					</a>
				</p>
			</form>
		</div>
	<?php else : ?>
		<div class="ahf-es-card">
			<form method="post" enctype="multipart/form-data">
				<?php wp_nonce_field( 'ahf_es_import_upload', 'ahf_es_upload_nonce' ); ?>

				<p>
					<label for="ahf_es_file"><strong><?php esc_html_e( 'Archivo .wpcontent', 'exportacion-selectiva' ); ?></strong></label><br />
					<input type="file" id="ahf_es_file" name="ahf_es_file" accept=".wpcontent,.zip,application/zip" required />
				</p>

				<p class="description">
					<?php esc_html_e( 'Selecciona un paquete generado por Exportación Selectiva.', 'exportacion-selectiva' ); ?>
				</p>

				<p class="submit">
					<button type="submit" name="ahf_es_analyze_submit" class="button button-primary">
						<?php esc_html_e( 'Analizar archivo', 'exportacion-selectiva' ); ?>
					</button>
					<a class="button" href="<?php echo esc_url( $list_url ); ?>">
						<?php esc_html_e( 'Volver al listado', 'exportacion-selectiva' ); ?>
					</a>
				</p>
			</form>
		</div>
	<?php endif; ?>
</div>
