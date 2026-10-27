<?php
/**
 * Contract for fetching a URL.
 *
 * @package Globetrotters\AiPresence
 */

declare(strict_types=1);

namespace Globetrotters\AiPresence\Client;

/**
 * Fetches a single URL into a normalised {@see FetchResult}.
 *
 * Extracted so the sync layer can be unit-tested with a canned fetcher in
 * place of live HTTP.
 */
interface Fetcher {

	/**
	 * Largest artefact body the plugin accepts, in bytes.
	 *
	 * The bundle is persisted to the options table and (partly) read on
	 * front-end requests, so bodies must stay small. The sync layer treats a
	 * body over this limit as a fetch failure; transports should cap their
	 * reads accordingly (see GtClient).
	 */
	public const MAX_BODY_BYTES = MB_IN_BYTES;

	/**
	 * Fetch a URL.
	 *
	 * @param string $url Absolute URL to fetch.
	 */
	public function fetch( string $url ): FetchResult;
}
