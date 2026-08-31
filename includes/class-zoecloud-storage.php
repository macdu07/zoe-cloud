<?php
/**
 * Private local storage.
 *
 * @package ZoeCloud
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.WP.AlternativeFunctions -- Private recursive cleanup needs direct directory removal after validated path containment.

/** Resolves and protects ZoeCloud's local files. */
class ZoeCloud_Storage {
	const DIRECTORY_OPTION = 'zoecloud_storage_token';

	/** Return the durable private storage directory. */
	public function get_directory() {
		if ( defined( 'ZOECLOUD_STORAGE_PATH' ) && ZOECLOUD_STORAGE_PATH ) {
			$directory = wp_normalize_path( untrailingslashit( ZOECLOUD_STORAGE_PATH ) );
		} else {
			$token = get_option( self::DIRECTORY_OPTION, '' );
			if ( ! preg_match( '/^[a-f0-9]{32}$/', (string) $token ) ) {
				$token = bin2hex( random_bytes( 16 ) );
				update_option( self::DIRECTORY_OPTION, $token, false );
			}
			$directory = wp_normalize_path( WP_CONTENT_DIR . '/.zoecloud-private/' . $token );
		}

		wp_mkdir_p( $directory );
		$this->protect( $directory );

		return $directory;
	}

	/**
	 * Return a storage subdirectory, creating it when necessary.
	 *
	 * @param string $name Subdirectory name.
	 * @return string
	 */
	public function get_subdirectory( $name ) {
		$name = sanitize_key( $name );
		$path = $this->get_directory() . '/' . $name;
		wp_mkdir_p( $path );
		$this->protect( $path );

		return $path;
	}

	/** Create an opaque key for a ZIP archive. */
	public function create_archive_key() {
		return wp_generate_uuid4() . '-' . bin2hex( random_bytes( 8 ) ) . '.zip';
	}

	/**
	 * Resolve a relative key without allowing traversal.
	 *
	 * @param string $key          Opaque storage key.
	 * @param string $subdirectory Storage subdirectory.
	 * @return string|WP_Error
	 */
	public function resolve( $key, $subdirectory = 'backups' ) {
		$key = sanitize_file_name( basename( (string) $key ) );
		if ( '' === $key ) {
			return new WP_Error( 'zoecloud_storage_key_invalid', __( 'Storage key is invalid.', 'zoe-cloud' ) );
		}

		return trailingslashit( $this->get_subdirectory( $subdirectory ) ) . $key;
	}

	/** Test whether private storage can be written. */
	public function is_writable() {
		$directory = $this->get_directory();
		$probe     = $directory . '/.write-' . bin2hex( random_bytes( 4 ) );
		$written   = false !== file_put_contents( $probe, 'ok' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		if ( $written ) {
			wp_delete_file( $probe );
		}

		return $written;
	}

	/** Recursively remove plugin storage after explicit uninstall consent. */
	public function purge() {
		$this->remove_directory( $this->get_directory() );
	}

	/**
	 * Remove abandoned upload and workspace artifacts.
	 *
	 * @param int $max_age Maximum age in seconds.
	 * @return void
	 */
	public function cleanup_expired( $max_age = DAY_IN_SECONDS ) {
		$threshold = time() - max( HOUR_IN_SECONDS, absint( $max_age ) );
		foreach ( array( 'uploads', 'restore', 'backups' ) as $subdirectory ) {
			$directory = $this->get_subdirectory( $subdirectory );
			$items     = scandir( $directory );
			foreach ( is_array( $items ) ? $items : array() as $item ) {
				if ( '.' === $item || '..' === $item || in_array( $item, array( 'index.php', '.htaccess', 'web.config' ), true ) ) {
					continue;
				}
				$path = $directory . '/' . $item;
				if ( filemtime( $path ) >= $threshold ) {
					continue;
				}
				if ( is_dir( $path ) && ( 'restore' === $subdirectory || 0 === strpos( $item, 'tmp-' ) ) ) {
					$this->remove_directory( $path );
				} elseif ( is_file( $path ) && 'uploads' === $subdirectory ) {
					wp_delete_file( $path );
				}
			}
		}
	}

	/**
	 * Remove a temporary directory bounded to plugin storage.
	 *
	 * @param string $path Directory path.
	 * @return bool
	 */
	public function remove_directory( $path ) {
		$root = trailingslashit( wp_normalize_path( $this->get_directory() ) );
		$path = trailingslashit( wp_normalize_path( $path ) );
		if ( $path === $root || 0 !== strpos( $path, $root ) || ! is_dir( $path ) ) {
			return false;
		}

		$items = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( untrailingslashit( $path ), RecursiveDirectoryIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::CHILD_FIRST
		);
		foreach ( $items as $item ) {
			$item->isDir() ? rmdir( $item->getPathname() ) : wp_delete_file( $item->getPathname() );
		}

		return rmdir( untrailingslashit( $path ) );
	}

	/**
	 * Add web-server defence in depth.
	 *
	 * @param string $directory Directory path.
	 * @return void
	 */
	private function protect( $directory ) {
		$files = array(
			'index.php'  => "<?php\n// Silence is golden.\n",
			'.htaccess'  => "Require all denied\nDeny from all\n",
			'web.config' => '<?xml version="1.0"?><configuration><system.webServer><security><authorization><remove users="*" roles="" verbs=""/><add accessType="Deny" users="*"/></authorization></security></system.webServer></configuration>',
		);
		foreach ( $files as $name => $contents ) {
			$path = trailingslashit( $directory ) . $name;
			if ( ! file_exists( $path ) ) {
				file_put_contents( $path, $contents ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			}
		}
	}
}
