<?php

namespace Simplixi\SUPCheckout\Tests\Takeover;

use Simplixi\SUPCheckout\Takeover\BudgetedSystem;

/**
 * FakeCutoverSystem with a time budget (0c Task 3): Rollback and Finalize yield instead of
 * sleeping. `$spent` is the budget_spent() answer, or a callable returning it.
 */
final class FakeBudgetedSystem extends FakeCutoverSystem implements BudgetedSystem {
    /** @var bool|callable function (): bool */
    public $spent = false;
    /** @var int Times budget_spent() was asked. */
    public $budget_checks = 0;

    public function budget_spent() {
        ++$this->budget_checks;
        return is_callable($this->spent) ? (bool) call_user_func($this->spent) : (bool) $this->spent;
    }
}
