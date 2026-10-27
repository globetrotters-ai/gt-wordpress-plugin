<?php
/**
 * @package Globetrotters\AiPresence\Tests
 */

declare(strict_types=1);

namespace Globetrotters\AiPresence\Tests\Unit\Serving;

use Brain\Monkey\Functions;
use Globetrotters\AiPresence\Cache\ArtefactCache;
use Globetrotters\AiPresence\Serving\LinkHeader;
use Globetrotters\AiPresence\Tests\Support\WpOptions;
use Globetrotters\AiPresence\Tests\TestCase;

/**
 * The homepage ``Link`` header: the registered ``api-catalog`` and
 * ``service-doc`` relations, then the HTTP twin of the injected
 * ``<link rel="mcp|agent-card|ai-catalog">`` tags, on a 200 HTML response for
 * the front page only, added beside any ``Link`` header already sent. Mirrors
 * the Symfony bundle's HomepageLinkHeaderTest.
 *
 * @covers \Globetrotters\AiPresence\Serving\LinkHeader
 */
final class LinkHeaderTest extends TestCase {

	use WpOptions;

	private const LINK = 'Link: </.well-known/api-catalog>; rel="api-catalog", </llms.txt>; rel="service-doc", </.well-known/mcp.json>; rel="mcp", </.well-known/agent-card.json>; rel="agent-card", </.well-known/ai-catalog.json>; rel="ai-catalog"';

	/**
	 * What core sends on every front-end page (``rest_output_link_header``).
	 */
	private const CORE_LINK = 'Link: <https://acme.example/wp-json/>; rel="https://api.w.org/"';

	protected function setUp(): void {
		parent::setUp();
		$this->fake_wp_options();
		Functions\when( 'is_front_page' )->justReturn( true );
		Functions\when( 'is_paged' )->justReturn( false );
		Functions\when( 'is_404' )->justReturn( false );
		Functions\when( 'is_feed' )->justReturn( false );
		Functions\when( 'is_embed' )->justReturn( false );
	}

	public function test_the_homepage_carries_the_header(): void {
		$this->cache_full_bundle();
		$header = $this->spy( array( 'Content-Type: text/html; charset=UTF-8' ) );

		$header->maybe_send();

		$this->assertSame( array( array( self::LINK, false ) ), $header->sent );
	}

	/**
	 * Core already sends a ``Link`` header on the homepage, and a theme or SEO
	 * plugin may add more. Ours is a header line of its own (``replace`` false),
	 * so every one of them survives; HTTP reads repeated ``Link`` lines as one
	 * list.
	 */
	public function test_it_is_added_beside_a_link_header_the_site_already_sends(): void {
		$this->cache_full_bundle();
		$header = $this->spy( array( 'Content-Type: text/html; charset=UTF-8', self::CORE_LINK ) );

		$header->maybe_send();

		$this->assertSame( array( array( self::LINK, false ) ), $header->sent );
	}

	public function test_it_is_not_sent_twice(): void {
		$this->cache_full_bundle();
		$header = $this->spy( array( 'Content-Type: text/html; charset=UTF-8', self::LINK ) );

		$header->maybe_send();

		$this->assertSame( array(), $header->sent );
	}

	public function test_an_interior_page_has_none(): void {
		$this->cache_full_bundle();
		Functions\when( 'is_front_page' )->justReturn( false );
		$header = $this->spy( array( 'Content-Type: text/html; charset=UTF-8' ) );

		$header->maybe_send();

		$this->assertSame( array(), $header->sent );
	}

	/**
	 * ``/page/2/`` is still ``is_front_page()`` on a posts front page, but it is
	 * not the homepage ``check_link_headers`` reads.
	 */
	public function test_a_paged_front_page_has_none(): void {
		$this->cache_full_bundle();
		Functions\when( 'is_paged' )->justReturn( true );
		$header = $this->spy( array( 'Content-Type: text/html; charset=UTF-8' ) );

		$header->maybe_send();

		$this->assertSame( array(), $header->sent );
	}

	/**
	 * @dataProvider not_an_html_page_provider
	 */
	public function test_a_homepage_that_is_not_an_html_page_has_none( string $conditional ): void {
		$this->cache_full_bundle();
		Functions\when( $conditional )->justReturn( true );
		$header = $this->spy( array( 'Content-Type: text/html; charset=UTF-8' ) );

		$header->maybe_send();

		$this->assertSame( array(), $header->sent );
	}

	/**
	 * @return array<string, array{0:string}>
	 */
	public static function not_an_html_page_provider(): array {
		return array(
			'404'   => array( 'is_404' ),
			'feed'  => array( 'is_feed' ),
			'embed' => array( 'is_embed' ),
		);
	}

	public function test_a_non_html_response_has_none(): void {
		$this->cache_full_bundle();
		$header = $this->spy( array( 'Content-Type: application/json; charset=UTF-8' ) );

		$header->maybe_send();

		$this->assertSame( array(), $header->sent );
	}

	public function test_a_non_200_response_has_none(): void {
		$this->cache_full_bundle();
		$header = $this->spy( array( 'Content-Type: text/html; charset=UTF-8' ), 301 );

		$header->maybe_send();

		$this->assertSame( array(), $header->sent );
	}

	public function test_a_cold_cache_advertises_nothing(): void {
		$header = $this->spy( array( 'Content-Type: text/html; charset=UTF-8' ) );

		$header->maybe_send();

		$this->assertSame( array(), $header->sent );
	}

	/**
	 * A bundle cached before 0.7.0 has no catalog until its next refresh; the
	 * header names only what this install would answer. The API catalog is
	 * answered once any of its targets is cached, so it is named here although
	 * ``llms.txt`` is not.
	 */
	public function test_only_cached_targets_are_named(): void {
		$this->store[ ArtefactCache::OPTION ] = array(
			'files'   => array(
				'.well-known/mcp.json'        => '{"m":1}',
				'.well-known/agent-card.json' => '{"c":2}',
			),
			'version' => 'v1',
		);
		$header                               = $this->spy( array( 'Content-Type: text/html; charset=UTF-8' ) );

		$header->maybe_send();

		$this->assertSame(
			array( array( 'Link: </.well-known/api-catalog>; rel="api-catalog", </.well-known/mcp.json>; rel="mcp", </.well-known/agent-card.json>; rel="agent-card"', false ) ),
			$header->sent
		);
	}

	/**
	 * PHP's own default is ``text/html``, so a response with no Content-Type
	 * line yet is an HTML one.
	 *
	 * @dataProvider content_type_provider
	 *
	 * @param string[] $headers Headers sent so far.
	 */
	public function test_is_html_reads_the_last_content_type( array $headers, bool $expected ): void {
		$this->assertSame( $expected, LinkHeader::is_html( $headers ) );
	}

	/**
	 * @return array<string, array{0:string[],1:bool}>
	 */
	public static function content_type_provider(): array {
		return array(
			'none yet'        => array( array( 'X-Powered-By: PHP' ), true ),
			'html'            => array( array( 'Content-Type: text/html; charset=UTF-8' ), true ),
			'lower-case name' => array( array( 'content-type: TEXT/HTML' ), true ),
			'json'            => array( array( 'Content-Type: application/json' ), false ),
			'xml'             => array( array( 'Content-Type: application/rss+xml; charset=UTF-8' ), false ),
			'last one wins'   => array( array( 'Content-Type: text/html', 'Content-Type: application/json' ), false ),
		);
	}

	public function test_the_relations_match_the_backend(): void {
		// The backend's apex homepage link relations, in order:
		// the registered relations validators read first, ours after.
		$this->assertSame(
			array(
				'.well-known/api-catalog'     => 'api-catalog',
				'llms.txt'                    => 'service-doc',
				'.well-known/mcp.json'        => 'mcp',
				'.well-known/agent-card.json' => 'agent-card',
				'.well-known/ai-catalog.json' => 'ai-catalog',
			),
			LinkHeader::RELS
		);
	}

	/**
	 * Artefacts the API catalog does not list do not make it servable, so a
	 * bundle holding only those names nothing the header carries.
	 */
	public function test_the_api_catalog_is_not_named_without_one_of_its_targets(): void {
		$this->store[ ArtefactCache::OPTION ] = array(
			'files'   => array( 'schema.json' => '{}' ),
			'version' => 'v1',
		);
		$header                               = $this->spy( array( 'Content-Type: text/html; charset=UTF-8' ) );

		$header->maybe_send();

		$this->assertSame( array(), $header->sent );
	}

	public function test_llms_txt_alone_names_the_catalog_and_itself(): void {
		$this->store[ ArtefactCache::OPTION ] = array(
			'files'   => array( 'llms.txt' => '# Acme' ),
			'version' => 'v1',
		);
		$header                               = $this->spy( array( 'Content-Type: text/html; charset=UTF-8' ) );

		$header->maybe_send();

		$this->assertSame(
			array( array( 'Link: </.well-known/api-catalog>; rel="api-catalog", </llms.txt>; rel="service-doc"', false ) ),
			$header->sent
		);
	}

	public function test_it_hooks_template_redirect(): void {
		$header = new LinkHeader( new ArtefactCache() );

		$header->register();

		$this->assertSame( LinkHeader::PRIORITY, has_action( 'template_redirect', array( $header, 'maybe_send' ) ) );
	}

	/**
	 * Every artefact the header can name, cached.
	 */
	private function cache_full_bundle(): void {
		$this->store[ ArtefactCache::OPTION ] = array(
			'files'   => array(
				'llms.txt'                    => '# Acme',
				'.well-known/mcp.json'        => '{"m":1}',
				'.well-known/agent-card.json' => '{"c":2}',
				'.well-known/ai-catalog.json' => '{"specVersion":"1.0"}',
			),
			'version' => 'v1',
		);
	}

	/**
	 * A LinkHeader that records what it would send instead of calling header().
	 *
	 * @param string[] $already_sent Header lines already queued for the response.
	 * @param int      $status       Response status so far.
	 */
	private function spy( array $already_sent, int $status = 200 ): LinkHeader {
		return new class( new ArtefactCache(), $already_sent, $status ) extends LinkHeader {
			/**
			 * @var array<int, array{0:string,1:bool}>
			 */
			public array $sent = array();

			/**
			 * @var string[]
			 */
			private array $already_sent;

			private int $status;

			/**
			 * @param string[] $already_sent Header lines already queued.
			 */
			public function __construct( ArtefactCache $cache, array $already_sent, int $status ) {
				parent::__construct( $cache );
				$this->already_sent = $already_sent;
				$this->status       = $status;
			}

			protected function status(): int {
				return $this->status;
			}

			protected function headers_so_far(): array {
				return $this->already_sent;
			}

			protected function send_header( string $line, bool $replace ): void {
				$this->sent[] = array( $line, $replace );
			}
		};
	}
}
