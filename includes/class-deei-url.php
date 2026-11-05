<?php
/**
 * URL classification without retaining resource paths.
 *
 * @package Digicyfr_External_Embed_Inventory
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Validate and classify one saved resource URL. */
final class DEEI_URL {
	/**
	 * Classify a saved URL. The returned value contains no path, query or fragment.
	 *
	 * @param mixed $value Raw HTML attribute or block attribute.
	 * @return array{status:string,host_key:string,scheme:string}
	 */
	public static function classify( mixed $value ): array {
		$empty = array(
			'status'   => 'invalid',
			'host_key' => '',
			'scheme'   => '',
		);
		if ( ! is_string( $value ) || '' === $value ) {
			return array(
				'status'   => 'ignored',
				'host_key' => '',
				'scheme'   => '',
			);
		}
		if ( str_starts_with( $value, '#' ) || preg_match( '/^(?:data|blob|javascript|mailto):/i', $value ) ) {
			return array(
				'status'   => 'ignored',
				'host_key' => '',
				'scheme'   => '',
			);
		}
		if ( strlen( $value ) > 2048 || preg_match( '/[\x00-\x20\x7f\\\\]/', $value ) ) {
			return $empty;
		}
		$relative = ! preg_match( '~^(https?:)?//~i', $value );
		if ( $relative ) {
			return array(
				'status'   => preg_match( '~^(?:/|\./|\.\./|[^:]+(?:/|$))~', $value ) ? 'unresolved_relative' : 'invalid',
				'host_key' => '',
				'scheme'   => '',
			);
		}
		$protocol_relative = str_starts_with( $value, '//' );
		$parse_value       = $protocol_relative ? 'https:' . $value : $value;
		$parts             = wp_parse_url( $parse_value );
		if ( ! is_array( $parts ) || empty( $parts['host'] ) || isset( $parts['user'] ) || isset( $parts['pass'] ) ) {
			return $empty;
		}
		$scheme = $protocol_relative ? 'unknown' : strtolower( (string) ( $parts['scheme'] ?? '' ) );
		if ( ! in_array( $scheme, array( 'http', 'https', 'unknown' ), true ) ) {
			return $empty;
		}
		// Require a well formed authority before trusting wp_parse_url() components.
		if ( ! preg_match( '~^(?:https?:)?//([^/?#]+)~i', $value, $authority_match ) ) {
			return $empty;
		}
		$authority = $authority_match[1];
		if ( str_contains( $authority, '@' ) || str_contains( $authority, '%' ) ) {
			return array(
				'status'   => str_contains( $authority, '%' ) ? 'unclassified_host' : 'invalid',
				'host_key' => '',
				'scheme'   => '',
			);
		}
		$raw_host = (string) $parts['host'];
		if ( preg_match( '/[^\x00-\x7f]/', $raw_host ) ) {
			return array(
				'status'   => 'unclassified_host',
				'host_key' => '',
				'scheme'   => '',
			);
		}
		$host = strtolower( $raw_host );
		if ( str_starts_with( $host, '[' ) ) {
			if ( ! str_ends_with( $host, ']' ) || ! filter_var( substr( $host, 1, -1 ), FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 ) ) {
				return $empty;
			}
		} else {
			if ( str_ends_with( $host, '.' ) ) {
				$host = substr( $host, 0, -1 );
			}
			if ( '' === $host || strlen( $host ) > 253 || str_ends_with( $host, '.' ) ) {
				return $empty;
			}
			foreach ( explode( '.', $host ) as $label ) {
				if ( strlen( $label ) > 63 || ! preg_match( '/^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$/', $label ) ) {
					return $empty;
				}
			}
			if ( preg_match( '/^[0-9.]+$/', $host ) && ! filter_var( $host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ) {
				return $empty;
			}
		}
		$port          = isset( $parts['port'] ) ? (int) $parts['port'] : 0;
		$explicit_port = ( str_starts_with( $authority, '[' ) && preg_match( '/\]:(\d+)$/', $authority ) ) || ( ! str_starts_with( $authority, '[' ) && str_contains( $authority, ':' ) );
		if ( $port < 0 || $port > 65535 || ( $explicit_port && 0 === $port ) || str_ends_with( $authority, ':' ) ) {
			return $empty;
		}
		if ( ( 'http' === $scheme && 80 === $port ) || ( 'https' === $scheme && 443 === $port ) ) {
			$port = 0;
		}
		$key       = $host . ( $port ? ':' . $port : '' );
		$site_keys = self::site_keys();
		if ( 'unknown' === $scheme && in_array( $host, array_map( array( __CLASS__, 'host_part' ), $site_keys ), true ) && in_array( $port, array( 80, 443 ), true ) ) {
			return array(
				'status'   => 'ambiguous_origin',
				'host_key' => '',
				'scheme'   => '',
			);
		}
		return array(
			'status'   => in_array( $key, $site_keys, true ) ? 'first_party' : 'external',
			'host_key' => $key,
			'scheme'   => $scheme,
		);
	}

	/**
	 * Normalized site authorities, without URL paths.
	 */
	private static function site_keys(): array {
		$keys = array();
		foreach ( array( home_url(), site_url() ) as $url ) {
			$parts = wp_parse_url( $url );
			if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
				continue;
			}
			$host = strtolower( rtrim( (string) $parts['host'], '.' ) );
			$port = (int) ( $parts['port'] ?? 0 );
			if ( ( 'http' === strtolower( (string) ( $parts['scheme'] ?? '' ) ) && 80 === $port ) || ( 'https' === strtolower( (string) ( $parts['scheme'] ?? '' ) ) && 443 === $port ) ) {
				$port = 0;
			}
			$keys[] = $host . ( $port ? ':' . $port : '' );
		}
		return array_unique( $keys );
	}

	/**
	 * Extract the host portion of a normalized host key.
	 *
	 * @param string $key Normalized host key.
	 */
	private static function host_part( string $key ): string {
		return preg_replace( '/:\d+$/', '', $key );
	}
}
