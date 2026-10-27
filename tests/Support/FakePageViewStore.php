<?php
/**
 * In-memory page-view counter store for unit tests.
 *
 * @package Globetrotters\AiPresence\Tests
 */

declare(strict_types=1);

namespace Globetrotters\AiPresence\Tests\Support;

use Globetrotters\AiPresence\Analytics\PageViewStore;

/**
 * Array-backed {@see PageViewStore} with the table's semantics: one row per
 * ``(day, bucket, path)``, a record id assigned once and never replaced, and
 * rows removed only by id or by age.
 *
 * Every write is also logged verbatim in {@see $writes}, so a test can inspect
 * exactly what the counter endpoint asked to persist.
 */
final class FakePageViewStore implements PageViewStore {

	/**
	 * @var array<int, array{id:int, day:string, path:string, bucket:string, views:int, record_id:?string}>
	 */
	public array $rows = array();

	/**
	 * Every argument list passed to a write method, in call order.
	 *
	 * @var array<int, array<int, mixed>>
	 */
	public array $writes = array();

	/**
	 * Run inside {@see distinct_paths()}, to simulate a concurrent beacon
	 * landing between an increment and the cap check.
	 *
	 * @var callable|null
	 */
	public $on_distinct_paths = null;

	private int $next_id = 1;

	private int $next_uuid = 1;

	public function increment( string $day, string $path, string $bucket ): int {
		$this->writes[] = array( 'increment', $day, $path, $bucket );
		foreach ( $this->rows as $id => $row ) {
			if ( $row['day'] === $day && $row['path'] === $path && $row['bucket'] === $bucket ) {
				if ( null !== $row['record_id'] ) {
					return 0;
				}
				$this->rows[ $id ]['views']++;
				return 2;
			}
		}
		$id                = $this->next_id++;
		$this->rows[ $id ] = array(
			'id'        => $id,
			'day'       => $day,
			'path'      => $path,
			'bucket'    => $bucket,
			'views'     => 1,
			'record_id' => null,
		);
		return 1;
	}

	public function distinct_paths( string $day ): int {
		if ( null !== $this->on_distinct_paths ) {
			( $this->on_distinct_paths )( $this );
		}
		$paths = array();
		foreach ( $this->rows as $row ) {
			if ( $row['day'] === $day && self::OTHER_PATH !== $row['path'] ) {
				$paths[ $row['path'] ] = true;
			}
		}
		return count( $paths );
	}

	public function remove( string $day, string $path, string $bucket ): bool {
		$this->writes[] = array( 'remove', $day, $path, $bucket );
		foreach ( $this->rows as $id => $row ) {
			if ( $row['day'] === $day && $row['path'] === $path && $row['bucket'] === $bucket && null === $row['record_id'] && 1 === $row['views'] ) {
				unset( $this->rows[ $id ] );
				return true;
			}
		}
		return false;
	}

	public function seal( string $today, int $limit ): int {
		$sealed = 0;
		foreach ( $this->rows as $id => $row ) {
			if ( $sealed >= $limit ) {
				break;
			}
			if ( $row['day'] < $today && null === $row['record_id'] ) {
				$this->rows[ $id ]['record_id'] = sprintf( '00000000-0000-4000-8000-%012d', $this->next_uuid++ );
				$sealed++;
			}
		}
		return $sealed;
	}

	public function prune( string $oldest_day ): int {
		$removed = 0;
		foreach ( $this->rows as $id => $row ) {
			if ( $row['day'] < $oldest_day ) {
				unset( $this->rows[ $id ] );
				$removed++;
			}
		}
		return $removed;
	}

	public function sealed( int $limit ): array {
		$out = array();
		foreach ( $this->rows as $row ) {
			if ( count( $out ) >= $limit ) {
				break;
			}
			if ( null !== $row['record_id'] ) {
				$out[] = array(
					'id'        => $row['id'],
					'record_id' => $row['record_id'],
					'day'       => $row['day'],
					'path'      => $row['path'],
					'bucket'    => $row['bucket'],
					'views'     => $row['views'],
				);
			}
		}
		return $out;
	}

	public function sealed_count(): int {
		$count = 0;
		foreach ( $this->rows as $row ) {
			if ( null !== $row['record_id'] ) {
				$count++;
			}
		}
		return $count;
	}

	public function delete( array $ids ): int {
		$removed = 0;
		foreach ( $ids as $id ) {
			if ( isset( $this->rows[ (int) $id ] ) ) {
				unset( $this->rows[ (int) $id ] );
				$removed++;
			}
		}
		return $removed;
	}

	/**
	 * Seed a row directly, as a previous day's traffic would have left it.
	 *
	 * @param string $day    UTC day.
	 * @param string $path   Page path.
	 * @param string $bucket UA bucket.
	 * @param int    $views  Count.
	 */
	public function seed( string $day, string $path, string $bucket = 'browser', int $views = 1 ): void {
		$id                = $this->next_id++;
		$this->rows[ $id ] = array(
			'id'        => $id,
			'day'       => $day,
			'path'      => $path,
			'bucket'    => $bucket,
			'views'     => $views,
			'record_id' => null,
		);
	}
}
