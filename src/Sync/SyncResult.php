<?php
/**
 * Outcome of an artefact sync run.
 *
 * @package Globetrotters\AiPresence
 */

declare(strict_types=1);

namespace Globetrotters\AiPresence\Sync;

/**
 * Immutable value object describing one sync run.
 */
final class SyncResult {

	private bool $success;
	private bool $changed;
	private string $version;

	/**
	 * Human-readable error messages.
	 *
	 * @var string[]
	 */
	private array $errors;

	private bool $busy;

	/**
	 * Record a sync outcome.
	 *
	 * @param bool     $success Whether a complete bundle was pulled and stored.
	 * @param bool     $changed Whether the stored version changed.
	 * @param string   $version The resulting bundle version.
	 * @param string[] $errors  Human-readable error messages, if any.
	 * @param bool     $busy    Whether the run stood down for one already in progress.
	 */
	public function __construct( bool $success, bool $changed, string $version, array $errors, bool $busy = false ) {
		$this->success = $success;
		$this->changed = $changed;
		$this->version = $version;
		$this->errors  = $errors;
		$this->busy    = $busy;
	}

	/**
	 * Whether another refresh held the lock, so this one fetched nothing.
	 */
	public function is_busy(): bool {
		return $this->busy;
	}

	public function is_success(): bool {
		return $this->success;
	}

	public function has_changed(): bool {
		return $this->changed;
	}

	public function version(): string {
		return $this->version;
	}

	/**
	 * The error messages, if any.
	 *
	 * @return string[]
	 */
	public function errors(): array {
		return $this->errors;
	}

	/**
	 * A single joined error message (empty when the run succeeded cleanly).
	 */
	public function error_message(): string {
		return implode( '; ', $this->errors );
	}
}
