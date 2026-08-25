<?php

namespace App\Tests\Service\Export;

use App\Service\Export\AnswerHumanizer;
use PHPUnit\Framework\TestCase;

final class AnswerHumanizerTest extends TestCase
{
    /** Réponses à choix multiples : lisibles dans un tableur, sans syntaxe JSON. */
    public function testMultipleChoiceAnswersAreJoined(): void
    {
        self::assertSame(
            'Analyse de données, Tableaux de bord & KPI',
            AnswerHumanizer::value(['Analyse de données', 'Tableaux de bord & KPI']),
        );
        self::assertSame('', AnswerHumanizer::value([]));
    }

    /** Les codes internes restent traduits, y compris dans une liste. */
    public function testCodesAreTranslated(): void
    {
        self::assertSame('Oui', AnswerHumanizer::value('oui'));
        self::assertSame('Coopérateur', AnswerHumanizer::value('cooperateur'));
        self::assertSame('Salarié, Travailleur non salarié', AnswerHumanizer::value(['salarie', 'tns']));
        self::assertSame('Non', AnswerHumanizer::value(false));
    }

    public function testKnownKeysUseExplicitLabels(): void
    {
        self::assertSame("Cas d'usage prioritaires", AnswerHumanizer::key('priorityUseCases'));
        self::assertSame('Licence Claude pour les ateliers', AnswerHumanizer::key('claudeLicense'));
    }

    /** Une clé inconnue reste affichable sans modification de code. */
    public function testUnknownKeyFallsBackToAutomaticLabel(): void
    {
        self::assertSame('Nouvelle question libre', AnswerHumanizer::key('nouvelleQuestionLibre'));
    }
}
