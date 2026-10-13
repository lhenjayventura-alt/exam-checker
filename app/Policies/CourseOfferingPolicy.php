<?php

namespace App\Policies;

use App\Models\CourseOffering;
use App\Models\User;

class CourseOfferingPolicy
{
    /** Assigned teachers, the chair of the course's program, and administrators. */
    public function view(User $user, CourseOffering $offering): bool
    {
        return $user->isAdmin() || $offering->isTaughtBy($user) || $offering->isInProgramChairedBy($user);
    }

    /** Roster changes: the assigned teacher or an administrator. */
    public function manageRoster(User $user, CourseOffering $offering): bool
    {
        return $user->isAdmin() || $offering->isTaughtBy($user);
    }

    /** Only an assigned teacher creates exams for the offering. */
    public function createExam(User $user, CourseOffering $offering): bool
    {
        return $offering->isTaughtBy($user);
    }
}
