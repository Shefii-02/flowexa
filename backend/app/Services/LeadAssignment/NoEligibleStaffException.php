<?php

namespace App\Services\LeadAssignment;

/** A manual "Auto assign" found nobody who can take the lead. Thrown inside the engine's transaction so nothing is left half-done (no AI hand-off, no orphan assignment). */
class NoEligibleStaffException extends \RuntimeException {}
