<?php
/**
 * @package Globetrotters\AiPresence\Tests
 */

declare(strict_types=1);

namespace Globetrotters\AiPresence\Tests\Unit\Analytics;

use Brain\Monkey\Functions;
use DateTimeImmutable;
use DateTimeZone;
use Globetrotters\AiPresence\Analytics\Event;
use Globetrotters\AiPresence\Analytics\EventBuffer;
use Globetrotters\AiPresence\Analytics\EventRecorder;
use Globetrotters\AiPresence\Analytics\Schema;
use Globetrotters\AiPresence\Settings\Options;
use Globetrotters\AiPresence\Tests\Support\FakeEventStore;
use Globetrotters\AiPresence\Tests\Support\FakeFlushRequest;
use Globetrotters\AiPresence\Tests\Support\WpOptions;
use Globetrotters\AiPresence\Tests\TestCase;

/**
 * @covers \Globetrotters\AiPresence\Analytics\EventRecorder
 */
final class EventRecorderTest extends TestCase {

	use WpOptions;

	private FakeEventStore $events;
	private EventRecorder $recorder;
	private FakeFlushRequest $flushes;
	private string $original_timezone;

	protected function setUp(): void {
		parent::setUp();
		$this->fake_wp_options();
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'wp_generate_uuid4' )->justReturn( '9f2c8a1e-5f3b-4a6d-9c2e-7b1d0f4a8c33' );

		$this->store[ Options::SETTINGS ] = array(
			'ingest_endpoint' => 'https://api.globetrotters.ai/presence/analytics/server-log',
			'ingest_token'    => 'tok_live',
		);
		$this->store[ Schema::VERSION_OPTION ] = Schema::VERSION;

		$options        = new Options();
		$this->events   = new FakeEventStore();
		$this->flushes  = new FakeFlushRequest();
		$this->recorder = new EventRecorder(
			new EventBuffer( $this->events ),
			$options,
			$this->flushes
		);

		$this->original_timezone = date_default_timezone_get();
		$_SERVER                 = array(
			'REMOTE_ADDR'     => '160.79.104.10',
			'HTTP_USER_AGENT' => 'Mozilla/5.0 (compatible; ClaudeBot/1.0; +claudebot@anthropic.com)',
			'HTTP_REFERER'    => 'https://example.test/',
		);
	}

	protected function tearDown(): void {
		date_default_timezone_set( $this->original_timezone ); // phpcs:ignore WordPress.DateTime.RestrictedFunctions.timezone_change_date_default_timezone_set
		parent::tearDown();
	}

	public function test_timestamp_is_utc_with_an_explicit_offset_on_a_paris_server(): void {
		// The failure this guards: a plugin using current_time() on a Europe/Paris
		// site reports every hit two hours off, and the backend re-stamps rather
		// than errors — so it degrades silently and forever.
		date_default_timezone_set( 'Europe/Paris' ); // phpcs:ignore WordPress.DateTime.RestrictedFunctions.timezone_change_date_default_timezone_set

		$event = $this->recorder->build( '/llms.txt', 200, 4211 );
		$utc   = ( new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) ) )->format( 'Y-m-d\TH:i' );

		$this->assertMatchesRegularExpression(
			'/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/',
			$event->ts(),
			'ts must carry an explicit UTC offset.'
		);
		$this->assertStringStartsWith( $utc, $event->ts(), 'ts must be real UTC, not local wall-clock time.' );
	}

	public function test_current_time_is_never_used(): void {
		// current_time() returns site-local time and current_time('mysql') returns
		// a naive string; the backend reads a bare timestamp as UTC.
		Functions\expect( 'current_time' )->never();

		$event = $this->recorder->build( '/llms.txt', 200, 4211 );

		$this->assertStringEndsWith( 'Z', $event->ts() );
		$this->addToAssertionCount( 1 );
	}

	public function test_event_carries_the_request_details(): void {
		$event = $this->recorder->build( '/.well-known/mcp.json', 200, 512 );

		$this->assertSame( '9f2c8a1e-5f3b-4a6d-9c2e-7b1d0f4a8c33', $event->id() );
		$this->assertSame( '/.well-known/mcp.json', $event->path() );
		$this->assertSame( '160.79.104.10', $event->ip() );
		$this->assertSame( 'https://example.test/', $event->referer() );
		$this->assertSame( 200, $event->status() );
		$this->assertSame( 512, $event->bytes() );
		$this->assertStringContainsString( 'ClaudeBot', $event->ua() );
	}

	public function test_long_fields_are_clipped_to_the_contract_limit(): void {
		$_SERVER['HTTP_USER_AGENT'] = str_repeat( 'a', 900 );

		$event = $this->recorder->build( '/llms.txt', 200, 10 );

		$this->assertSame( Event::MAX_FIELD_CHARS, strlen( $event->ua() ) );
	}

	public function test_records_into_the_buffer(): void {
		$this->recorder->record( '/llms.txt', 200, 4211 );

		$this->assertCount( 1, $this->events->rows );
	}

	public function test_an_ordinary_hit_does_not_ask_for_a_flush(): void {
		// The 15-minute schedule covers a quiet site; asking on every hit would put
		// an option write on the serve path for no gain.
		$this->recorder->record( '/llms.txt', 200, 4211 );

		$this->assertSame( 0, $this->flushes->requests );
	}

	public function test_a_filling_buffer_asks_for_an_early_flush(): void {
		// Past the high-water mark, and on the 25-hit prune boundary so the buffer
		// actually looks. Without this the site silently drops everything above
		// what one 15-minute interval can hold.
		$this->events->bytes_override = EventBuffer::MAX_BYTES;
		$this->events->next_id        = EventBuffer::MAX_EVENTS;

		$this->recorder->record( '/llms.txt', 200, 4211 );

		$this->assertSame( 1, $this->flushes->requests );
	}

	public function test_a_failure_in_the_flush_request_never_escapes_to_the_serve_path(): void {
		$this->events->bytes_override = EventBuffer::MAX_BYTES;
		$this->events->next_id        = EventBuffer::MAX_EVENTS;
		$recorder                     = new EventRecorder(
			new EventBuffer( $this->events ),
			new Options(),
			new class() implements \Globetrotters\AiPresence\Analytics\FlushRequest {
				public function request_flush(): void {
					throw new \RuntimeException( 'cron exploded' );
				}
			}
		);

		$recorder->record( '/llms.txt', 200, 4211 );

		// The event still landed; only the scheduling ask was lost.
		$this->assertCount( 1, $this->events->rows );
	}

	public function test_capture_is_off_until_ingest_credentials_are_saved(): void {
		$this->store[ Options::SETTINGS ] = array(
			'ingest_endpoint' => '',
			'ingest_token'    => '',
		);

		$this->recorder->record( '/llms.txt', 200, 4211 );

		$this->assertSame( array(), $this->events->rows );
	}

	public function test_capture_keeps_working_at_schema_version_1(): void {
		// Mid-upgrade to v2, or the page-view table failed to create: the event
		// buffer has existed since v1, so agent-traffic capture must not stop.
		$this->store[ Schema::VERSION_OPTION ] = 1;

		$this->recorder->record( '/llms.txt', 200, 4211 );

		$this->assertCount( 1, $this->events->rows );
	}

	public function test_capture_is_off_when_the_buffer_table_is_missing(): void {
		unset( $this->store[ Schema::VERSION_OPTION ] );

		$this->recorder->record( '/llms.txt', 200, 4211 );

		$this->assertSame( array(), $this->events->rows );
	}

	public function test_a_failure_in_capture_never_escapes_to_the_serve_path(): void {
		Functions\when( 'wp_generate_uuid4' )->alias(
			static function (): string {
				throw new \RuntimeException( 'boom' );
			}
		);

		$this->recorder->record( '/llms.txt', 200, 4211 );

		$this->assertSame( array(), $this->events->rows );
	}
}
