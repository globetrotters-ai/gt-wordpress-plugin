<?php
/**
 * Stand-ins for the parts of W3 Total Cache and LiteSpeed Cache that
 * PageCacheExclusion calls, with the same names and shapes. Loaded by
 * PageCacheExclusionTest only: once declared they exist for the rest of the
 * run, and no other class probes for them.
 *
 * @package Globetrotters\AiPresence\Tests
 */

declare(strict_types=1);

// phpcs:disable Generic.Files.OneObjectStructurePerFile.MultipleFound, Universal.Namespaces.DisallowCurlyBraceSyntax.Forbidden, Universal.Namespaces.OneDeclarationPerFile.MultipleFound, WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid

namespace W3TC {

	/**
	 * ``W3TC\Config``: get_array / set / save.
	 */
	final class Config {

		/**
		 * @var array<string, mixed>
		 */
		public static array $values = array();

		public static int $saves = 0;

		/**
		 * @param string $key Config key.
		 * @return array<int, mixed>
		 */
		public function get_array( string $key ): array {
			return (array) ( self::$values[ $key ] ?? array() );
		}

		/**
		 * @param string $key   Config key.
		 * @param mixed  $value Value.
		 */
		public function set( string $key, $value ): void {
			self::$values[ $key ] = $value;
		}

		public function save(): void {
			++self::$saves;
		}

		/**
		 * @param string $key Config key.
		 */
		public function get_string( string $key ): string {
			return (string) ( self::$values[ $key ] ?? '' );
		}
	}

	/**
	 * ``W3TC\Root_Environment``: the rewrite-rule fix.
	 */
	final class Root_Environment {

		public static int $fixes = 0;

		/**
		 * When set, the fix throws it (an unwritable .htaccess).
		 */
		public static ?\Throwable $fails = null;

		/**
		 * @param Config $config Config.
		 * @param bool   $force  Force.
		 */
		public function fix_in_wpadmin( $config, $force ): void {
			unset( $config, $force );
			++self::$fixes;
			if ( null !== self::$fails ) {
				throw self::$fails;
			}
		}
	}

	/**
	 * ``W3TC\Dispatcher``: the config and component locator.
	 */
	final class Dispatcher {

		public static function config(): Config {
			return new Config();
		}

		/**
		 * @param string $name Component.
		 */
		public static function component( string $name ): Root_Environment {
			unset( $name );
			return new Root_Environment();
		}
	}
}

namespace LiteSpeed {

	/**
	 * ``LiteSpeed\Conf``: cls() / conf() / update_confs().
	 */
	final class Conf {

		/**
		 * @var array<string, mixed>
		 */
		public static array $values = array();

		public static int $updates = 0;

		public static function cls(): self {
			return new self();
		}

		/**
		 * @param string $id Option id.
		 * @return mixed
		 */
		public function conf( string $id ) {
			return self::$values[ $id ] ?? array();
		}

		/**
		 * @param array<string, mixed> $the_matrix Options to save.
		 */
		public function update_confs( array $the_matrix ): void {
			++self::$updates;
			self::$values = array_merge( self::$values, $the_matrix );
		}
	}
}
