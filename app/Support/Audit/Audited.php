<?php

namespace App\Support\Audit;

use Attribute;

/**
 * This route's action leaves an entry in workspace Activity or the admin
 * log when it changes something. The route coverage test makes every
 * write route say this or #[NotAudited], and in tests a successful request
 * to one that recorded nothing fails.
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD)]
final class Audited {}
