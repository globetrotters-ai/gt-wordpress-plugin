<?php
/**
 * How the robots.txt block treats the AI agents it names.
 *
 * @package Globetrotters\AiPresence
 */

declare(strict_types=1);

namespace Globetrotters\AiPresence\Settings;

/**
 * The two robots.txt opt-ins, as {@see \Globetrotters\AiPresence\Serving\RobotsFilter}
 * reads them.
 *
 * Both defaults leave what a crawler may fetch exactly as the site already had
 * it. Anything broader is a decision about the customer's whole site, not about
 * the presence, so it is theirs to opt into. Mirrors the Symfony bundle's
 * ``robots.ai_agents`` / ``robots.ai_train`` config.
 */
final class RobotsOptions {

	/**
	 * Each named agent inherits the site's own ``User-agent: *`` rules.
	 */
	public const INHERIT = 'inherit';

	/**
	 * Each named agent gets ``Allow: /``, overriding the site's wildcard rules for it.
	 */
	public const ALLOW_ALL = 'allow_all';

	private string $ai_agents;
	private bool $ai_train;

	/**
	 * Build the options.
	 *
	 * @param string $ai_agents {@see self::INHERIT} or {@see self::ALLOW_ALL}.
	 * @param bool   $ai_train  Whether ``Content-Signal`` declares ``ai-train=yes``.
	 */
	public function __construct( string $ai_agents = self::INHERIT, bool $ai_train = false ) {
		$this->ai_agents = $ai_agents;
		$this->ai_train  = $ai_train;
	}

	/**
	 * The accepted ``ai_agents`` values.
	 *
	 * @return string[]
	 */
	public static function agent_modes(): array {
		return array( self::INHERIT, self::ALLOW_ALL );
	}

	/**
	 * Whether every named agent gets ``Allow: /``.
	 */
	public function allows_all(): bool {
		return self::ALLOW_ALL === $this->ai_agents;
	}

	/**
	 * Whether ``Content-Signal`` declares ``ai-train=yes``.
	 */
	public function ai_train(): bool {
		return $this->ai_train;
	}
}
