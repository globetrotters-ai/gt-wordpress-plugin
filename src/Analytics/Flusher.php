<?php
/**
 * Flushes buffered events to the ingest endpoint.
 *
 * @package Globetrotters\AiPresence
 */

declare(strict_types=1);

namespace Globetrotters\AiPresence\Analytics;

use Globetrotters\AiPresence\Settings\Options;
use Throwable;

/**
 * One flush: claim the oldest events, fit them under the wire caps, POST, and
 * only then drop them from the buffer.
 *
 * Two properties matter more than anything else here:
 *
 * **Retry must not regenerate IDs.** A flush that times out may well have been
 * received. Rows are deleted only after a 2xx, so the next attempt re-claims the
 * same rows and therefore re-sends the same UUIDs — and the backend dedupes on
 * ``id``, so a retry cannot double-count. UUIDs are minted once, in
 * {@see EventRecorder}, and never here.
 *
 * Page-view counters ride in the same envelope under ``pageViews``, with the
 * same property. A counter is sent only once its UTC day has closed, and closing
 * it assigns a record id exactly once ({@see PageViewStore::seal()}); a retry
 * re-sends that id, and the counter is deleted only after a 2xx. Days the
 * backend would refuse as too old are pruned instead of sent.
 *
 * **Failure is quiet.** Any non-2xx or timeout means "retry later" and nothing
 * else; ``429`` additionally means back off and re-send the *same* payload. A
 * flush never affects a served response, and never surfaces an error to a
 * visitor.
 *
 * Note that ``202`` is not confirmation the data was good: the endpoint answers
 * it for a bad token, an unknown install and a malformed body too. All this
 * class can honestly record is that a batch was accepted.
 */
final class Flusher {

	/**
	 * Cron-collision lock, held in an option rather than a transient so it works
	 * on a self-hosted site with no persistent object cache.
	 *
	 * ``add_option`` refuses to overwrite an existing option, which is enough to
	 * stop two overlapping cron runs doing the same work twice — but it is a
	 * check-then-insert, not a true compare-and-swap, so a genuinely simultaneous
	 * pair could both take it. That costs a duplicate POST and nothing else: the
	 * backend dedupes on event ``id``, so it cannot double-count.
	 */
	public const LOCK_OPTION = 'gtaip_flush_lock';

	/**
	 * Age at which a held lock is assumed abandoned (a fatal between acquire and
	 * release). Comfortably longer than the transport timeout, comfortably
	 * shorter than the 15-minute flush interval.
	 */
	private const LOCK_TTL_SECONDS = 300;

	/**
	 * Batches one run will send before giving the buffer back to the schedule.
	 *
	 * A backlog bigger than one batch must not take an hour to drain: hits older
	 * than 90 minutes when the flush lands are re-stamped to arrival time, which
	 * silently mis-buckets them. Five batches covers the entire buffer bound in a
	 * single run and stays two orders of magnitude under the 60-batches-per-minute
	 * per-org ceiling.
	 */
	private const MAX_BATCHES_PER_RUN = 5;

	private EventBuffer $buffer;
	private IngestTransport $transport;
	private Options $options;
	private ?PageViewStore $page_views;
	private ?CaptureContext $context;

	/**
	 * This run's ``captureContext`` block, snapshotted once per run so every
	 * batch of the run (and a retry of it) carries the same one.
	 *
	 * @var array<string, mixed>|null
	 */
	private ?array $context_block = null;

	/**
	 * Whether the last {@see flush_once()} found nothing to send, as opposed to
	 * a rejected or unfit batch.
	 *
	 * @var bool
	 */
	private bool $found_nothing = false;

	public function __construct(
		EventBuffer $buffer,
		IngestTransport $transport,
		Options $options,
		?PageViewStore $page_views = null,
		?CaptureContext $context = null
	) {
		$this->buffer     = $buffer;
		$this->transport  = $transport;
		$this->options    = $options;
		$this->page_views = $page_views;
		$this->context    = $context;
	}

	/**
	 * Run one flush. Returns true when at least one batch was accepted.
	 *
	 * Drains up to {@see MAX_BATCHES_PER_RUN} batches, stopping at the first
	 * rejection — a failing endpoint gets one attempt per run, not five.
	 */
	public function run(): bool {
		if ( ! $this->options->is_reporting_configured() || ! Schema::is_ready() ) {
			return false;
		}
		if ( ! $this->acquire_lock() ) {
			return false;
		}

		try {
			$this->close_page_view_days();
			$this->context_block = null === $this->context ? null : $this->context->snapshot();

			$sent = false;
			for ( $batch = 0; $batch < self::MAX_BATCHES_PER_RUN; $batch++ ) {
				if ( ! $this->flush_once() ) {
					break;
				}
				$sent = true;
				if ( 0 === $this->buffer->count() && 0 === $this->pending_page_views() ) {
					break;
				}
			}

			// captureContext goes with every flush (contract section 1). With
			// nothing else to send, one context-only envelope per run carries it,
			// so the backend learns the cache topology of a quiet install too.
			if ( ! $sent && $this->found_nothing && null !== $this->context_block ) {
				$sent = $this->flush_context_only();
			}
			if ( $sent && null !== $this->context && null !== $this->context_block ) {
				$this->context->clear_markers( $this->context_block['cdnMarkers'] );
			}
			return $sent;
		} catch ( Throwable $error ) {
			// Same rule as capture: reporting degrades, the site does not.
			$this->options->update_flush_state(
				array(
					'last_flush_attempt' => time(),
					'last_flush_error'   => $error->getMessage(),
				)
			);
			return false;
		} finally {
			$this->release_lock();
		}
	}

	/**
	 * Claim, send, and settle one batch.
	 */
	private function flush_once(): bool {
		$this->buffer->prune();

		$claimed             = $this->buffer->claim( IngestTransport::MAX_EVENTS_PER_BATCH );
		$store               = $this->page_view_store();
		$views               = null === $store ? array() : $store->sealed( IngestTransport::MAX_PAGE_VIEWS_PER_BATCH );
		$this->found_nothing = array() === $claimed && array() === $views;
		if ( $this->found_nothing ) {
			$this->options->update_flush_state( array( 'last_flush_attempt' => time() ) );
			return false;
		}

		$dropped = $this->buffer->dropped_pending();
		$batch   = $this->fit( $claimed, $views, $dropped );

		if ( array() === $batch['claimed'] && array() === $batch['views'] ) {
			// Nothing fits, not even one record. Drop the head rather than wedge
			// everything behind it — an event is counted as dropped, so the gap
			// stays measured. A page-view record cannot realistically get here
			// (its path is capped and valid UTF-8), but the same rule holds.
			if ( array() !== $claimed ) {
				$this->buffer->discard( array( $claimed[0]['id'] ) );
			} elseif ( null !== $store ) {
				$store->delete( array( $views[0]['id'] ) );
			}
			return false;
		}

		$result = $this->transport->post(
			$this->options->ingest_endpoint(),
			$this->options->ingest_token(),
			$batch['json']
		);

		$this->record_attempt( $result );

		if ( ! $result->is_accepted() ) {
			// Leave every claimed row in place: the next attempt re-sends this exact
			// payload, same UUIDs, and the backend dedupes it.
			return false;
		}

		$this->buffer->release( array_column( $batch['claimed'], 'id' ) );
		if ( null !== $store && array() !== $batch['views'] ) {
			$store->delete( array_column( $batch['views'], 'id' ) );
		}
		$this->buffer->clear_dropped( $dropped );

		return true;
	}

	/**
	 * Send an envelope carrying only ``captureContext`` (and any pending drop
	 * count). Returns true when it was accepted.
	 */
	private function flush_context_only(): bool {
		$dropped = $this->buffer->dropped_pending();
		$json    = $this->encode( array(), array(), $dropped );
		if ( ! $this->fits( $json ) ) {
			return false;
		}

		$result = $this->transport->post(
			$this->options->ingest_endpoint(),
			$this->options->ingest_token(),
			$json
		);
		$this->record_attempt( $result );
		if ( ! $result->is_accepted() ) {
			return false;
		}
		$this->buffer->clear_dropped( $dropped );
		return true;
	}

	/**
	 * Seal the counters of every closed UTC day, after dropping the days the
	 * backend would refuse.
	 *
	 * Pruned first, so nothing is sealed only to be deleted. The age limit is
	 * the backend's, measured the same way (UTC, from today): a record older
	 * than that is dropped on arrival, so sending it would only cost bytes. A
	 * day that could not be delivered inside that window is a visible gap in
	 * the numbers, not a mis-dated one.
	 *
	 * Sealing is bounded per run to what the run can send; anything left is
	 * sealed by the next.
	 */
	private function close_page_view_days(): void {
		$store = $this->page_view_store();
		if ( null === $store ) {
			return;
		}
		$now = time();
		$store->prune(
			gmdate( 'Y-m-d', $now - IngestTransport::MAX_PAGE_VIEW_AGE_DAYS * DAY_IN_SECONDS )
		);
		$store->seal(
			gmdate( 'Y-m-d', $now ),
			self::MAX_BATCHES_PER_RUN * IngestTransport::MAX_PAGE_VIEWS_PER_BATCH
		);
	}

	/**
	 * Sealed page-view records still waiting to be sent.
	 */
	private function pending_page_views(): int {
		$store = $this->page_view_store();
		return null === $store ? 0 : $store->sealed_count();
	}

	/**
	 * The page-view store, or null when there is none or its table is not
	 * installed yet (schema version 1). The event path never depends on it: a
	 * missing counter table leaves agent-traffic reporting exactly as it was.
	 */
	private function page_view_store(): ?PageViewStore {
		return null !== $this->page_views && Schema::page_views_ready() ? $this->page_views : null;
	}

	/**
	 * Reduce a claim until its envelope fits under the backend's wire caps.
	 *
	 * Halves rather than trims one record at a time: an oversize batch is rare,
	 * and halving converges in a handful of encodes instead of hundreds.
	 * Whatever is left over stays stored for the next flush rather than being
	 * discarded.
	 *
	 * Which list gives way depends on why the envelope does not fit:
	 *
	 * - **Too big**: page views are halved first, down to one record, and only
	 *   then events. Events are the time-sensitive half — past 90 minutes on
	 *   arrival they are re-stamped and mis-bucketed — while a closed day's
	 *   counters keep for a week, so page views must never throttle event
	 *   throughput.
	 * - **Unencodable** (``encode()`` returned ''): the culprit is an event,
	 *   whose fields are recorded verbatim — page-view paths are validated
	 *   UTF-8 — so only events are halved, isolating it without shrinking the
	 *   page-view slice.
	 *
	 * @param array<int, array{id:int, event:Event}> $claimed Claimed rows, oldest first.
	 * @param array<int, array<string, mixed>>       $views   Sealed page-view records, oldest first.
	 * @param int                                    $dropped Drops to report in the envelope.
	 * @return array{claimed: array<int, array{id:int, event:Event}>, views: array<int, array<string, mixed>>, json: string}
	 */
	private function fit( array $claimed, array $views, int $dropped ): array {
		$events = count( $claimed );
		$pages  = count( $views );

		while ( true ) {
			$event_slice = array_slice( $claimed, 0, $events );
			$page_slice  = array_slice( $views, 0, $pages );
			$json        = $this->encode( $event_slice, $page_slice, $dropped );

			if ( $this->fits( $json ) ) {
				return array(
					'claimed' => $event_slice,
					'views'   => $page_slice,
					'json'    => $json,
				);
			}

			if ( '' === $json && $events > 1 ) {
				$events = (int) floor( $events / 2 );
			} elseif ( '' !== $json && $pages > 1 ) {
				$pages = (int) floor( $pages / 2 );
			} elseif ( $events > 1 ) {
				$events = (int) floor( $events / 2 );
			} else {
				break;
			}
		}

		return array(
			'claimed' => array(),
			'views'   => array(),
			'json'    => '',
		);
	}

	/**
	 * Whether an envelope is inside both the decompressed and the wire caps.
	 *
	 * @param string $json Encoded envelope.
	 */
	private function fits( string $json ): bool {
		// An empty envelope means encoding failed (see encode()). Sending it would
		// be silent data loss: the endpoint answers 202 to an empty body, this
		// class would read that as acceptance and delete the rows it never sent.
		return '' !== $json
			&& strlen( $json ) <= IngestTransport::MAX_BODY_BYTES
			&& $this->transport->wire_size( $json ) <= $this->transport->wire_cap();
	}

	/**
	 * Encode one flush envelope. camelCase throughout, per the contract.
	 *
	 * Returns '' when the payload cannot be encoded at all — malformed UTF-8 in a
	 * captured User-Agent or Referer, which is recorded verbatim by design.
	 * {@see fits()} treats that as "doesn't fit", so the batch halves down to a
	 * single event and, if that one still fails, it is discarded and counted
	 * rather than wedging every later flush behind it.
	 *
	 * ``pageViews`` is present only when there are records to send, so an
	 * events-only envelope is byte-for-byte what earlier versions sent.
	 *
	 * @param array<int, array{id:int, event:Event}> $claimed Rows to send.
	 * @param array<int, array<string, mixed>>       $views   Page-view records to send.
	 * @param int                                    $dropped Drops to report.
	 */
	private function encode( array $claimed, array $views, int $dropped ): string {
		$events = array();
		$site   = array();
		foreach ( $claimed as $row ) {
			// One buffer, two lanes: a site event goes under ``siteEvents`` and
			// its payload has no ip key at all.
			if ( $row['event']->is_site() ) {
				$site[] = $row['event']->to_payload();
			} else {
				$events[] = $row['event']->to_payload();
			}
		}

		$envelope = array(
			'producer'   => 'wordpress-plugin/' . \Globetrotters\AiPresence\VERSION,
			// Local sampling is the escape hatch for a very high-traffic apex;
			// the backend scales counts by 1/sampleRate. The plugin reports
			// everything it captured, so this stays at 1.0.
			'sampleRate' => 1.0,
			'dropped'    => $dropped,
			'events'     => $events,
		);

		if ( array() !== $views ) {
			$envelope['pageViews'] = array();
			foreach ( $views as $record ) {
				// The stored bucket is the counter's key, ``browser@chatgpt`` for a
				// referred view; the wire carries the bucket and the platform apart,
				// ``none`` for every row without one (all that 0.8.x wrote).
				list( $bucket, $platform ) = PageViewCounter::split_stored_bucket( (string) $record['bucket'] );
				$envelope['pageViews'][]   = array(
					'id'       => (string) $record['record_id'],
					'day'      => (string) $record['day'],
					'path'     => (string) $record['path'],
					'bucket'   => $bucket,
					'platform' => $platform,
					// Clamped rather than dropped: the backend refuses a record past
					// its ceiling outright, and no real page gets near it.
					'count'    => min( (int) $record['views'], IngestTransport::MAX_PAGE_VIEW_COUNT ),
				);
			}
		}

		if ( array() !== $site ) {
			$envelope['siteEvents'] = $site;
		}
		if ( null !== $this->context_block ) {
			$envelope['captureContext'] = $this->context_block;
		}

		$json = wp_json_encode(
			$envelope,
			// Keep sampleRate on the wire as `1.0` rather than `1`: the contract
			// types it as a float in (0, 1], and an integer is a needless dialect
			// difference for anything reading the payload.
			JSON_PRESERVE_ZERO_FRACTION
		);

		return is_string( $json ) ? $json : '';
	}

	/**
	 * Record the outcome of one attempt in the flush state.
	 *
	 * @param IngestResult $result Transport outcome.
	 */
	private function record_attempt( IngestResult $result ): void {
		$now   = time();
		$state = array(
			'last_flush_attempt' => $now,
			'last_flush_error'   => $result->is_rate_limited()
				? __( 'Globetrotters is rate limiting this site; the same batch will be retried.', 'globetrotters-ai-presence' )
				: $result->error_message(),
		);

		if ( $result->is_accepted() ) {
			$state['last_flush_ok'] = $now;
			$state['flush_count']   = (int) $this->options->flush_state()['flush_count'] + 1;
		}

		$this->options->update_flush_state( $state );
	}

	/**
	 * Take the flush lock, reclaiming an abandoned one.
	 */
	private function acquire_lock(): bool {
		// autoload 'no': a lock that lives for the duration of one cron run has no
		// business in alloptions on every request.
		if ( add_option( self::LOCK_OPTION, time(), '', false ) ) {
			return true;
		}

		$held = (int) get_option( self::LOCK_OPTION, 0 );
		if ( $held > 0 && ( time() - $held ) < self::LOCK_TTL_SECONDS ) {
			return false;
		}

		// Stale (or corrupt) lock: reclaim it.
		delete_option( self::LOCK_OPTION );
		return (bool) add_option( self::LOCK_OPTION, time(), '', false );
	}

	/**
	 * Release the flush lock.
	 */
	private function release_lock(): void {
		delete_option( self::LOCK_OPTION );
	}
}
