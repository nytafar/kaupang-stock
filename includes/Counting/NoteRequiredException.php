<?php
declare(strict_types=1);

namespace Kaupang\Stock\Counting;

defined('ABSPATH') || exit;

/**
 * Thrown by CountLines when clearing in review would remove a line flagged for
 * recount without the note the override path requires. Nothing was written.
 */
final class NoteRequiredException extends \RuntimeException {
}
