<?php
/**
 * Plugin backup/restore system for Crayn AI Builder MCP.
 *
 * @package CraynAiBuilderMcp
 */

namespace CraynAiBuilderMcp;

defined( 'ABSPATH' ) || exit;

/**
 * Zips plugin folders into {uploads}/craynai-backups/plugins/ before any
 * plugin write, and restores them when a health check fails or on request.
 */
class PluginBackup {

	/**
	 * Base directory for all backups: inside the uploads directory, so the
	 * backup is never stored in (or restored from) the plugin folder itself.
	 *
	 * @return string
	 */
	public static function backup_dir() {
		$uploads = wp_get_upload_dir();
		if ( empty( $uploads['error'] ) && ! empty( $uploads['basedir'] ) ) {
			return $uploads['basedir'] . '/craynai-backups/plugins';
		}
		// Fallback for exotic setups only: keep the previous path.
		return WP_CONTENT_DIR . '/craynai-backups/plugins';
	}

	/**
	 * Initialize WP_Filesystem and return the global instance.
	 *
	 * @return \WP_Filesystem_Base|null Null when the filesystem is unavailable.
	 */
	private static function filesystem() {
		if ( ! function_exists( 'WP_Filesystem' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		if ( ! WP_Filesystem() ) {
			return null;
		}
		global $wp_filesystem;
		return $wp_filesystem ? $wp_filesystem : null;
	}

	/**
	 * Create the backup directory and protect it from direct web access.
	 *
	 * @return string Absolute path to the backup directory.
	 */
	public static function ensure_backup_dir() {
		$dir = self::backup_dir();

		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}

		$fs = self::filesystem();
		if ( null === $fs ) {
			return $dir;
		}

		// index.php: nobody may list this directory over the web.
		if ( ! file_exists( $dir . '/index.php' ) ) {
			$fs->put_contents( $dir . '/index.php', "<?php\n// Silence is golden.\n", FS_CHMOD_FILE );
		}

		// .htaccess: deny everything (Apache).
		if ( ! file_exists( $dir . '/.htaccess' ) ) {
			$rules  = "Options -Indexes\n";
			$rules .= "<IfModule mod_authz_core.c>\n\tRequire all denied\n</IfModule>\n";
			$rules .= "<IfModule !mod_authz_core.c>\n\tOrder deny,allow\n\tDeny from all\n</IfModule>\n";
			$fs->put_contents( $dir . '/.htaccess', $rules, FS_CHMOD_FILE );
		}

		return $dir;
	}

	/**
	 * Resolve the filesystem source path(s) of a plugin.
	 *
	 * @param string $plugin_file Plugin file relative to the plugins dir, e.g. "akismet/akismet.php".
	 * @return array{type: string, source: string, slug: string}|null
	 */
	public static function plugin_source( $plugin_file ) {
		$plugin_file = str_replace( array( '\\', '..' ), array( '/', '' ), (string) $plugin_file );
		$absolute    = WP_PLUGIN_DIR . '/' . ltrim( $plugin_file, '/' );

		if ( ! file_exists( $absolute ) ) {
			return null;
		}

		$last_slash = strrpos( $plugin_file, '/' );
		if ( false === $last_slash ) {
			return array(
				'type'   => 'singlefile',
				'source' => $absolute,
				'slug'   => pathinfo( $plugin_file, PATHINFO_FILENAME ),
			);
		}

		$slug = substr( $plugin_file, 0, $last_slash );
		return array(
			'type'   => 'folder',
			'source' => dirname( $absolute ),
			'slug'   => $slug,
		);
	}

	/**
	 * Recursively collect files (relative paths) of a directory.
	 *
	 * @param string $root Absolute directory path.
	 * @param string $prefix Relative prefix inside the zip.
	 * @return string[] Absolute file paths.
	 */
	private static function collect_files( $root, $prefix = '' ) {
		$files = array();
		$iter  = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $root, \FilesystemIterator::SKIP_DOTS ),
			\RecursiveIteratorIterator::LEAVES_ONLY
		);
		$root  = rtrim( $root, '/' ) . '/';
		foreach ( $iter as $file ) {
			/** @var \SplFileInfo $file */
			if ( $file->isFile() ) {
				$relative = ltrim( substr( $file->getPathname(), strlen( $root ) ), '/' );
				$files[]  = ( '' === $prefix ) ? $relative : $prefix . '/' . $relative;
			}
		}
		sort( $files );
		return $files;
	}

	/**
	 * Zip a plugin into a backup file.
	 *
	 * @param string $plugin_file Plugin file relative to the plugins dir.
	 * @return array{file: string, path: string, size: int, version: string, slug: string, created_at: string}|\WP_Error
	 */
	public static function backup( $plugin_file ) {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$source = self::plugin_source( $plugin_file );
		if ( null === $source ) {
			return new \WP_Error( 'craynai_plugin_not_found', sprintf( 'Plugin file "%s" does not exist.', $plugin_file ) );
		}

		$data    = get_plugin_data( WP_PLUGIN_DIR . '/' . ltrim( $plugin_file, '/' ), false, false );
		$version = isset( $data['Version'] ) && '' !== $data['Version'] ? $data['Version'] : 'unknown';
		$dirname = dirname( $plugin_file );
		$slug    = ( '.' === $dirname ) ? sanitize_title( $data['Name'] ) : sanitize_title( $source['slug'] );

		// The folder's web protection is written through the filesystem layer: without
		// it, a backup would sit unprotected under uploads.
		if ( null === self::filesystem() ) {
			return new \WP_Error( 'craynai_fs_unavailable', 'Could not initialize the WordPress filesystem; no backup was taken, nothing was changed.' );
		}
		$dir        = self::ensure_backup_dir();
		$created_at = gmdate( 'Y-m-d H:i:s' );
		// Random suffix: on servers that ignore .htaccess (nginx) the name must not be guessable.
		$filename   = sprintf( '%s-%s-%s-%s.zip', $slug, sanitize_file_name( $version ), gmdate( 'YmdHis' ), strtolower( wp_generate_password( 8, false ) ) );
		$zip_path   = $dir . '/' . $filename;

		// Absolute file paths with their in-zip relative paths.
		if ( 'singlefile' === $source['type'] ) {
			$in_zip_base = $source['slug'];
			$abs_files   = array( array( $source['source'], basename( $source['source'] ) ) );
		} else {
			$in_zip_base = '';
			$abs_files   = array();
			$collect     = self::collect_files( $source['source'] );
			foreach ( $collect as $rel ) {
				$abs_files[] = array( $source['source'] . '/' . $rel, $rel );
			}
		}

		if ( ! empty( $abs_files ) && class_exists( 'ZipArchive' ) ) {
			$zip = new \ZipArchive();
			$res = $zip->open( $zip_path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE );
			if ( true !== $res ) {
				return new \WP_Error( 'craynai_zip_open_failed', sprintf( 'Could not create backup zip (code %s).', $res ) );
			}
			foreach ( $abs_files as $pair ) {
				$zip->addFile( $pair[0], $pair[1] );
			}
			$zip->close();
		} else {
			// Fallback to WordPress' bundled PclZip.
			require_once ABSPATH . 'wp-admin/includes/class-pcl-zip.php';
			$archive = new \PclZip( $zip_path );
			$paths   = array_map(
				static function ( $pair ) {
					return $pair[0];
				},
				$abs_files
			);
			$result  = $archive->create( $paths, PCLZIP_OPT_REMOVE_PATH, ( 'singlefile' === $source['type'] ? dirname( $source['source'] ) : $source['source'] ) );
			if ( 0 === $result ) {
				return new \WP_Error( 'craynai_zip_create_failed', 'Could not create backup zip (PclZip): ' . $archive->errorInfo( true ) );
			}
		}

		if ( ! file_exists( $zip_path ) || empty( $abs_files ) ) {
			return new \WP_Error( 'craynai_zip_missing', 'Backup zip was not written (or the plugin has no files).' );
		}
		// Never continue an update on an incomplete backup.
		if ( class_exists( 'ZipArchive' ) ) {
			$check = new \ZipArchive();
			if ( true !== $check->open( $zip_path ) || $check->numFiles !== count( $abs_files ) ) {
				return new \WP_Error( 'craynai_zip_incomplete', 'Backup zip verification failed: file count does not match the plugin.' );
			}
			$check->close();
		}

		$meta = array(
			'plugin_file' => $plugin_file,
			'plugin_slug' => $slug,
			'version'     => $version,
			'created_at'  => $created_at,
			'active'      => is_plugin_active( $plugin_file ) || is_plugin_active_for_network( $plugin_file ),
		);
		$fs = self::filesystem();
		if ( null === $fs ) {
			return new \WP_Error( 'craynai_fs_unavailable', 'Could not initialize the WordPress filesystem (needed to store the backup metadata).' );
		}
		if ( ! $fs->put_contents( $dir . '/' . $filename . '.json', wp_json_encode( $meta, JSON_UNESCAPED_SLASHES ), FS_CHMOD_FILE ) ) {
			wp_delete_file( $zip_path );
			return new \WP_Error( 'craynai_backup_meta_failed', 'Could not store the backup metadata; the backup was discarded.' );
		}

		clearstatcache();
		return array(
			'file'       => $filename,
			'path'       => $zip_path,
			'size'       => (int) filesize( $zip_path ),
			'version'    => $version,
			'slug'       => $slug,
			'created_at' => $created_at,
		);
	}

	/**
	 * List all backups of a plugin (or all plugins) with metadata.
	 *
	 * @param string|null $plugin_file Optional filter: only this plugin's backups.
	 * @return array<int, array<string, mixed>>
	 */
	public static function list_backups( $plugin_file = null ) {
		$dir = self::backup_dir();
		if ( ! is_dir( $dir ) ) {
			return array();
		}

		$backups = array();
		foreach ( glob( $dir . '/*.zip' ) as $zip_path ) {
			$filename = basename( $zip_path );
			$meta     = array();
			if ( file_exists( $zip_path . '.json' ) ) {
				$raw   = file_get_contents( $zip_path . '.json' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
				$meta  = json_decode( (string) $raw, true );
				$meta  = is_array( $meta ) ? $meta : array();
			}

			if ( null !== $plugin_file && isset( $meta['plugin_file'] ) && $meta['plugin_file'] !== $plugin_file ) {
				continue;
			}

			clearstatcache();
			$backups[] = array(
				'file'       => $filename,
				'plugin'     => isset( $meta['plugin_file'] ) ? $meta['plugin_file'] : '',
				'slug'       => isset( $meta['plugin_slug'] ) ? $meta['plugin_slug'] : '',
				'version'    => isset( $meta['version'] ) ? $meta['version'] : '',
				'active'     => ! empty( $meta['active'] ),
				'size'       => (int) filesize( $zip_path ),
				'size_human' => size_format( (int) filesize( $zip_path ), 2 ),
				'created_at' => isset( $meta['created_at'] ) ? $meta['created_at'] : gmdate( 'Y-m-d H:i:s', (int) filemtime( $zip_path ) ),
			);
		}

		usort(
			$backups,
			static function ( $a, $b ) {
				return strcmp( $b['created_at'], $a['created_at'] );
			}
		);

		return $backups;
	}

	/**
	 * Restore plugin files from a backup zip.
	 *
	 * @param string $backup_file Zip filename (basename only) inside the backup dir.
	 * @param bool   $reactivate  Whether to (re)activate the plugin afterwards if it was active when backed up.
	 * @return array{reactivated: bool, plugin_file: string, version: string}|\WP_Error
	 */
	public static function restore( $backup_file, $reactivate = true ) {
		$backup_file = basename( (string) $backup_file );
		$zip_path    = self::backup_dir() . '/' . $backup_file;

		if ( ! file_exists( $zip_path ) ) {
			return new \WP_Error( 'craynai_backup_not_found', sprintf( 'Backup file "%s" does not exist.', $backup_file ) );
		}

		$meta = array();
		if ( file_exists( $zip_path . '.json' ) ) {
			$meta = json_decode( (string) file_get_contents( $zip_path . '.json' ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			$meta = is_array( $meta ) ? $meta : array();
		}

		if ( ! function_exists( 'WP_Filesystem' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		if ( ! function_exists( 'is_plugin_active' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		WP_Filesystem();
		global $wp_filesystem;
		if ( ! $wp_filesystem || ( defined( 'FS_METHOD' ) && 'direct' !== FS_METHOD && ! $wp_filesystem->connect() ) ) {
			return new \WP_Error( 'craynai_fs_unavailable', 'Could not initialize the WordPress filesystem (needed to restore plugin files).' );
		}

		$temp = wp_tempnam( $backup_file );
		if ( ! $temp ) {
			return new \WP_Error( 'craynai_temp_failed', 'Could not create a temporary directory for the restore.' );
		}
		if ( is_file( $temp ) ) {
			wp_delete_file( $temp );
		}
		wp_mkdir_p( $temp );
		$temp = rtrim( $temp, '/' );

		$unzipped = unzip_file( $zip_path, $temp );
		if ( is_wp_error( $unzipped ) ) {
			self::rrmdir( $temp );
			return new \WP_Error( 'craynai_unzip_failed', 'Could not unzip the backup: ' . $unzipped->get_error_message() );
		}

		$plugin_file = isset( $meta['plugin_file'] ) ? (string) $meta['plugin_file'] : '';
		$source      = '' !== $plugin_file ? self::plugin_source( $plugin_file ) : null;
		if ( null === $source ) {
			self::rrmdir( $temp );
			return new \WP_Error( 'craynai_plugin_not_found', sprintf( 'Plugin file "%s" no longer resolves; cannot restore the backup.', $plugin_file ) );
		}

		// The zip root holds the plugin folder's contents (folder plugins) or the
		// single plugin file itself (single-file plugins). The plugins dir comes
		// from the filesystem layer so it matches the active transport (direct/FTP).
		$plugins_dir = untrailingslashit( $wp_filesystem->wp_plugins_dir() );
		if ( 'singlefile' === $source['type'] ) {
			$extracted = $temp . '/' . basename( $plugin_file );
			if ( ! file_exists( $extracted ) ) {
				self::rrmdir( $temp );
				return new \WP_Error( 'craynai_unzip_empty', 'The backup zip does not contain the plugin file; nothing was changed.' );
			}
			$copied = $wp_filesystem->copy( $extracted, $plugins_dir . '/' . $plugin_file, true, FS_CHMOD_FILE );
			if ( ! $copied ) {
				self::rrmdir( $temp );
				return new \WP_Error( 'craynai_restore_copy_failed', 'Could not copy the restored plugin file into place.' );
			}
		} else {
			if ( ! file_exists( $temp . '/' . basename( $plugin_file ) ) ) {
				self::rrmdir( $temp );
				return new \WP_Error( 'craynai_unzip_empty', 'The backup zip does not contain the plugin main file; nothing was changed.' );
			}
			$dest_dir  = $plugins_dir . '/' . $source['slug'];
			$aside_dir = $dest_dir . '.craynai-old-' . time();
			// Move the current folder aside first, so a failed copy can be undone.
			if ( is_dir( $dest_dir ) && ! $wp_filesystem->move( $dest_dir, $aside_dir, true ) ) {
				self::rrmdir( $temp );
				return new \WP_Error( 'craynai_restore_move_failed', 'Could not move the current plugin folder aside; nothing was changed.' );
			}
			$wp_filesystem->mkdir( $dest_dir, FS_CHMOD_DIR );
			$copied = copy_dir( $temp, $dest_dir );
			if ( is_wp_error( $copied ) ) {
				$wp_filesystem->rmdir( $dest_dir, true );
				$wp_filesystem->move( $aside_dir, $dest_dir, true );
				self::rrmdir( $temp );
				return new \WP_Error( 'craynai_restore_copy_failed', 'Could not copy backup files into place (previous files were put back): ' . $copied->get_error_message() );
			}
			// Restored copy is in place; drop the replaced version.
			$wp_filesystem->rmdir( $aside_dir, true );
		}

		self::rrmdir( $temp );

		$was_active = ! empty( $meta['active'] );
		$reactivated = false;
		if ( $reactivate && $was_active && ! is_plugin_active( $plugin_file ) ) {
			if ( is_multisite() && is_plugin_active_for_network( $plugin_file ) ) {
				$network_wide = true;
			} else {
				$network_wide = false;
			}
			$result = activate_plugin( $plugin_file, '', $network_wide );
			if ( is_wp_error( $result ) ) {
				return new \WP_Error( 'craynai_reactivate_failed', 'Files were restored but the plugin could not be re-activated: ' . $result->get_error_message() );
			}
			$reactivated = true;
		}

		return array(
			'restored'     => true,
			'reactivated'  => $reactivated,
			'plugin_file'  => $plugin_file,
			'version'      => isset( $meta['version'] ) ? (string) $meta['version'] : '',
		);
	}

	/**
	 * Recursively delete a local temp directory (never used for content or plugins)
	 * through the WordPress filesystem layer.
	 *
	 * @param string $dir Directory path.
	 * @return void
	 */
	private static function rrmdir( $dir ) {
		if ( ! is_dir( $dir ) ) {
			return;
		}
		$fs = self::filesystem();
		if ( null === $fs ) {
			return;
		}
		$fs->delete( $dir, true );
	}
}
