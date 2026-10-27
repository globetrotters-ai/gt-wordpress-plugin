<?php
/**
 * @package Globetrotters\AiPresence\Tests
 */

declare(strict_types=1);

namespace Globetrotters\AiPresence\Tests\Unit\Sync;

use Brain\Monkey\Functions;
use Globetrotters\AiPresence\Cache\ArtefactCache;
use Globetrotters\AiPresence\Client\Fetcher;
use Globetrotters\AiPresence\Client\FetchResult;
use Globetrotters\AiPresence\Serving\ContentTypes;
use Globetrotters\AiPresence\Settings\Options;
use Globetrotters\AiPresence\Sync\ArtefactSync;
use Globetrotters\AiPresence\Tests\Support\FakeFetcher;
use Globetrotters\AiPresence\Tests\Support\FakeRefreshLock;
use Globetrotters\AiPresence\Tests\Support\WpOptions;
use Globetrotters\AiPresence\Tests\TestCase;

/**
 * @covers \Globetrotters\AiPresence\Sync\ArtefactSync
 */
final class ArtefactSyncTest extends TestCase {

	use WpOptions;

	/**
	 * An ai.json shaped like the backend publishes it: cross-file links resolved
	 * against the canonical origin the bundle was published to.
	 */
	private const AI_JSON = '{"name":"Acme","url":"https://www.visit-acme.example","metadata":{"relatedFiles":['
		. '{"url":"https://ai.visit-acme.example/llms.txt"},'
		. '{"url":"https://ai.visit-acme.example/schema.json"}]}}';

	/**
	 * A key shaped like the ones the presence stack issues (32 hex chars).
	 */
	private const INDEXNOW_KEY = 'e715a2e7bf3c4a1d8e0b6f9c2d5a7e14';

	private ArtefactCache $cache;
	private Options $options;

	protected function setUp(): void {
		parent::setUp();
		$this->fake_wp_options();
		Functions\when( 'wp_json_encode' )->alias( fn( $data ) => json_encode( $data ) );
		Functions\when( '__' )->returnArg();
		Functions\when( 'wp_parse_url' )->alias( fn( $url, $component = -1 ) => parse_url( $url, $component ) );
		Functions\when( 'sanitize_title' )->alias( fn( $title ) => strtolower( (string) $title ) );
		Functions\when( 'maybe_serialize' )->alias(
			fn( $value ) => is_array( $value ) || is_object( $value ) ? serialize( $value ) : $value
		);

		$this->store[ Options::SETTINGS ] = array( 'website_url' => 'https://acme.globetrotters.ai' );
		$this->cache                      = new ArtefactCache();
		$this->options                    = new Options();
	}

	public function test_run_pulls_the_full_set_and_synthesizes_the_marker(): void {
		$sync = new ArtefactSync( $this->all_good_fetcher(), $this->cache, $this->options );

		$result = $sync->run();

		$this->assertTrue( $result->is_success() );
		$this->assertTrue( $result->has_changed() );

		$files = $this->cache->files();
		foreach ( ContentTypes::paths() as $path ) {
			$this->assertArrayHasKey( $path, $files, "missing $path" );
		}

		$marker = json_decode( $files[ ContentTypes::VERSION_MARKER ], true );
		$this->assertSame( 'synthesized', $marker['source'] );
		$this->assertSame( 'acme', $marker['destinationSlug'] );
		$this->assertNotSame( '', $marker['contentHash'] );

		$state = $this->options->state();
		$this->assertSame( '', $state['last_error'] );
		$this->assertNotSame( 0, $state['last_refresh'] );
	}

	public function test_a_refresh_already_running_exits_without_fetching(): void {
		// WP-Cron's loopback and a system cron, or a cron run and "Refresh now",
		// firing together fetched every file twice.
		$this->cache->store( array( 'llms.txt' => 'old' ), 'old-version' );
		$lock       = new FakeRefreshLock();
		$lock->held = true;
		$fetcher    = $this->all_good_fetcher();

		$result = ( new ArtefactSync( $fetcher, $this->cache, $this->options, $lock ) )->run();

		$this->assertFalse( $result->is_success() );
		$this->assertTrue( $result->is_busy() );
		$this->assertSame( array(), $fetcher->requested, 'the second refresher fetched' );
		$this->assertSame( 'old-version', $result->version() );
		$this->assertSame( '', $this->options->state()['last_error'], 'a refresh in progress is not a failure' );
		$this->assertTrue( $lock->held, 'the other holder keeps its lock' );
	}

	public function test_a_pull_that_lost_its_lock_publishes_nothing(): void {
		// It outlived the lock's lifetime and a successor reclaimed it while
		// this one was still fetching: a late result must not land over it.
		$this->cache->store( array( 'llms.txt' => 'old' ), 'old-version' );
		$lock       = new FakeRefreshLock();
		$lock->lost = true;

		$result = ( new ArtefactSync( $this->all_good_fetcher(), $this->cache, $this->options, $lock ) )->run();

		$this->assertFalse( $result->is_success() );
		$this->assertTrue( $result->is_busy() );
		$this->assertSame( 'old', $this->cache->get( 'llms.txt' ) );
		$this->assertSame( '', $this->options->state()['last_error'] );
		$this->assertSame( 0, (int) $this->options->state()['last_refresh'] );
	}

	public function test_the_lock_is_released_after_a_successful_run(): void {
		$lock = new FakeRefreshLock();

		$result = ( new ArtefactSync( $this->all_good_fetcher(), $this->cache, $this->options, $lock ) )->run();

		$this->assertTrue( $result->is_success() );
		$this->assertFalse( $result->is_busy() );
		$this->assertSame( 1, $lock->acquired );
		$this->assertSame( 1, $lock->released );
	}

	public function test_the_lock_is_released_after_a_failed_run(): void {
		$lock = new FakeRefreshLock();

		$result = ( new ArtefactSync( new FakeFetcher(), $this->cache, $this->options, $lock ) )->run();

		$this->assertFalse( $result->is_success() );
		$this->assertFalse( $lock->held );
		$this->assertSame( 1, $lock->released );
	}

	public function test_run_uses_upstream_marker_version_when_served(): void {
		$fetcher = $this->all_good_fetcher();
		$fetcher->on(
			'/' . ContentTypes::VERSION_MARKER,
			FetchResult::http( 200, '{"version":"2026-06-01-120000","contentHash":"abc"}' )
		);

		$result = ( new ArtefactSync( $fetcher, $this->cache, $this->options ) )->run();

		$this->assertSame( '2026-06-01-120000', $result->version() );
		$this->assertSame( '2026-06-01-120000', $this->options->state()['installed_version'] );
	}

	public function test_run_aborts_and_keeps_stale_bundle_on_required_failure(): void {
		// Seed a previous good bundle.
		$this->cache->store( array( 'llms.txt' => 'old' ), 'old-version' );

		$fetcher = $this->all_good_fetcher();
		$fetcher->on( '/ai.json', FetchResult::http( 500, '' ) );

		$result = ( new ArtefactSync( $fetcher, $this->cache, $this->options ) )->run();

		$this->assertFalse( $result->is_success() );
		$this->assertNotSame( '', $this->options->state()['last_error'] );
		// The previous bundle is untouched — stale-serve.
		$this->assertSame( 'old', $this->cache->get( 'llms.txt' ) );
		$this->assertSame( 'old-version', $this->cache->version() );
	}

	/**
	 * The four agent discovery files are required artefacts, on the same pull,
	 * validate, cache path as mcp.json: one of them gone upstream fails the
	 * whole refresh, and the bundle already cached keeps serving.
	 *
	 * @dataProvider agent_discovery_path_provider
	 */
	public function test_a_missing_agent_discovery_file_keeps_the_last_good_bundle( string $path ): void {
		$good = new ArtefactSync( $this->all_good_fetcher(), $this->cache, $this->options );
		$this->assertTrue( $good->run()->is_success() );
		$served  = $this->cache->get( $path );
		$version = $this->cache->version();
		$this->assertNotNull( $served, "$path should be cached by a good pull" );

		$fetcher = $this->all_good_fetcher();
		$fetcher->on( '/' . $path, FetchResult::http( 404, '' ) );
		$result = ( new ArtefactSync( $fetcher, $this->cache, $this->options ) )->run();

		$this->assertFalse( $result->is_success() );
		$this->assertSame( $served, $this->cache->get( $path ) );
		$this->assertSame( '# Acme', $this->cache->get( 'llms.txt' ) );
		$this->assertSame( $version, $this->cache->version() );
	}

	/**
	 * @return array<string, array{0:string}>
	 */
	public static function agent_discovery_path_provider(): array {
		return array(
			'agent.json'         => array( 'agent.json' ),
			'AGENTS.md'          => array( 'AGENTS.md' ),
			'ai-catalog.json'    => array( '.well-known/ai-catalog.json' ),
			'agent-skills index' => array( '.well-known/agent-skills/index.json' ),
		);
	}

	public function test_run_rejects_an_oversize_body_and_keeps_stale_bundle(): void {
		// Seed a previous good bundle.
		$this->cache->store( array( 'llms.txt' => 'old' ), 'old-version' );

		$fetcher = $this->all_good_fetcher();
		$fetcher->on( '/ai.json', FetchResult::http( 200, str_repeat( 'a', Fetcher::MAX_BODY_BYTES + 1 ) ) );

		$result = ( new ArtefactSync( $fetcher, $this->cache, $this->options ) )->run();

		$this->assertFalse( $result->is_success() );
		$this->assertStringContainsString( 'ai.json', $result->error_message() );
		$this->assertSame( 'old', $this->cache->get( 'llms.txt' ) );
		$this->assertSame( 'old-version', $this->cache->version() );
	}

	public function test_run_accepts_a_body_at_exactly_the_size_limit(): void {
		$fetcher = $this->all_good_fetcher();
		$fetcher->on( '/llms.txt', FetchResult::http( 200, str_repeat( 'a', Fetcher::MAX_BODY_BYTES ) ) );

		$result = ( new ArtefactSync( $fetcher, $this->cache, $this->options ) )->run();

		$this->assertTrue( $result->is_success() );
	}

	public function test_run_accepts_an_empty_but_successful_200_body(): void {
		$fetcher = $this->all_good_fetcher();
		$fetcher->on( '/llms.txt', FetchResult::http( 200, '' ) );

		$result = ( new ArtefactSync( $fetcher, $this->cache, $this->options ) )->run();

		$this->assertTrue( $result->is_success() );
		$this->assertSame( '', $this->cache->get( 'llms.txt' ) );
	}

	public function test_run_refuses_an_all_empty_pull_and_keeps_the_stale_bundle(): void {
		// Seed a previous good bundle.
		$this->cache->store( array( 'llms.txt' => 'old' ), 'old-version' );

		// Every required file answers 200 with no body — the signature of a
		// broken origin or an interposed proxy, not of a real publish.
		$fetcher = ( new FakeFetcher() )->fallback( FetchResult::http( 200, '' ) );

		$result = ( new ArtefactSync( $fetcher, $this->cache, $this->options ) )->run();

		$this->assertFalse( $result->is_success() );
		$this->assertStringContainsString( 'empty', $result->error_message() );
		// Stale-serve: blanks never replace the last good bundle.
		$this->assertSame( 'old', $this->cache->get( 'llms.txt' ) );
		$this->assertSame( 'old-version', $this->cache->version() );
	}

	/**
	 * A proxy or maintenance mode answering 200 with an HTML page passes every
	 * status and size check. It must not replace a good bundle, and nothing the
	 * failed pull saw may leak into state: not the change date the sitemap
	 * reports, and not the IndexNow key.
	 */
	public function test_an_html_page_served_as_json_keeps_the_whole_last_good_generation(): void {
		$good = $this->all_good_fetcher();
		$good->on( '/' . ContentTypes::VERSION_MARKER, $this->marker_with_key( self::INDEXNOW_KEY ) );
		$this->assertTrue( ( new ArtefactSync( $good, $this->cache, $this->options ) )->run()->is_success() );
		$files = $this->cache->files();
		// Back-dated, so "unchanged" cannot just be the same second passing.
		$this->store[ Options::STATE ]['last_refresh']       = 1000;
		$this->store[ Options::STATE ]['content_changed_at'] = 1000;
		$before = $this->options->state();

		$maintenance = $this->all_good_fetcher();
		$maintenance->on( '/schema.json', FetchResult::http( 200, '<!DOCTYPE html><html><body>Down for maintenance</body></html>' ) );
		$maintenance->on( '/llms.txt', FetchResult::http( 200, 'republished' ) );
		$maintenance->on( '/' . ContentTypes::VERSION_MARKER, $this->marker_with_key( 'rotated-key-0000000000000000' ) );
		$result = ( new ArtefactSync( $maintenance, $this->cache, $this->options ) )->run();

		$this->assertFalse( $result->is_success() );
		$this->assertStringContainsString( '/schema.json', $result->error_message() );
		$this->assertStringContainsString( 'not valid JSON', $result->error_message() );
		// Every file of the previous generation, not only the rejected one.
		$this->assertSame( $files, $this->cache->files() );
		$after = $this->options->state();
		foreach ( array( 'content_hash', 'content_changed_at', 'indexnow_key', 'installed_version', 'last_refresh' ) as $key ) {
			$this->assertSame( $before[ $key ], $after[ $key ], $key . ' must not advance on a rejected pull' );
		}
		$this->assertSame( $result->error_message(), $after['last_error'] );
	}

	/**
	 * @dataProvider unusable_json_artefact_provider
	 * @param string $path Required path.
	 * @param string $body What it answers 200 with.
	 */
	public function test_an_unusable_json_artefact_is_rejected( string $path, string $body ): void {
		$this->assertTrue( ( new ArtefactSync( $this->all_good_fetcher(), $this->cache, $this->options ) )->run()->is_success() );
		$good = $this->cache->get( $path );

		$fetcher = $this->all_good_fetcher();
		$fetcher->on( '/' . $path, FetchResult::http( 200, $body ) );
		$result = ( new ArtefactSync( $fetcher, $this->cache, $this->options ) )->run();

		$this->assertFalse( $result->is_success() );
		$this->assertStringContainsString( '/' . $path, $result->error_message() );
		$this->assertSame( $good, $this->cache->get( $path ) );
	}

	/**
	 * @return array<string, array{0:string, 1:string}>
	 */
	public static function unusable_json_artefact_provider(): array {
		return array(
			'truncated'                    => array( 'ai.json', '{"a":' ),
			'empty'                        => array( '.well-known/mcp.json', '' ),
			'whitespace'                   => array( 'schema.json', "  \n" ),
			'json null'                    => array( 'ai.json', 'null' ),
			'bare string'                  => array( '.well-known/agent-card.json', '"maintenance"' ),
			'list where an object belongs' => array( 'ai.json', '[{"a":1}]' ),
			'schema scalar'                => array( 'schema.json', '42' ),
			'schema list of scalars'       => array( 'schema.json', '[1,2]' ),
			'schema empty list'            => array( 'schema.json', '[]' ),
		);
	}

	/**
	 * JSON-LD allows a top-level array of node objects, so schema.json is held
	 * to that rather than to a single object.
	 */
	public function test_schema_may_be_a_list_of_nodes(): void {
		$fetcher = $this->all_good_fetcher();
		$fetcher->on( '/schema.json', FetchResult::http( 200, '[{"@type":"TouristDestination"},{"@type":"Place"}]' ) );

		$this->assertTrue( ( new ArtefactSync( $fetcher, $this->cache, $this->options ) )->run()->is_success() );
	}

	/**
	 * The parse error is read before translating the message around it, so a
	 * gettext filter that makes a JSON call of its own cannot reset it.
	 */
	public function test_the_parse_error_survives_translation(): void {
		Functions\when( '__' )->alias(
			static function ( $text ) {
				json_decode( '{}' );
				return $text;
			}
		);
		$fetcher = $this->all_good_fetcher();
		$fetcher->on( '/ai.json', FetchResult::http( 200, '{"a":' ) );

		$result = ( new ArtefactSync( $fetcher, $this->cache, $this->options ) )->run();

		$this->assertFalse( $result->is_success() );
		$this->assertStringContainsString( 'not valid JSON: Syntax error', $result->error_message() );
	}

	public function test_text_artefacts_are_not_held_to_json(): void {
		$fetcher = $this->all_good_fetcher();
		$fetcher->on( '/llms.txt', FetchResult::http( 200, "# Acme\n\n> {not json} <b>markdown may carry markup</b>\n" ) );

		$this->assertTrue( ( new ArtefactSync( $fetcher, $this->cache, $this->options ) )->run()->is_success() );
	}

	public function test_run_treats_a_failed_persist_as_a_failed_refresh(): void {
		// Seed a previous good bundle.
		$this->cache->store( array( 'llms.txt' => 'old' ), 'old-version' );
		$before = $this->options->state();

		// The artefact option refuses to move; every other option still writes.
		Functions\when( 'update_option' )->alias(
			function ( $name, $value ) {
				if ( ArtefactCache::OPTION === $name ) {
					return false;
				}
				$this->store[ $name ] = $value;
				return true;
			}
		);

		$result = ( new ArtefactSync( $this->all_good_fetcher(), $this->cache, $this->options ) )->run();

		$this->assertFalse( $result->is_success() );
		// The state must not describe a bundle this site cannot serve.
		$state = $this->options->state();
		$this->assertNotSame( '', $state['last_error'] );
		$this->assertSame( $before['installed_version'], $state['installed_version'] );
		$this->assertSame( $before['content_hash'], $state['content_hash'] );
		$this->assertSame( 'old', $this->cache->get( 'llms.txt' ) );
		$this->assertSame( 'old-version', $this->cache->version() );
	}

	public function test_run_derives_the_published_origin(): void {
		( new ArtefactSync( $this->all_good_fetcher(), $this->cache, $this->options ) )->run();

		$this->assertSame( 'https://ai.visit-acme.example', $this->options->state()['presence_origin'] );
	}

	public function test_a_later_refresh_follows_the_origin_when_it_flips(): void {
		// A managed hostname activating (or detaching) changes the canonical host
		// the backend bakes into the artefacts. The plugin must follow it on the
		// normal refresh cycle, with nothing for the customer to update.
		$sync = new ArtefactSync( $this->all_good_fetcher(), $this->cache, $this->options );
		$sync->run();
		$this->assertSame( 'https://ai.visit-acme.example', $this->options->state()['presence_origin'] );

		$detached = $this->all_good_fetcher();
		$detached->on(
			'/ai.json',
			FetchResult::http( 200, str_replace( 'ai.visit-acme.example', 'acme.globetrotters.ai', self::AI_JSON ) )
		);

		( new ArtefactSync( $detached, $this->cache, $this->options ) )->run();

		$this->assertSame( 'https://acme.globetrotters.ai', $this->options->state()['presence_origin'] );
	}

	public function test_an_undeterminable_origin_is_recorded_as_empty(): void {
		$fetcher = $this->all_good_fetcher();
		$fetcher->on( '/ai.json', FetchResult::http( 200, '{"name":"Acme"}' ) );

		( new ArtefactSync( $fetcher, $this->cache, $this->options ) )->run();

		$this->assertSame( '', $this->options->state()['presence_origin'] );
	}

	public function test_run_records_latest_equal_to_installed_on_success(): void {
		$sync  = new ArtefactSync( $this->all_good_fetcher(), $this->cache, $this->options );
		$sync->run();

		$state = $this->options->state();
		$this->assertSame( $state['installed_version'], $state['latest_version'] );
	}

	public function test_run_aborts_on_transport_error(): void {
		$fetcher = $this->all_good_fetcher();
		$fetcher->on( '/schema.json', FetchResult::error( 'timeout' ) );

		$result = ( new ArtefactSync( $fetcher, $this->cache, $this->options ) )->run();

		$this->assertFalse( $result->is_success() );
		$this->assertStringContainsString( 'timeout', $result->error_message() );
	}

	public function test_run_fails_cleanly_when_not_connected(): void {
		$this->store[ Options::SETTINGS ] = array( 'website_url' => '' );
		$options                          = new Options();

		$result = ( new ArtefactSync( $this->all_good_fetcher(), $this->cache, $options ) )->run();

		$this->assertFalse( $result->is_success() );
	}

	/**
	 * The pulled artefacts are published at the customer's apex, homepage
	 * JSON-LD included, so a cleartext or scheme-less source is refused before
	 * anything is fetched — a value written by ``wp option update`` never
	 * passes the settings sanitizer — and the last good bundle keeps serving.
	 *
	 * @dataProvider insecure_website_url_provider
	 * @param string $website_url A website URL that is not absolute https.
	 */
	public function test_run_refuses_a_website_url_that_is_not_https( string $website_url ): void {
		$this->sync_successfully();
		$before = $this->cache->files();
		$state  = $this->options->state();

		$this->store[ Options::SETTINGS ] = array( 'website_url' => $website_url );
		$fetcher                          = $this->all_good_fetcher();

		$result = ( new ArtefactSync( $fetcher, $this->cache, new Options() ) )->run();

		$this->assertFalse( $result->is_success() );
		$this->assertSame( array(), $fetcher->requested, 'Nothing may be fetched from a non-https source.' );
		$this->assertSame( ArtefactSync::insecure_url_message(), ( new Options() )->state()['last_error'] );
		$this->assertSame( $before, ( new ArtefactCache() )->files(), 'The last good bundle must keep serving.' );
		$this->assertSame( $state['last_refresh'], ( new Options() )->state()['last_refresh'] );
	}

	/**
	 * @dataProvider insecure_website_url_provider
	 * @param string $website_url A website URL that is not absolute https.
	 */
	public function test_check_latest_fetches_nothing_from_a_website_url_that_is_not_https( string $website_url ): void {
		$this->store[ Options::SETTINGS ] = array( 'website_url' => $website_url );
		$fetcher                          = $this->all_good_fetcher();

		$this->assertSame( '', ( new ArtefactSync( $fetcher, $this->cache, new Options() ) )->check_latest() );
		$this->assertSame( array(), $fetcher->requested );
	}

	/**
	 * @return array<string, array{0:string}>
	 */
	public static function insecure_website_url_provider(): array {
		return array(
			'http'        => array( 'http://acme.globetrotters.ai' ),
			'scheme-less' => array( 'acme.globetrotters.ai' ),
			'other'       => array( 'ftp://acme.globetrotters.ai' ),
			'no host'     => array( 'https:///llms' ),
		);
	}

	public function test_second_identical_upstream_run_reports_no_change(): void {
		$fetcher = $this->all_good_fetcher();
		$fetcher->on(
			'/' . ContentTypes::VERSION_MARKER,
			FetchResult::http( 200, '{"version":"2026-06-01-120000"}' )
		);
		$sync = new ArtefactSync( $fetcher, $this->cache, $this->options );

		$this->assertTrue( $sync->run()->has_changed() );
		$this->assertFalse( $sync->run()->has_changed() );
	}

	/**
	 * ``content_changed_at`` stamps the generated sitemap's ``<lastmod>``, so it
	 * has to mean *the content moved*, not *cron ran*. A refresh that finds the
	 * same bytes must leave the previous stamp standing.
	 */
	public function test_content_changed_at_only_advances_when_the_content_changes(): void {
		$fetcher = $this->all_good_fetcher();
		$fetcher->on(
			'/' . ContentTypes::VERSION_MARKER,
			FetchResult::http( 200, '{"version":"2026-06-01-120000"}' )
		);
		$sync = new ArtefactSync( $fetcher, $this->cache, $this->options );

		$sync->run();
		$first = $this->options->content_changed_at();
		$this->assertGreaterThan( 0, $first );

		// A later identical pull: same bytes, so the stamp must not move even
		// though last_refresh does.
		$this->store[ Options::STATE ]['content_changed_at'] = $first - 3600;
		$sync->run();

		$this->assertSame( $first - 3600, $this->options->content_changed_at() );
	}

	public function test_content_changed_at_is_zero_before_any_successful_pull(): void {
		$this->assertSame( 0, $this->options->content_changed_at() );
	}

	public function test_check_latest_reads_upstream_marker(): void {
		$fetcher = ( new FakeFetcher() )->on(
			'/' . ContentTypes::VERSION_MARKER,
			FetchResult::http( 200, '{"version":"2026-07-02-090000"}' )
		);

		$sync = new ArtefactSync( $fetcher, $this->cache, $this->options );

		$this->assertSame( '2026-07-02-090000', $sync->check_latest() );
	}

	public function test_check_latest_is_empty_without_upstream_marker(): void {
		$sync = new ArtefactSync( ( new FakeFetcher() )->fallback( FetchResult::http( 404, '' ) ), $this->cache, $this->options );

		$this->assertSame( '', $sync->check_latest() );
	}

	public function test_content_hash_matches_the_backend_canonical_hash(): void {
		// Fixed vector: the expected digest is computed by the backend's canonical
		// bundle hash (sha256 over the sorted paths, each followed by NUL, the raw
		// sha256 digest of its body and NUL). This locks the plugin to
		// byte-for-byte parity with the backend and the Symfony bundle.
		$fetcher = new FakeFetcher();
		$fetcher->on( '/llms.txt', FetchResult::http( 200, 'hello' ) );
		$fetcher->on( '/ai.json', FetchResult::http( 200, '{"a":1}' ) );
		$fetcher->on( '/schema.json', FetchResult::http( 200, '{"@context":"https://schema.org"}' ) );
		$fetcher->on( '/.well-known/mcp.json', FetchResult::http( 200, '{"m":1}' ) );
		$fetcher->on( '/.well-known/agent-card.json', FetchResult::http( 200, '{"c":2}' ) );
		$fetcher->on( '/.well-known/ai-catalog.json', FetchResult::http( 200, '{"specVersion":"1.0"}' ) );
		$fetcher->on( '/.well-known/agent-skills/index.json', FetchResult::http( 200, '{"skills":[]}' ) );
		$fetcher->on( '/agent.json', FetchResult::http( 200, '{"name":"Acme"}' ) );
		$fetcher->on( '/AGENTS.md', FetchResult::http( 200, "# Acme\n" ) );
		$fetcher->fallback( FetchResult::http( 404, '' ) );

		$this->assertTrue( ( new ArtefactSync( $fetcher, $this->cache, $this->options ) )->run()->is_success() );

		$this->assertSame(
			'30b2e9f6dee4b43ea4f9e6c165de634ed5f908f4c5118a01034cbccdeadec0ef',
			$this->options->state()['content_hash']
		);
	}

	public function test_run_stores_the_indexnow_key_carried_by_the_upstream_marker(): void {
		$fetcher = $this->all_good_fetcher();
		$fetcher->on( '/' . ContentTypes::VERSION_MARKER, $this->marker_with_key( self::INDEXNOW_KEY ) );

		( new ArtefactSync( $fetcher, $this->cache, $this->options ) )->run();

		$this->assertSame( self::INDEXNOW_KEY, $this->options->state()['indexnow_key'] );
	}

	public function test_a_marker_without_the_key_clears_a_previously_stored_one(): void {
		$keyed = $this->all_good_fetcher();
		$keyed->on( '/' . ContentTypes::VERSION_MARKER, $this->marker_with_key( self::INDEXNOW_KEY ) );
		( new ArtefactSync( $keyed, $this->cache, $this->options ) )->run();

		// A key removed upstream (rotation to nothing, or a destination moved to
		// a keyless environment) must stop being served here, not linger.
		$keyless = $this->all_good_fetcher();
		$keyless->on( '/' . ContentTypes::VERSION_MARKER, FetchResult::http( 200, '{"version":"2026-06-02-120000"}' ) );
		( new ArtefactSync( $keyless, $this->cache, $this->options ) )->run();

		$this->assertSame( '', $this->options->state()['indexnow_key'] );
	}

	/**
	 * @dataProvider unusable_marker_key_provider
	 * @param mixed $value Value the marker carries under ``indexnowKey``.
	 */
	public function test_an_unusable_marker_key_is_not_stored( $value ): void {
		$fetcher = $this->all_good_fetcher();
		$fetcher->on(
			'/' . ContentTypes::VERSION_MARKER,
			FetchResult::http( 200, (string) json_encode( array( 'version' => 'v9', 'indexnowKey' => $value ) ) )
		);

		( new ArtefactSync( $fetcher, $this->cache, $this->options ) )->run();

		$this->assertSame( '', $this->options->state()['indexnow_key'] );
	}

	/**
	 * @return array<string, array{0:mixed}>
	 */
	public static function unusable_marker_key_provider(): array {
		return array(
			'empty'    => array( '' ),
			'short'    => array( 'abc' ),
			'newline'  => array( "abcdefgh\n" ),
			'not text' => array( 42 ),
			'array'    => array( array( 'abcdefgh' ) ),
		);
	}

	public function test_a_synthesized_marker_leaves_no_key(): void {
		// Nothing upstream serves a marker, so there is no key to learn. The
		// environment is keyless and the route must stay unserved.
		( new ArtefactSync( $this->all_good_fetcher(), $this->cache, $this->options ) )->run();

		$this->assertSame( '', $this->options->state()['indexnow_key'] );
	}

	public function test_a_keyless_sync_still_succeeds_and_reports_no_error(): void {
		$result = ( new ArtefactSync( $this->all_good_fetcher(), $this->cache, $this->options ) )->run();

		// The key is never a required fetched path: adding it to required_paths()
		// would fail every sync in an environment where no key is configured.
		$this->assertTrue( $result->is_success() );
		$this->assertSame( '', $this->options->state()['last_error'] );
		$this->assertArrayHasKey( 'llms.txt', $this->cache->files() );
	}

	public function test_the_indexnow_key_stays_out_of_the_content_hash(): void {
		$keyless = ( new ArtefactSync( $this->all_good_fetcher(), $this->cache, $this->options ) )->run();
		$keyless_hash = $this->options->state()['content_hash'];

		$keyed = $this->all_good_fetcher();
		$keyed->on( '/' . ContentTypes::VERSION_MARKER, $this->marker_with_key( self::INDEXNOW_KEY ) );
		( new ArtefactSync( $keyed, $this->cache, $this->options ) )->run();

		// The key rides on the marker, which is added after hashing — so drift
		// detection cannot fire on a key appearing, disappearing or rotating.
		$this->assertTrue( $keyless->is_success() );
		$this->assertSame( $keyless_hash, $this->options->state()['content_hash'] );
	}

	public function test_a_failed_sync_keeps_the_last_known_key(): void {
		$keyed = $this->all_good_fetcher();
		$keyed->on( '/' . ContentTypes::VERSION_MARKER, $this->marker_with_key( self::INDEXNOW_KEY ) );
		( new ArtefactSync( $keyed, $this->cache, $this->options ) )->run();

		$broken = $this->all_good_fetcher();
		$broken->on( '/llms.txt', FetchResult::http( 500, '' ) );
		( new ArtefactSync( $broken, $this->cache, $this->options ) )->run();

		// Same rule as the bundle itself: a failed pull learns nothing, so the
		// site keeps serving the last key it was told about.
		$this->assertSame( self::INDEXNOW_KEY, $this->options->state()['indexnow_key'] );
	}

	// --- forgetting a source -----------------------------------------------

	/**
	 * Stale-serve keeps the last good bundle when *the same* source goes flaky.
	 * A bundle from a source the site has been disconnected from, or moved away
	 * from, is not a fallback: serving it publishes a presence the customer has
	 * withdrawn, or another destination's.
	 */
	public function test_forget_drops_the_bundle_and_everything_learned_from_its_source(): void {
		$this->sync_successfully();
		// What 0.4.0 left in the state option: pending drops not yet reported,
		// which the drop counter still has to seed from.
		$this->store[ Options::STATE ]['dropped_pending'] = 4;

		$this->sync()->forget();

		$this->assertFalse( $this->cache->has_any() );
		$this->assertArrayNotHasKey( ArtefactCache::OPTION, $this->store );
		$state = $this->options->state();
		$this->assertSame( '', $state['installed_version'] );
		$this->assertSame( '', $state['latest_version'] );
		$this->assertSame( '', $state['presence_origin'] );
		$this->assertSame( '', $state['content_hash'] );
		$this->assertSame( '', $this->options->indexnow_key() );
		$this->assertSame( 0, $state['last_refresh'] );
		$this->assertSame( 0, $this->options->content_changed_at() );
		$this->assertSame( '', $state['last_error'] );
		$this->assertSame( 4, $this->store[ Options::STATE ]['dropped_pending'] );
	}

	/**
	 * @dataProvider source_change_provider
	 * @param string $website_url The website URL just saved.
	 */
	public function test_a_cleared_or_different_website_url_forgets_the_previous_source( string $website_url ): void {
		$this->sync_successfully();

		$this->sync()->forget_previous_source(
			array( 'website_url' => 'https://acme.globetrotters.ai' ),
			array( 'website_url' => $website_url )
		);

		$this->assertFalse( $this->cache->has_any() );
		$this->assertSame( '', $this->options->indexnow_key() );
		$this->assertSame( '', $this->options->presence_origin() );
	}

	/**
	 * @return array<string, array{0:string}>
	 */
	public static function source_change_provider(): array {
		return array(
			'disconnected'        => array( '' ),
			'another destination' => array( 'https://other-city.globetrotters.ai' ),
		);
	}

	public function test_saving_the_same_website_url_keeps_the_bundle(): void {
		$this->sync_successfully();

		// A re-save that changes the cadence and adds a trailing slash is still
		// the same source.
		$this->sync()->forget_previous_source(
			array(
				'website_url'      => 'https://acme.globetrotters.ai',
				'refresh_interval' => 'daily',
			),
			array(
				'website_url'      => 'https://acme.globetrotters.ai/',
				'refresh_interval' => 'weekly',
			)
		);

		$this->assertTrue( $this->cache->has_any() );
		$this->assertSame( self::INDEXNOW_KEY, $this->options->indexnow_key() );
	}

	/**
	 * A pull is up to ten blocking fetches (nine artefacts, then the marker). If the destination is cleared while
	 * one is in flight, storing its result would put back exactly the bundle the
	 * disconnect just removed — and with no destination, no refresh is scheduled
	 * to ever replace it.
	 */
	public function test_a_pull_that_outlives_a_destination_change_is_discarded(): void {
		$disconnect = function (): void {
			$this->store[ Options::SETTINGS ] = array( 'website_url' => '' );
		};
		$fetcher    = new class( $this->all_good_fetcher(), $disconnect ) implements Fetcher {
			private Fetcher $inner;

			/**
			 * @var callable
			 */
			private $during;

			public function __construct( Fetcher $inner, callable $during ) {
				$this->inner  = $inner;
				$this->during = $during;
			}

			public function fetch( string $url ): FetchResult {
				( $this->during )();
				return $this->inner->fetch( $url );
			}
		};

		$result = ( new ArtefactSync( $fetcher, $this->cache, $this->options ) )->run();

		$this->assertFalse( $result->is_success() );
		$this->assertFalse( $this->cache->has_any() );
		$this->assertSame( '', (string) $this->options->state()['installed_version'] );
	}

	/**
	 * Run a successful sync whose marker carries an IndexNow key.
	 */
	private function sync_successfully(): void {
		$fetcher = $this->all_good_fetcher()->on( '/' . ContentTypes::VERSION_MARKER, $this->marker_with_key( self::INDEXNOW_KEY ) );
		$this->assertTrue( ( new ArtefactSync( $fetcher, $this->cache, $this->options ) )->run()->is_success() );
		$this->assertTrue( $this->cache->has_any() );
		$this->assertSame( self::INDEXNOW_KEY, $this->options->indexnow_key() );
	}

	/**
	 * A sync with nothing to fetch, for exercising what it does without pulling.
	 */
	private function sync(): ArtefactSync {
		return new ArtefactSync( new FakeFetcher(), $this->cache, $this->options );
	}

	/**
	 * An upstream marker carrying an IndexNow key, shaped as the backend renders
	 * it (``render_version_marker``).
	 *
	 * @param string $key Key to carry.
	 */
	private function marker_with_key( string $key ): FetchResult {
		return FetchResult::http(
			200,
			(string) json_encode(
				array(
					'version'     => '2026-06-01-120000',
					'contentHash' => 'abc',
					'indexnowKey' => $key,
				)
			)
		);
	}

	/**
	 * A fetcher that serves every required file (marker falls through to 404).
	 */
	private function all_good_fetcher(): FakeFetcher {
		$fetcher = new FakeFetcher();
		$fetcher->on( '/llms.txt', FetchResult::http( 200, '# Acme' ) );
		$fetcher->on( '/ai.json', FetchResult::http( 200, self::AI_JSON ) );
		$fetcher->on( '/schema.json', FetchResult::http( 200, '{"@type":"Place"}' ) );
		$fetcher->on( '/.well-known/mcp.json', FetchResult::http( 200, '{"mcp":true}' ) );
		$fetcher->on( '/.well-known/agent-card.json', FetchResult::http( 200, '{"agent":true}' ) );
		$fetcher->on( '/.well-known/ai-catalog.json', FetchResult::http( 200, '{"specVersion":"1.0"}' ) );
		$fetcher->on( '/.well-known/agent-skills/index.json', FetchResult::http( 200, '{"skills":[]}' ) );
		$fetcher->on( '/agent.json', FetchResult::http( 200, '{"name":"Acme"}' ) );
		$fetcher->on( '/AGENTS.md', FetchResult::http( 200, "# Acme\n" ) );
		$fetcher->fallback( FetchResult::http( 404, '' ) );
		return $fetcher;
	}
}
