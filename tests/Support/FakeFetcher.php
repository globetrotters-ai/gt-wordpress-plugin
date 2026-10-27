<?php
/**
 * In-memory Fetcher for tests.
 *
 * @package Globetrotters\AiPresence\Tests
 */

declare(strict_types=1);

namespace Globetrotters\AiPresence\Tests\Support;

use Globetrotters\AiPresence\Client\Fetcher;
use Globetrotters\AiPresence\Client\FetchResult;

/**
 * Returns canned {@see FetchResult}s keyed by URL suffix.
 */
final class FakeFetcher implements Fetcher {

	/**
	 * @var array<string, FetchResult>
	 */
	private array $responses = array();

	private FetchResult $fallback;

	/**
	 * @var string[]
	 */
	public array $requested = array();

	public function __construct() {
		$this->fallback = FetchResult::http( 404, '' );
	}

	/**
	 * Map a canned result to any URL ending with ``$suffix``.
	 *
	 * @param string      $suffix Path suffix (e.g. ``/llms.txt``).
	 * @param FetchResult $result Result to return.
	 */
	public function on( string $suffix, FetchResult $result ): self {
		$this->responses[ $suffix ] = $result;
		return $this;
	}

	/**
	 * Set the default result for unmapped URLs.
	 *
	 * @param FetchResult $result Fallback result.
	 */
	public function fallback( FetchResult $result ): self {
		$this->fallback = $result;
		return $this;
	}

	public function fetch( string $url ): FetchResult {
		$this->requested[] = $url;
		foreach ( $this->responses as $suffix => $result ) {
			if ( substr( $url, -strlen( $suffix ) ) === $suffix ) {
				return $result;
			}
		}
		return $this->fallback;
	}
}
