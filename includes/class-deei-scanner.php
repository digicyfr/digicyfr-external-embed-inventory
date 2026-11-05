<?php
/**
 * Bounded inspection of saved block fragments only.
 *
 * @package Digicyfr_External_Embed_Inventory
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Inspect saved block attributes and HTML without rendering. */
final class DEEI_Scanner {
	public const MAX_BYTES      = 524288;
	public const MAX_CANDIDATES = 300;
	public const MAX_BLOCKS     = 2000;
	public const MAX_DEPTH      = 32;
	public const MAX_TAGS       = 5000;

	/**
	 * Scan saved content without executing it.
	 *
	 * @param string $content Content.
	 * @return array|WP_Error Aggregated rows and coverage counters.
	 */
	public static function scan( string $content ): array|WP_Error {
		if ( strlen( $content ) > self::MAX_BYTES ) {
			return new WP_Error( 'post_too_large', 'Saved content exceeds 512 KiB.' );
		}
		if ( substr_count( $content, '<!-- wp:' ) > self::MAX_BLOCKS ) {
			return new WP_Error( 'block_count_exceeded', 'Saved content exceeds the block count limit.' );
		}
		$state = array(
			'rows'                => array(),
			'candidate_count'     => 0,
			'unresolved_relative' => 0,
			'unsupported_count'   => 0,
			'first_party_count'   => 0,
			'invalid_count'       => 0,
			'ignored_count'       => 0,
			'unclassified_count'  => 0,
			'ambiguous_count'     => 0,
			'blocks'              => 0,
			'tags'                => 0,
		);
		try {
			self::walk( parse_blocks( $content ), 0, $state );
		} catch ( RuntimeException $error ) {
			return new WP_Error( $error->getMessage(), $error->getMessage() );
		} catch ( Throwable $error ) {
			return new WP_Error( 'parser_failed', 'Saved content could not be parsed.' );
		}
		unset( $state['blocks'], $state['tags'] );
		$state['rows'] = array_values( $state['rows'] );
		return $state;
	}

	/**
	 * Walk saved blocks without duplicating child markup.
	 *
	 * @param array $blocks Blocks.
	 * @param int   $depth Depth.
	 * @param array $state State.
	 * @throws RuntimeException When a parser limit is exceeded.
	 */
	private static function walk( array $blocks, int $depth, array &$state ): void {
		if ( $depth > self::MAX_DEPTH ) {
			throw new RuntimeException( 'block_depth_exceeded' );
		}
		foreach ( $blocks as $block ) {
			++$state['blocks'];
			if ( $state['blocks'] > self::MAX_BLOCKS ) {
				throw new RuntimeException( 'block_count_exceeded' );
			}
			if ( 'core/embed' === ( $block['blockName'] ?? '' ) && is_array( $block['attrs'] ?? null ) && array_key_exists( 'url', $block['attrs'] ) ) {
				self::candidate( $block['attrs']['url'], 'core/embed', 'url', 'provider_url_hint', $state );
			}
			$html = $block['innerHTML'] ?? '';
			if ( is_string( $html ) && '' !== $html ) {
				self::html( $html, $state );
			}
			if ( ! empty( $block['innerBlocks'] ) ) {
				self::walk( $block['innerBlocks'], $depth + 1, $state );
			}
		}
	}

	/**
	 * Inspect supported HTML attributes.
	 *
	 * @param string $html Html.
	 * @param array  $state State.
	 * @throws RuntimeException When a parser limit is exceeded.
	 */
	private static function html( string $html, array &$state ): void {
		$processor = new WP_HTML_Tag_Processor( $html );
		$primary   = array(
			'IFRAME' => array( 'src' ),
			'SCRIPT' => array( 'src' ),
			'EMBED'  => array( 'src' ),
			'OBJECT' => array( 'data' ),
			'VIDEO'  => array( 'src', 'poster' ),
			'AUDIO'  => array( 'src' ),
			'SOURCE' => array( 'src' ),
			'IMG'    => array( 'src' ),
		);
		while ( $processor->next_tag() ) {
			++$state['tags'];
			if ( $state['tags'] > self::MAX_TAGS ) {
				throw new RuntimeException( 'tag_count_exceeded' );
			}
			if ( $processor->is_tag_closer() ) {
				continue;
			}
			$tag = $processor->get_tag();
			if ( ! isset( $primary[ $tag ] ) ) {
				continue;
			}
			foreach ( array( 'srcset', 'srcdoc' ) as $unsupported ) {
				if ( null !== $processor->get_attribute( $unsupported ) ) {
					++$state['unsupported_count'];
				}
			}
			foreach ( $primary[ $tag ] as $attribute ) {
				$value = $processor->get_attribute( $attribute );
				if ( null !== $value ) {
					self::candidate( $value, strtolower( $tag ), $attribute, 'saved_resource_url', $state );
				}
			}
			foreach ( array( 'data-src', 'data-lazy-src' ) as $attribute ) {
				$value = $processor->get_attribute( $attribute );
				if ( null !== $value ) {
					self::candidate( $value, strtolower( $tag ), $attribute, 'potential_lazy_url', $state );
				}
			}
		}
		if ( $processor->paused_at_incomplete_token() ) {
			throw new RuntimeException( 'incomplete_html_token' );
		}
	}

	/**
	 * Classify one saved URL candidate without retaining its path.
	 *
	 * @param mixed  $value Value.
	 * @param string $kind Kind.
	 * @param string $attribute Attribute.
	 * @param string $evidence Evidence.
	 * @param array  $state State.
	 * @throws RuntimeException When the candidate limit is exceeded.
	 */
	private static function candidate( mixed $value, string $kind, string $attribute, string $evidence, array &$state ): void {
		++$state['candidate_count'];
		if ( $state['candidate_count'] > self::MAX_CANDIDATES ) {
			throw new RuntimeException( 'candidate_count_exceeded' );
		}
		$result = DEEI_URL::classify( $value );
		switch ( $result['status'] ) {
			case 'external':
				$key = implode( "\0", array( $result['host_key'], $result['scheme'], $kind, $attribute, $evidence ) );
				if ( ! isset( $state['rows'][ $key ] ) ) {
					$state['rows'][ $key ] = array(
						'host_key'         => $result['host_key'],
						'scheme'           => $result['scheme'],
						'source_kind'      => $kind,
						'attribute_name'   => $attribute,
						'evidence'         => $evidence,
						'occurrence_count' => 0,
					);
				}
				++$state['rows'][ $key ]['occurrence_count'];
				break;
			case 'first_party':
				++$state['first_party_count'];
				break;
			case 'unresolved_relative':
				++$state['unresolved_relative'];
				break;
			case 'unclassified_host':
				++$state['unclassified_count'];
				break;
			case 'ambiguous_origin':
				++$state['ambiguous_count'];
				break;
			case 'ignored':
				++$state['ignored_count'];
				break;
			default:
				++$state['invalid_count'];
		}
	}
}
