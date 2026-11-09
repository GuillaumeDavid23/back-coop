<?php

namespace App\Tests\Service\Billing;

use App\Entity\RegistrationStatus;
use App\Entity\Site;
use App\Form\ManualRegistrationType;
use App\Service\Billing\ManualRegistrationCreator;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Form\Extension\Validator\ValidatorExtension;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\Forms;
use Symfony\Component\Validator\Validation;

/**
 * Inscription saisie depuis le BO : le prix vient de la grille du site, les
 * questions sont celles du formulaire public, et la saisie rapide les diffère.
 */
final class ManualRegistrationCreatorTest extends TestCase
{
    private const array IDENTITY = [
        'civility' => 'mme',
        'firstName' => 'Marie',
        'lastName' => 'Durand',
        'email' => 'marie@example.test',
    ];

    public function testQuickRegistrationIsPricedByTheSiteGridAndAwaitsManualPayment(): void
    {
        $registration = $this->creator()->create($this->site('seminaire_cac'), self::IDENTITY + ['fare' => 'non_cooperateur'], [], 'admin@example.test');

        self::assertSame('Non coopérateur', $registration->getFareLabel());
        self::assertSame('950.00', $registration->getAmountExclTax());
        self::assertSame('1140.00', $registration->getAmountInclTax());
        self::assertSame(RegistrationStatus::PENDING, $registration->getStatus());
        self::assertTrue($registration->isManuallyHandled());
        self::assertTrue($registration->isFormIncomplete());
        self::assertSame([], $registration->getAnswers());
    }

    public function testCompletingKeepsThePriceAndStoresTheAnswers(): void
    {
        $creator = $this->creator();
        $registration = $creator->create($this->site('seminaire_ia'), self::IDENTITY + ['fare' => 'sans_heb', 'statut' => 'cooperateur', 'eveningGuests' => 1], [], 'admin@example.test');

        self::assertSame('600.00', $registration->getAmountExclTax());
        self::assertTrue($creator->canComplete($registration));

        $creator->complete($registration, [
            ...$creator->prefill($registration)['registration'],
            'category' => 'salarie',
            'motivation' => 'Monter en compétence',
        ], ['aiLevel' => 'Je découvre'], 'admin@example.test');

        self::assertFalse($registration->isFormIncomplete());
        self::assertFalse($creator->canComplete($registration));
        self::assertSame('600.00', $registration->getAmountExclTax());
        self::assertSame('Je découvre', $registration->getAnswers()['aiLevel']);
        self::assertSame('cooperateur', $registration->getAnswers()['statut']);
        self::assertSame(1, $registration->getAnswers()['eveningGuests']);
    }

    public function testTwoPersonFareCreatesTheSecondParticipant(): void
    {
        $registration = $this->creator()->create($this->site('seminaire_ia'), self::IDENTITY + [
            'fare' => 'heb_1ec_acc',
            'statut' => 'non_cooperateur',
            'civility2' => 'm',
            'firstName2' => 'Paul',
            'lastName2' => 'Durand',
        ], [], 'admin@example.test');

        self::assertCount(2, $registration->getParticipants());
        self::assertSame('Marie Durand, Paul Durand', $registration->getParticipantsFullNames());
    }

    public function testCompleteModeKeepsTheSiteQuestions(): void
    {
        $form = $this->factory()->create(ManualRegistrationType::class, null, ['site_code' => 'coop_ia_tour']);

        self::assertTrue($form->get('registration')->has('motivation'));
        self::assertTrue($form->get('registration')->has('cocktailAttendance'));
        self::assertTrue($form->get('questionnaire')->has('aiLevel'));
    }

    public function testQuickFormAcceptsOnlyTheEssentials(): void
    {
        $form = $this->factory()->create(ManualRegistrationType::class, null, ['site_code' => 'coop_ia_tour', 'mode' => ManualRegistrationType::MODE_QUICK]);
        $form->submit(['registration' => self::IDENTITY + ['fare' => 'cooperateur']]);

        self::assertFalse($form->has('questionnaire'));
        self::assertFalse($form->get('registration')->has('motivation'));
        self::assertTrue($form->isValid(), (string) $form->getErrors(true));
    }

    public function testQuickFormStillRequiresTheIdentity(): void
    {
        $form = $this->factory()->create(ManualRegistrationType::class, null, ['site_code' => 'seminaire_cac', 'mode' => ManualRegistrationType::MODE_QUICK]);
        $form->submit(['registration' => ['fare' => 'cooperateur', 'civility' => 'm']]);

        self::assertFalse($form->isValid());
        self::assertFalse($form->get('registration')->get('email')->isValid());
    }

    public function testQuickFormOnSeminaireIaRequiresTheCompanionButNotTheMembershipNumber(): void
    {
        $data = self::IDENTITY + ['fare' => 'heb_1ec_acc', 'statut' => 'cooperateur'];

        $missing = $this->factory()->create(ManualRegistrationType::class, null, ['site_code' => 'seminaire_ia', 'mode' => ManualRegistrationType::MODE_QUICK]);
        $missing->submit(['registration' => $data]);
        self::assertFalse($missing->get('registration')->get('lastName2')->isValid());

        $complete = $this->factory()->create(ManualRegistrationType::class, null, ['site_code' => 'seminaire_ia', 'mode' => ManualRegistrationType::MODE_QUICK]);
        $complete->submit(['registration' => $data + ['civility2' => 'm', 'firstName2' => 'Paul', 'lastName2' => 'Durand']]);
        self::assertTrue($complete->isValid(), (string) $complete->getErrors(true));
    }

    private function creator(): ManualRegistrationCreator
    {
        return new ManualRegistrationCreator($this->createStub(EntityManagerInterface::class), new NullLogger());
    }

    private function site(string $code): Site
    {
        $site = $this->createStub(Site::class);
        $site->method('getCode')->willReturn($code);

        return $site;
    }

    private function factory(): FormFactoryInterface
    {
        return Forms::createFormFactoryBuilder()
            ->addExtension(new ValidatorExtension(Validation::createValidator()))
            ->getFormFactory();
    }
}
