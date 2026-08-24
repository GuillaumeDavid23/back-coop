<?php

namespace App\Twig;

use App\Service\Export\AnswerHumanizer;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

final class HumanizeExtension extends AbstractExtension
{
    public function getFilters(): array
    {
        return [
            new TwigFilter('answer_key', AnswerHumanizer::key(...)),
            new TwigFilter('answer_value', AnswerHumanizer::value(...)),
            new TwigFilter('civility_label', AnswerHumanizer::civility(...)),
            new TwigFilter('participant_status_label', AnswerHumanizer::participantStatus(...)),
            new TwigFilter('registration_status_label', AnswerHumanizer::registrationStatus(...)),
            new TwigFilter('payment_status_label', AnswerHumanizer::paymentStatus(...)),
        ];
    }
}
