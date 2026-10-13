<?php

namespace App\Services\Grading;

use App\Models\Question;
use App\Models\RecognizedAnswer;

/**
 * A grader that needs the whole recognized answer (e.g. full source code and its origin)
 * rather than only the normalized value.
 */
interface AnswerAwareGrader extends QuestionGrader
{
    public function gradeAnswer(Question $question, ?RecognizedAnswer $answer): GradeOutcome;
}
