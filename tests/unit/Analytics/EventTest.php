<?php
/**
 * @package Globetrotters\AiPresence\Tests
 */

declare(strict_types=1);

namespace Globetrotters\AiPresence\Tests\Unit\Analytics;

use Globetrotters\AiPresence\Analytics\Event;
use Globetrotters\AiPresence\Tests\TestCase;

/**
 * @covers \Globetrotters\AiPresence\Analytics\Event
 */
final class EventTest extends TestCase {

	public function test_string_fields_are_clipped_to_the_contract_limit(): void {
		$event = new Event(
			'uuid',
			'2026-08-10T09:14:22Z',
			'/llms.txt',
			str_repeat( 'u', 2000 ),
			'160.79.104.10',
			str_repeat( 'r', 2000 ),
			200,
			4211
		);

		$this->assertSame( Event::MAX_FIELD_CHARS, strlen( $event->ua() ) );
		$this->assertSame( Event::MAX_FIELD_CHARS, strlen( $event->referer() ) );
	}

	public function test_empty_optional_fields_go_out_as_null(): void {
		$payload = $this->event()->to_payload();

		$this->assertNull( $payload['referer'] );
		$this->assertSame( '/llms.txt', $payload['path'] );
		$this->assertSame( 200, $payload['status'] );
		$this->assertSame( 4211, $payload['bytes'] );
	}

	public function test_round_trips_through_a_buffer_row(): void {
		$row = $this->event()->to_row();

		$restored = Event::from_row( $row );

		$this->assertSame( $this->event()->to_payload(), $restored->to_payload() );
	}

	public function test_size_estimate_tracks_the_encoded_footprint(): void {
		$event = $this->event();

		// The estimate exists to keep the 512KB bound cheap, so it must not
		// under-report what the event actually costs on the wire.
		$this->assertGreaterThanOrEqual(
			strlen( (string) json_encode( $event->to_payload() ) ),
			$event->size_bytes()
		);
	}

	private function event(): Event {
		return new Event(
			'9f2c8a1e-5f3b-4a6d-9c2e-7b1d0f4a8c33',
			'2026-08-10T09:14:22Z',
			'/llms.txt',
			'Mozilla/5.0 (compatible; ClaudeBot/1.0; +claudebot@anthropic.com)',
			'160.79.104.10',
			'',
			200,
			4211
		);
	}

	public function test_a_site_event_round_trips_through_a_buffer_row_without_an_ip(): void {
		$event = Event::site( 'id-1', '2026-10-15T10:00:00Z', '/a', 'GPTBot/1.1', 301, null, false, 'ignored' );
		$row   = $event->to_row();

		$this->assertNull( $row['ip'] );
		$this->assertNull( $row['bytes'] );
		$this->assertSame( 'site', $row['kind'] );
		$this->assertSame( 0, $row['verified'] );
		$this->assertNull( $row['vendor'], 'A vendor only rides with verified=true.' );

		$back = Event::from_row( $row + array( 'id' => 7 ) );
		$this->assertTrue( $back->is_site() );
		$this->assertSame( $event->to_payload(), $back->to_payload() );
		$this->assertSame( array( 'id', 'ts', 'path', 'ua', 'status', 'bytes', 'verified', 'vendor' ), array_keys( $back->to_payload() ) );
	}

	/**
	 * @dataProvider signed_events
	 *
	 * @param Event $event Event to sign.
	 */
	public function test_a_signature_round_trips_through_a_buffer_row_on_either_lane( Event $event ): void {
		$signed = $event->with_signature( self::signature() );
		$row    = $signed->to_row();

		$this->assertSame( 'signature', array_key_last( $row ), 'The schema-5 column comes last.' );
		$this->assertNull( $event->signature(), 'with_signature() returns a copy.' );
		$this->assertSame( self::signature(), Event::from_row( $row )->to_payload()['signature'] );
		$this->assertSame( $signed->to_payload(), Event::from_row( $row )->to_payload() );
		$this->assertGreaterThanOrEqual( strlen( (string) json_encode( $signed->to_payload() ) ), $signed->size_bytes() );
	}

	/**
	 * @return array<string, array{0:Event}>
	 */
	public static function signed_events(): array {
		return array(
			'artefact' => array( new Event( 'a', '2026-10-31T10:00:00Z', '/llms.txt', 'Mozilla/5.0', '160.79.104.10', '', 200, 10 ) ),
			'site'     => array( Event::site( 's', '2026-10-31T10:00:00Z', '/a', 'Mozilla/5.0', 200, null, null, null ) ),
		);
	}

	/**
	 * @dataProvider unusable_signatures
	 *
	 * @param mixed $stored Stored column value.
	 */
	public function test_an_unusable_stored_signature_reads_as_none( $stored ): void {
		$row = ( new Event( 'a', '2026-10-31T10:00:00Z', '/llms.txt', 'ua', '', '', 200, 10 ) )->to_row();

		$row['signature'] = $stored;

		$this->assertArrayNotHasKey( 'signature', Event::from_row( $row )->to_payload() );
	}

	/**
	 * @return array<string, array{0:mixed}>
	 */
	public static function unusable_signatures(): array {
		return array(
			'null (schema 4 row)' => array( null ),
			'not json'            => array( 'nope' ),
			'missing member'      => array( '{"agent":"a","input":"i","value":"v"}' ),
			'non-string member'   => array( '{"agent":"a","input":"i","value":1,"authority":"h"}' ),
			'empty agent'         => array( '{"agent":"","input":"i","value":"v","authority":"h"}' ),
		);
	}

	/**
	 * @return array{agent:string, input:string, value:string, authority:string}
	 */
	private static function signature(): array {
		return array(
			'agent'     => '"https://www.meta.com"',
			'input'     => 'sig1=("@authority" "signature-agent");tag="web-bot-auth"',
			'value'     => 'sig1=:YWJj:',
			'authority' => 'visit-acme.example',
		);
	}

	public function test_a_non_ascii_signature_is_never_under_sized(): void {
		$signature          = self::signature();
		$signature['input'] = 'sig1=("@authority");keyid="' . str_repeat( 'é', 300 ) . '"';
		$event              = ( new Event( 'a', '2026-10-31T10:00:00Z', '/llms.txt', 'ua', '', '', 200, 10 ) )->with_signature( $signature );

		$this->assertGreaterThanOrEqual( strlen( (string) json_encode( $event->to_payload() ) ), $event->size_bytes() );
	}

	public function test_a_row_without_a_kind_is_an_artefact_event(): void {
		$event = Event::from_row(
			array(
				'event_id' => 'x',
				'ts'       => '2026-10-15T10:00:00Z',
				'path'     => '/llms.txt',
				'ua'       => 'ClaudeBot',
				'ip'       => '160.79.104.10',
				'status'   => 200,
				'bytes'    => 10,
			)
		);
		$this->assertFalse( $event->is_site() );
		$this->assertSame( '160.79.104.10', $event->to_payload()['ip'] );
	}
}
