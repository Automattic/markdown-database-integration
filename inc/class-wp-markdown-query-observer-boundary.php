<?php
/** Non-mutating observer boundary for authoritative database queries. */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class WP_Markdown_Query_Observer_Boundary {
	/** @var array<int,bool> */
	private static array $active = array();

	public static function observe( mixed $observer, string $query, mixed $return_value, object $database ): void {
		if ( ! is_object( $observer ) || ! method_exists( $observer, 'observe' ) ) {
			return;
		}

		$id = spl_object_id( $database );
		if ( isset( self::$active[ $id ] ) ) {
			return;
		}

		try {
			$state = self::public_state( $database );
		} catch ( Throwable $error ) {
			self::diagnostic( 'markdown_db_native_shadow_state_capture_failed', $error );
			return;
		}
		self::$active[ $id ] = true;
		try {
			$observer->observe( $query, $return_value, $database );
		} catch ( Throwable $error ) {
			self::diagnostic( 'markdown_db_native_shadow_observer_failed', $error );
		} finally {
			try {
				self::restore_public_state( $database, $state );
			} catch ( Throwable $error ) {
				self::diagnostic( 'markdown_db_native_shadow_state_restore_failed', $error );
			}
			unset( self::$active[ $id ] );
		}
	}

	/** @return array<string,mixed> */
	private static function public_state( object $database ): array {
		$state = array();
		foreach ( get_object_vars( $database ) as $name => $value ) {
			if ( self::is_public_property( $database, $name ) ) {
				$state[ $name ] = $value;
			}
		}
		return $state;
	}

	/** @param array<string,mixed> $state */
	private static function restore_public_state( object $database, array $state ): void {
		foreach ( array_keys( get_object_vars( $database ) ) as $name ) {
			if ( self::is_public_property( $database, $name ) && ! array_key_exists( $name, $state ) ) {
				unset( $database->{$name} );
			}
		}
		foreach ( $state as $name => $value ) {
			$database->{$name} = $value;
		}
	}

	private static function is_public_property( object $database, string $name ): bool {
		try {
			return ( new ReflectionProperty( $database, $name ) )->isPublic();
		} catch ( ReflectionException $error ) {
			return true;
		}
	}

	private static function diagnostic( string $code, Throwable $error ): void {
		$GLOBALS['markdown_db_native_shadow_diagnostic'] = array(
			'code'  => $code,
			'class' => get_class( $error ),
		);
	}
}
