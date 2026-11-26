<?php
/**
 * Core version, so a host can report which core it vendors.
 *
 * @package Ux1\AiCore
 */

declare(strict_types=1);

namespace Ux1\AiCore;

final class Version {

	public const VERSION = '0.4.0';

	/** Lowest PHP the core supports (UX1 Studio minimum; CA runs 8.2+). */
	public const MIN_PHP = '8.1.0';
}
