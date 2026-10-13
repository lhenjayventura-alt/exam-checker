<?php

namespace App\Policies;

use App\Models\Exam;
use App\Models\User;

/**
 * Viewing is open to the assigned teacher, the program chair and administrators.
 * Every grading action (questions, submissions, review, finalize, release) is limited to
 * teachers assigned to the exam's course offering. Program chairs and administrators do
 * not receive score-changing rights by default.
 */
class ExamPolicy
{
    public function view(User $user, Exam $exam): bool
    {
        $offering = $exam->offering;

        return $user->isAdmin() || $offering->isTaughtBy($user) || $offering->isInProgramChairedBy($user);
    }

    public function manage(User $user, Exam $exam): bool
    {
        return $exam->offering->isTaughtBy($user);
    }

    /** Access to answer keys and grading decisions. */
    public function grade(User $user, Exam $exam): bool
    {
        return $this->manage($user, $exam);
    }
}
