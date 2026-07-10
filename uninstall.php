<?php
/**
 * Limpieza al desinstalar el plugin.
 *
 * @package ExportacionSelectiva
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

$upload_dir = wp_upload_dir();

if ( empty( $upload_dir['error'] ) ) {
	$temp_dir = trailingslashit( $upload_dir['basedir'] ) . 'exportacion-selectiva-temp';

	if ( is_dir( $temp_dir ) ) {
		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $temp_dir, FilesystemIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::CHILD_FIRST
		);

		foreach ( $iterator as $file ) {
			if ( $file->isDir() ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
				rmdir( $file->getPathname() );
			} else {
				wp_delete_file( $file->getPathname() );
			}
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
		rmdir( $temp_dir );
	}
}

$roles = array( 'administrator', 'editor' );

foreach ( $roles as $role_slug ) {
	$role = get_role( $role_slug );

	if ( ! $role ) {
		continue;
	}

	$role->remove_cap( 'ahf_export_content' );
	$role->remove_cap( 'ahf_import_content' );
	$role->remove_cap( 'ahf_es_export_content' );
	$role->remove_cap( 'ahf_es_import_content' );
}
