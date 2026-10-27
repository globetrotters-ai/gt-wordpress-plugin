<?php
/**
 * In-memory refresh lock for tests.
 *
 * @package Globetrotters\AiPresence\Tests
 */

declare(strict_types=1);

namespace Globetrotters\AiPresence\Tests\Support;

use Globetrotters\AiPresence\Sync\RefreshLock;

/**
 * A lock another process may already hold.
 */
final class FakeRefreshLock implements RefreshLock {

	/**
	 * Whether the lock is taken (by this process or another).
	 */
	public bool $held = false;

	public int $acquired = 0;

	public int $released = 0;

	public function acquire(): bool {
		if ( $this->held ) {
			return false;
		}
		$this->held = true;
		++$this->acquired;
		return true;
	}

	/**
	 * Set by a test to stand for a successor having reclaimed the lock.
	 */
	public bool $lost = false;

	public function still_held(): bool {
		return $this->held && ! $this->lost;
	}

	public function release(): void {
		$this->held = false;
		++$this->released;
	}
}
