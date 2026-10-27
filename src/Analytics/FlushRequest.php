<?php
/**
 * Contract for asking that a flush happen sooner than the recurring schedule.
 *
 * @package Globetrotters\AiPresence
 */

declare(strict_types=1);

namespace Globetrotters\AiPresence\Analytics;

/**
 * The seam between capture and scheduling.
 *
 * Narrow on purpose: {@see EventRecorder} runs on the serve path and has no
 * business knowing about WP-Cron, and keeping the dependency to one method lets
 * the recorder be unit tested without a cron double.
 */
interface FlushRequest {

	/**
	 * Ask for a flush at the earliest opportunity.
	 *
	 * Implementations must be cheap, idempotent, and must never perform the
	 * flush inline — the caller is mid-request on a served artefact.
	 */
	public function request_flush(): void;
}
