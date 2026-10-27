<?php
/**
 * Mutual exclusion between refreshes.
 *
 * @package Globetrotters\AiPresence
 */

declare(strict_types=1);

namespace Globetrotters\AiPresence\Sync;

/**
 * One refresh at a time, across every process on the install.
 */
interface RefreshLock {

	/**
	 * Take the lock. False when another refresh holds it.
	 */
	public function acquire(): bool;

	/**
	 * Whether this process still holds the lock it took.
	 *
	 * False once a holder outlived the lock's lifetime and another process
	 * reclaimed it: that one is pulling now, and a late result must not be
	 * published over it.
	 */
	public function still_held(): bool;

	/**
	 * Give back a lock this process took.
	 */
	public function release(): void;
}
