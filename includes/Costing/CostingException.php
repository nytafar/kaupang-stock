<?php
declare(strict_types=1);

namespace Kaupang\Stock\Costing;

/** Costing-module failures (mirrors LedgerException; never leaks into the sale path). */
final class CostingException extends \RuntimeException {
}
