<?php

namespace App\Support\Audit;

use Attribute;

/**
 * This route's action deliberately records nothing, and why. Writes made
 * while staff view as someone are still noted for us (TrackViewAs).
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD)]
final class NotAudited
{
    public function __construct(public string $why) {}
}
