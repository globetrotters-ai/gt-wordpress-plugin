<?php
/**
 * Recording flush-request double for unit tests.
 *
 * @package Globetrotters\AiPresence\Tests
 */

declare(strict_types=1);

namespace Globetrotters\AiPresence\Tests\Support;

use Globetrotters\AiPresence\Analytics\FlushRequest;

/**
 * Counts early-flush requests so capture can be tested without WP-Cron.
 */
final class FakeFlushRequest implements FlushRequest {

	public int $requests = 0;

	public function request_flush(): void {
		$this->requests++;
	}
}
