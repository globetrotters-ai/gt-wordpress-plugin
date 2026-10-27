<?php
/**
 * Pulls the apex artefacts from Globetrotters and updates the cache + state.
 *
 * @package Globetrotters\AiPresence
 */

declare(strict_types=1);

namespace Globetrotters\AiPresence\Sync;

use Globetrotters\AiPresence\Cache\ArtefactCache;
use Globetrotters\AiPresence\Client\Fetcher;
use Globetrotters\AiPresence\Serving\ContentTypes;
use Globetrotters\AiPresence\Serving\IndexNowKey;
use Globetrotters\AiPresence\Settings\Options;
use stdClass;

/**
 * Orchestrates one refresh: fetch the full file set, resolve the version
 * marker, hash the content, store the bundle, and re-derive where the
 * Globetrotters-hosted copy is published (see {@see PresenceOrigin}).
 *
 * A run only overwrites the cached bundle when *every* required file is
 * fetched successfully and every JSON artefact is a well-formed document. On
 * any required-file failure — and equally on a rejected JSON artefact, an
 * all-empty pull or a cache write that does not land — it aborts and records
 * the error, leaving the previous (stale) bundle in place to keep serving.
 */
final class ArtefactSync {

	private Fetcher $client;
	private ArtefactCache $cache;
	private Options $options;
	private ?RefreshLock $lock;

	/**
	 * Build the sync.
	 *
	 * @param Fetcher          $client  Fetches from the website URL.
	 * @param ArtefactCache    $cache   Where the bundle is stored.
	 * @param Options          $options Plugin options.
	 * @param RefreshLock|null $lock    One refresh at a time; none when null.
	 */
	public function __construct( Fetcher $client, ArtefactCache $cache, Options $options, ?RefreshLock $lock = null ) {
		$this->client  = $client;
		$this->cache   = $cache;
		$this->options = $options;
		$this->lock    = $lock;
	}

	/**
	 * Run a sync. Safe to call from cron, activation, or a manual refresh.
	 *
	 * A run that finds another one in progress stands down at once, fetching
	 * and recording nothing: the one holding the lock is already pulling the
	 * same set, and its outcome is the one the state should show.
	 */
	public function run(): SyncResult {
		if ( null !== $this->lock && ! $this->lock->acquire() ) {
			return new SyncResult(
				false,
				false,
				$this->cache->version(),
				array( __( 'A refresh is already running; this one was skipped.', 'globetrotters-ai-presence' ) ),
				true
			);
		}
		try {
			return $this->pull();
		} finally {
			if ( null !== $this->lock ) {
				$this->lock->release();
			}
		}
	}

	/**
	 * Pull, validate and store the full set, under the lock.
	 */
	private function pull(): SyncResult {
		$base_url = $this->options->base_url();
		if ( '' === $base_url ) {
			return $this->fail( array( __( 'No destination is connected yet.', 'globetrotters-ai-presence' ) ) );
		}
		// The artefacts are published at the customer's apex, homepage JSON-LD
		// included, so a cleartext pull lets anyone on the path rewrite the
		// site's presence. Refused before any fetch, and checked here rather
		// than only in the settings sanitizer: ``wp option update`` writes the
		// option without ever reaching it. Recorded as a failure, so the last
		// good bundle keeps serving and the admin notice says why.
		if ( ! Options::is_https_url( $base_url ) ) {
			return $this->fail( array( self::insecure_url_message() ) );
		}

		$files  = array();
		$errors = array();

		foreach ( $this->required_paths() as $path ) {
			$result = $this->client->fetch( $base_url . '/' . $path );
			if ( ! $result->is_ok() ) {
				$errors[] = $this->describe_failure( $path, $result->is_transport_error() ? $result->error_message() : (string) $result->status() );
				continue;
			}
			if ( strlen( $result->body() ) > Fetcher::MAX_BODY_BYTES ) {
				$errors[] = $this->describe_failure(
					$path,
					sprintf(
						/* translators: %d: maximum accepted body size in bytes. */
						__( 'response exceeds the %d-byte limit', 'globetrotters-ai-presence' ),
						Fetcher::MAX_BODY_BYTES
					)
				);
				continue;
			}
			$problem = self::unpublishable_json( $path, $result->body() );
			if ( null !== $problem ) {
				$errors[] = sprintf(
					/* translators: 1: file path, 2: why the document was refused. */
					__( 'Rejected /%1$s (%2$s).', 'globetrotters-ai-presence' ),
					$path,
					$problem
				);
				continue;
			}
			$files[ $path ] = $result->body();
		}

		// All-or-nothing: any single failure — a fetch or a rejected document —
		// aborts before anything is stored or stamped, and leaves the stale
		// bundle, its change date and its IndexNow key exactly as they were.
		if ( array() !== $errors ) {
			return $this->fail( $errors );
		}

		// An individual artefact may legitimately be empty, but a well-behaved
		// origin never serves *every* required file empty at once. That signature
		// is a broken origin or an interposed proxy answering 200 with no body, so
		// refuse the pull rather than replace the last good bundle with blanks.
		// Tested without concatenating the bodies: each is capped at 1 MB, so an
		// implode() here would transiently allocate a second copy of the whole
		// bundle on a host that may only have tens of MB to spend.
		$non_empty = array_filter( $files, static fn( string $body ): bool => '' !== $body );
		if ( array() !== $files && array() === $non_empty ) {
			return $this->fail( array( __( 'Every artefact came back empty; keeping the last good bundle.', 'globetrotters-ai-presence' ) ) );
		}

		$content_hash = $this->content_hash( $files );
		$marker       = $this->resolve_version_marker( $base_url, $content_hash );

		// A pull is several blocking fetches. If the destination was cleared or
		// changed meanwhile — a save in another request, which has already
		// forgotten the old source — storing this would put back exactly what
		// that save removed, and a disconnected install schedules no refresh to
		// ever replace it. Nothing is recorded either: the state belongs to the
		// new source now.
		if ( ! $this->options->still_connected_to( $base_url ) ) {
			return new SyncResult(
				false,
				false,
				$this->cache->version(),
				array( __( 'The destination changed while this refresh was running; its result was discarded.', 'globetrotters-ai-presence' ) )
			);
		}

		// A pull that outlived the lock's lifetime lost it to a successor, which
		// is pulling the same set now: publishing this late result could put an
		// older bundle over the successor's. Discarded without a record, like a
		// run that found the lock taken.
		if ( null !== $this->lock && ! $this->lock->still_held() ) {
			return new SyncResult(
				false,
				false,
				$this->cache->version(),
				array( __( 'This refresh ran past its lock and another one took over; its result was discarded.', 'globetrotters-ai-presence' ) ),
				true
			);
		}

		$files[ ContentTypes::VERSION_MARKER ] = $marker['body'];

		// Change detection keys off the stable content hash, not the version
		// string: when Globetrotters serves no marker the version is synthesized
		// from a timestamp (see resolve_version_marker) and would differ on every
		// run, reporting a spurious "changed" even for byte-identical content.
		$previous_hash = (string) $this->options->state()['content_hash'];

		// A write that did not land must not advance the state: reporting success
		// for a bundle the cache does not hold would make installed_version and
		// content_hash describe artefacts this site cannot serve, and the admin
		// status page would report a healthy install that is silently stale.
		if ( ! $this->cache->store( $files, $marker['version'] ) ) {
			return $this->fail( array( __( 'Failed to persist the refreshed artefacts; keeping the last good bundle.', 'globetrotters-ai-presence' ) ) );
		}

		// Re-derived on every successful pull, so a managed-hostname activation or
		// detach propagates on the normal refresh cycle with no customer action.
		// Derived regardless of profile: switching to ``subdomain_breadcrumb`` in
		// Settings then takes effect immediately instead of waiting for a refresh.
		$presence_origin = PresenceOrigin::resolve( $files['ai.json'] ?? '' );

		$state = array(
			'installed_version' => $marker['version'],
			'presence_origin'   => $presence_origin,
			// Written on every successful pull, including when the marker
			// carries no key: a key that has been rotated away upstream must
			// stop being served here rather than linger from an earlier sync.
			// A *failed* pull writes nothing, so the last known key keeps
			// serving alongside the last known good bundle.
			'indexnow_key'      => $marker['indexnow_key'],
			// After a successful pull the installed bundle *is* the latest we
			// know of, so keep them in lockstep. This also makes the drift
			// notice work from cron (which never runs check_latest()): it only
			// diverges when a later check finds a newer upstream marker.
			'latest_version'    => $marker['version'],
			'content_hash'      => $content_hash,
			'last_refresh'      => time(),
			'last_error'        => '',
		);

		// Only stamped when the content actually moved. update_state() merges,
		// so an unchanged republish leaves the previous stamp standing rather
		// than advancing it — which is what keeps the generated sitemap's
		// <lastmod> a statement about the content and not about the cron.
		$changed = $previous_hash !== $content_hash;
		if ( $changed ) {
			$state['content_changed_at'] = time();
		}

		$this->options->update_state( $state );

		return new SyncResult( true, $changed, $marker['version'], array() );
	}

	/**
	 * Forget the current source: drop its bundle and everything learned from it.
	 *
	 * Stale-serve keeps the last good bundle when the *same* source fails. A
	 * bundle from a source the site no longer points at is not a fallback —
	 * serving it publishes a presence the customer withdrew, or another
	 * destination's — so a disconnect or a new destination clears it at once.
	 * The apex falls through to WordPress until a pull from the new source
	 * lands, which the settings save has already queued.
	 */
	public function forget(): void {
		$this->cache->clear();
		$this->options->reset_state();
	}

	/**
	 * ``update_option_gtaip_settings`` callback: forget the source when the
	 * website URL was cleared or changed.
	 *
	 * Compared normalized, so a trailing slash, or a re-save that only changes
	 * other settings, is not a new source.
	 *
	 * @param mixed $old_value Settings before the update.
	 * @param mixed $value     Settings after it.
	 */
	public function forget_previous_source( $old_value, $value ): void {
		if ( self::website_url_of( $old_value ) !== self::website_url_of( $value ) ) {
			$this->forget();
		}
	}

	/**
	 * The normalized website URL in a raw settings value, or ''.
	 *
	 * @param mixed $settings Raw ``gtaip_settings`` value.
	 */
	private static function website_url_of( $settings ): string {
		return is_array( $settings ) && isset( $settings['website_url'] ) && is_string( $settings['website_url'] )
			? Options::normalize_url( $settings['website_url'] )
			: '';
	}

	/**
	 * Look up the latest version Globetrotters advertises, without pulling.
	 *
	 * Reads the upstream drift marker when one is served; returns an empty
	 * string when it isn't (in which case drift can't be detected ahead of a
	 * pull, but every refresh still self-heals it).
	 */
	public function check_latest(): string {
		$base_url = $this->options->base_url();
		if ( ! Options::is_https_url( $base_url ) ) {
			return '';
		}
		$result = $this->client->fetch( $base_url . '/' . ContentTypes::VERSION_MARKER );
		// An oversize body is truncated by the transport (see GtClient); treat it
		// like a missing marker rather than parsing a corrupt fragment.
		if ( ! $result->is_ok() || strlen( $result->body() ) > Fetcher::MAX_BODY_BYTES ) {
			return '';
		}
		$decoded = json_decode( $result->body(), true );
		return is_array( $decoded ) && isset( $decoded['version'] ) ? (string) $decoded['version'] : '';
	}

	/**
	 * Required (non-marker) apex paths.
	 *
	 * @return string[]
	 */
	private function required_paths(): array {
		return array_values(
			array_filter(
				ContentTypes::paths(),
				static fn( string $path ): bool => ContentTypes::VERSION_MARKER !== $path
			)
		);
	}

	/**
	 * Resolve the drift marker: use Globetrotters' copy when it serves one,
	 * otherwise synthesize one from the freshly hashed content.
	 *
	 * ``indexnow_key`` is this environment's IndexNow key when the marker
	 * carries one. It rides on the marker rather than being a fetched artefact
	 * of its own: a required path that 404s in every keyless environment would
	 * fail the whole sync there (see ``run()``), and a bundle *file* would have
	 * to be excluded from ``content_hash`` in three byte-matched
	 * implementations. The marker is already both synced and outside the hash.
	 *
	 * @param string $base_url     Source base URL.
	 * @param string $content_hash Hash over the required files.
	 * @return array{body: string, version: string, indexnow_key: string}
	 */
	private function resolve_version_marker( string $base_url, string $content_hash ): array {
		$result = $this->client->fetch( $base_url . '/' . ContentTypes::VERSION_MARKER );
		// Ignore an oversize (transport-truncated) marker and synthesize instead,
		// mirroring the required-files size guard in run().
		if ( $result->is_ok() && strlen( $result->body() ) <= Fetcher::MAX_BODY_BYTES ) {
			$decoded = json_decode( $result->body(), true );
			if ( is_array( $decoded ) && isset( $decoded['version'] ) ) {
				return array(
					'body'         => $result->body(),
					'version'      => (string) $decoded['version'],
					// Untrusted like every other byte from this origin, and the
					// value decides which path the router answers: anything
					// outside IndexNow's own key grammar reads as no key at all.
					'indexnow_key' => IndexNowKey::sanitize( $decoded['indexnowKey'] ?? '' ),
				);
			}
		}

		$version = gmdate( 'Y-m-d-His' );
		$body    = (string) wp_json_encode(
			array(
				'generator'       => 'globetrotters-apex-wp-plugin',
				'destinationSlug' => $this->options->slug(),
				'version'         => $version,
				'contentHash'     => $content_hash,
				'builtAt'         => gmdate( 'c' ),
				'source'          => 'synthesized',
			)
		);

		return array(
			'body'         => $body,
			'version'      => $version,
			// A synthesized marker is what an origin serving no marker produces,
			// so there is nothing to learn a key from.
			'indexnow_key' => '',
		);
	}

	/**
	 * Why a fetched JSON artefact cannot be published, or null when it can.
	 *
	 * Status and size only prove that *something* answered. A maintenance page,
	 * a login wall or a proxy error served with a 200 passes both, and publishing
	 * it would replace a working discovery document with HTML. So every JSON
	 * artefact has to parse, and to parse as the kind of document it is: an
	 * object, or for JSON-LD also a non-empty list of node objects.
	 *
	 * Deliberately no deeper than that. The document schemas are Globetrotters'
	 * to evolve, and a field-level check here would start rejecting legitimate
	 * publications the day a field moved. Text artefacts (``llms.txt``) are
	 * Markdown with no grammar to hold them to, and pass through untouched.
	 * Mirrors the Symfony bundle's ``ArtefactSync::unpublishableJson``.
	 *
	 * @param string $path Apex-relative path.
	 * @param string $body Fetched body.
	 */
	private static function unpublishable_json( string $path, string $body ): ?string {
		$type       = (string) ContentTypes::for_path( $path );
		$is_json_ld = 0 === strpos( $type, 'application/ld+json' );
		if ( ! $is_json_ld && 0 !== strpos( $type, 'application/json' ) ) {
			return null;
		}

		if ( '' === trim( $body ) ) {
			return __( 'empty body where a JSON document was expected', 'globetrotters-ai-presence' );
		}
		$decoded = json_decode( $body );
		// Read before anything else runs: __() below can reach gettext filters,
		// and a JSON call in one would reset the error this message reports.
		$json_error = JSON_ERROR_NONE === json_last_error() ? '' : json_last_error_msg();
		if ( '' !== $json_error ) {
			/* translators: %s: the JSON parser's error message. */
			return sprintf( __( 'not valid JSON: %s', 'globetrotters-ai-presence' ), $json_error );
		}

		if ( $decoded instanceof stdClass ) {
			return null;
		}
		if ( $is_json_ld && is_array( $decoded ) && array() !== $decoded
			&& array() === array_filter( $decoded, static fn( $node ): bool => ! $node instanceof stdClass ) ) {
			return null;
		}

		return $is_json_ld
			? __( 'not a JSON-LD object or list of node objects', 'globetrotters-ai-presence' )
			: __( 'not a JSON object', 'globetrotters-ai-presence' );
	}

	/**
	 * Canonical content hash over the required files.
	 *
	 * Mirrors the backend ``canonical_hash``: sha256 over sorted
	 * ``path \0 sha256(body) \0`` triples, excluding the version marker.
	 *
	 * @param array<string, string> $files Apex-relative path → body.
	 */
	private function content_hash( array $files ): string {
		ksort( $files, SORT_STRING );
		$parts = '';
		foreach ( $files as $path => $body ) {
			// Raw (binary) inner digest to match the backend's
			// hashlib.sha256(body).digest(); a hex digest here would never
			// match upstream. SORT_STRING mirrors Python's sorted().
			$parts .= $path . "\0" . hash( 'sha256', $body, true ) . "\0";
		}
		return hash( 'sha256', $parts );
	}

	/**
	 * Why a website URL that is not https is refused.
	 */
	public static function insecure_url_message(): string {
		return __( 'The Globetrotters website URL must be an https:// URL; nothing was fetched.', 'globetrotters-ai-presence' );
	}

	/**
	 * Record a failed run in state and return the result.
	 *
	 * @param string[] $errors Error messages.
	 */
	private function fail( array $errors ): SyncResult {
		$this->options->update_state( array( 'last_error' => implode( '; ', $errors ) ) );
		return new SyncResult( false, false, $this->cache->version(), $errors );
	}

	/**
	 * Human-readable failure line for a single path.
	 *
	 * @param string $path   Apex-relative path.
	 * @param string $reason Transport message or HTTP status.
	 */
	private function describe_failure( string $path, string $reason ): string {
		/* translators: 1: file path, 2: HTTP status or transport error. */
		return sprintf( __( 'Failed to fetch /%1$s (%2$s).', 'globetrotters-ai-presence' ), $path, $reason );
	}
}
