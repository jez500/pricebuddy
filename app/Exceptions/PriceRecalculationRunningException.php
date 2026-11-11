<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * A store's price adjustments cannot change while its saved prices are being
 * recalculated. The message says how long it has been running.
 */
class PriceRecalculationRunningException extends RuntimeException {}
