<?php
/**
 * Remapeo de URLs entre sitio origen y destino.
 *
 * @package ExportacionSelectiva
 */

namespace AHF\ExportacionSelectiva;

defined( 'ABSPATH' ) || exit;

/**
 * Clase Url_Remapper.
 */
class Url_Remapper {

	/**
	 * Pares origen => destino.
	 *
	 * @var array<string, string>
	 */
	private $replacements = array();

	/**
	 * Constructor.
	 *
	 * @param string $source_url URL del sitio origen (manifest).
	 * @param string $target_url URL del sitio destino.
	 */
	public function __construct( string $source_url, string $target_url ) {
		$this->replacements = self::build_replacements( $source_url, $target_url );
	}

	/**
	 * Indica si hay algo que reemplazar.
	 *
	 * @return bool
	 */
	public function has_replacements(): bool {
		return ! empty( $this->replacements );
	}

	/**
	 * Obtiene los pares de reemplazo.
	 *
	 * @return array<string, string>
	 */
	public function get_replacements(): array {
		return $this->replacements;
	}

	/**
	 * Remapea un valor arbitrario.
	 *
	 * @param mixed $value Valor.
	 * @return mixed
	 */
	public function remap( $value ) {
		if ( ! $this->has_replacements() ) {
			return $value;
		}

		if ( is_array( $value ) ) {
			$remapped = array();

			foreach ( $value as $key => $item ) {
				$remapped[ $key ] = $this->remap( $item );
			}

			return $remapped;
		}

		if ( is_string( $value ) && '' !== $value ) {
			return $this->remap_string( $value );
		}

		return $value;
	}

	/**
	 * Remapea una cadena (texto o JSON).
	 *
	 * @param string $value Cadena.
	 * @return string
	 */
	public function remap_string( string $value ): string {
		if ( ! $this->has_replacements() ) {
			return $value;
		}

		$trimmed = trim( $value );

		if ( '' !== $trimmed && ( '{' === $trimmed[0] || '[' === $trimmed[0] ) ) {
			$decoded = json_decode( $value, true );

			if ( is_array( $decoded ) ) {
				$encoded = wp_json_encode( $this->remap( $decoded ) );

				return false !== $encoded ? $encoded : strtr( $value, $this->replacements );
			}
		}

		return strtr( $value, $this->replacements );
	}

	/**
	 * Normaliza una URL a su forma base sin slash final.
	 *
	 * @param string $url URL.
	 * @return string
	 */
	public static function normalize_base( string $url ): string {
		$url = trim( $url );

		if ( '' === $url ) {
			return '';
		}

		$parts = wp_parse_url( $url );

		if ( empty( $parts['host'] ) ) {
			return untrailingslashit( $url );
		}

		$scheme = ! empty( $parts['scheme'] ) ? $parts['scheme'] : 'https';
		$host   = strtolower( $parts['host'] );
		$port   = ! empty( $parts['port'] ) ? ':' . $parts['port'] : '';
		$path   = ! empty( $parts['path'] ) ? untrailingslashit( $parts['path'] ) : '';

		return untrailingslashit( $scheme . '://' . $host . $port . $path );
	}

	/**
	 * Compara si dos URLs apuntan al mismo sitio base.
	 *
	 * @param string $a URL A.
	 * @param string $b URL B.
	 * @return bool
	 */
	public static function is_same_site( string $a, string $b ): bool {
		$a_host = wp_parse_url( self::normalize_base( $a ), PHP_URL_HOST );
		$b_host = wp_parse_url( self::normalize_base( $b ), PHP_URL_HOST );

		if ( ! $a_host || ! $b_host ) {
			return self::normalize_base( $a ) === self::normalize_base( $b );
		}

		$a_host = preg_replace( '/^www\./i', '', (string) $a_host );
		$b_host = preg_replace( '/^www\./i', '', (string) $b_host );

		return strtolower( (string) $a_host ) === strtolower( (string) $b_host );
	}

	/**
	 * Construye pares de reemplazo (http/https, con/sin www, escaped JSON).
	 *
	 * @param string $source_url Origen.
	 * @param string $target_url Destino.
	 * @return array<string, string>
	 */
	public static function build_replacements( string $source_url, string $target_url ): array {
		$source = self::normalize_base( $source_url );
		$target = self::normalize_base( $target_url );

		if ( '' === $source || '' === $target || $source === $target ) {
			return array();
		}

		if ( self::is_same_site( $source, $target ) && untrailingslashit( $source ) === untrailingslashit( $target ) ) {
			return array();
		}

		$source_variants = self::url_variants( $source );
		$target_variants = self::url_variants( $target );
		$pairs           = array();

		// Empareja por índice cuando sea posible; si no, usa la variante canónica destino.
		$canonical_target = $target_variants[0] ?? $target;

		foreach ( $source_variants as $index => $from ) {
			$to = $target_variants[ $index ] ?? $canonical_target;

			if ( $from !== $to ) {
				$pairs[ $from ] = $to;
				// Variante escapada para JSON (\/).
				$pairs[ str_replace( '/', '\/', $from ) ] = str_replace( '/', '\/', $to );
			}
		}

		// Más largos primero para evitar reemplazos parciales incorrectos.
		uksort(
			$pairs,
			static function ( $a, $b ) {
				return strlen( (string) $b ) <=> strlen( (string) $a );
			}
		);

		return $pairs;
	}

	/**
	 * Genera variantes http/https y www.
	 *
	 * @param string $url URL base.
	 * @return string[]
	 */
	private static function url_variants( string $url ): array {
		$parts = wp_parse_url( $url );

		if ( empty( $parts['host'] ) ) {
			return array( untrailingslashit( $url ) );
		}

		$host = strtolower( $parts['host'] );
		$host_no_www = preg_replace( '/^www\./i', '', $host );
		$path = ! empty( $parts['path'] ) ? untrailingslashit( $parts['path'] ) : '';
		$port = ! empty( $parts['port'] ) ? ':' . $parts['port'] : '';

		$hosts = array_unique(
			array(
				$host_no_www,
				'www.' . $host_no_www,
			)
		);

		$variants = array();

		foreach ( array( 'https', 'http' ) as $scheme ) {
			foreach ( $hosts as $variant_host ) {
				$variants[] = untrailingslashit( $scheme . '://' . $variant_host . $port . $path );
			}
		}

		return array_values( array_unique( $variants ) );
	}
}
