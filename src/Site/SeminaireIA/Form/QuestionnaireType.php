<?php

namespace App\Site\SeminaireIA\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Questionnaire préalable à l'inscription : sert à calibrer les ateliers
 * pratiques (niveau des participants, outils déjà en place, licences Claude à
 * prévoir). Les réponses sont stockées telles quelles dans Registration::answers.
 *
 * Les valeurs enregistrées sont les libellés eux-mêmes, et non des codes : ces
 * réponses ne sont jamais interprétées par l'application, seulement relues dans
 * l'export et le back-office. Cela évite une table de correspondance de plus de
 * trente entrées dans AnswerHumanizer pour un gain nul.
 */
final class QuestionnaireType extends AbstractType
{
    /** @param list<string> $labels @return array<string, string> */
    private static function choices(array $labels): array
    {
        return array_combine($labels, $labels);
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            // --- Niveau ---
            ->add('aiLevel', ChoiceType::class, [
                'label' => "Comment évaluez-vous votre niveau d'utilisation de l'IA aujourd'hui ?",
                'choices' => self::choices([
                    "Jamais utilisé",
                    'Je découvre',
                    "J'utilise ponctuellement",
                    "J'utilise régulièrement et je crée déjà des outils",
                ]),
                'placeholder' => '-',
                'constraints' => [new Assert\NotBlank()],
            ])
            ->add('aiFrequency', ChoiceType::class, [
                'label' => "À quelle fréquence utilisez-vous une IA générative dans votre travail ?",
                'choices' => self::choices([
                    'Jamais',
                    'Quelques fois par mois',
                    'Chaque semaine',
                    'Chaque jour',
                ]),
                'placeholder' => '-',
                'constraints' => [new Assert\NotBlank()],
            ])

            // --- Abonnement ou licence IA ---
            ->add('paidSubscription', ChoiceType::class, [
                'label' => "Disposez-vous d'un abonnement payant à une IA générative ?",
                'choices' => self::choices(['Non', 'Oui']),
                'placeholder' => '-',
                'constraints' => [new Assert\NotBlank()],
            ])
            ->add('paidSubscriptionTools', ChoiceType::class, [
                'label' => 'Si oui, le(s)quel(s) ?',
                'choices' => self::choices(['Claude', 'ChatGPT', 'Gemini', 'Copilot', 'Mistral', 'Autre']),
                'multiple' => true,
                'expanded' => true,
                'required' => false,
                'constraints' => [new Assert\Count(min: 1, minMessage: 'Merci de préciser au moins un abonnement.', groups: ['has_subscription'])],
            ])
            ->add('claudeUsage', ChoiceType::class, [
                'label' => 'Utilisez-vous déjà Claude au cabinet ?',
                'choices' => self::choices(['Non', 'Oui, version gratuite', 'Oui, version payante']),
                'placeholder' => '-',
                'constraints' => [new Assert\NotBlank()],
            ])
            ->add('claudeLicense', ChoiceType::class, [
                'label' => "Pour les ateliers, disposez-vous de votre propre licence Claude ou souhaitez-vous qu'on vous en mette une à disposition ?",
                'choices' => self::choices(["J'ai ma licence", "J'ai besoin qu'on m'en prête une"]),
                'placeholder' => '-',
                'constraints' => [new Assert\NotBlank()],
            ])

            // --- Outils & cas d'usage ---
            ->add('dailyTools', TextareaType::class, [
                'label' => 'Quels outils utilisez-vous au quotidien au cabinet ?',
                'help' => 'Logiciel de production, GED, Suite Office…',
                'constraints' => [new Assert\NotBlank()],
                'attr' => ['rows' => 2],
            ])
            ->add('priorityUseCases', ChoiceType::class, [
                'label' => "Quels cas d'usage aimeriez-vous aborder en priorité pendant la journée ?",
                'choices' => self::choices([
                    'Analyse de données',
                    'Rédaction de livrables',
                    'Préparation de RDV clients',
                    'Tableaux de bord & KPI',
                    'Bilans illustrés',
                    'Restitutions RH',
                    'Prévisionnels & business plans',
                    'Connexion de Claude à vos outils métiers',
                    'Autre',
                ]),
                'multiple' => true,
                'expanded' => true,
                'constraints' => [new Assert\Count(min: 1, minMessage: "Merci de sélectionner au moins un cas d'usage.")],
            ])
            ->add('toolsToBuild', TextareaType::class, [
                'label' => 'Quels outils souhaiteriez-vous développer pour votre cabinet ?',
                'constraints' => [new Assert\NotBlank()],
                'attr' => ['rows' => 2],
            ])
            ->add('timeConsumingTasks', TextareaType::class, [
                'label' => 'Sur quelles tâches perdez-vous le plus de temps aujourd\'hui ?',
                'constraints' => [new Assert\NotBlank()],
                'attr' => ['rows' => 2],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => null,
            // La question « Si oui, le(s)quel(s) ? » n'est exigée que lorsqu'un
            // abonnement payant est déclaré.
            'validation_groups' => static function (FormInterface $form): array {
                $data = $form->getData() ?? [];

                return 'Oui' === ($data['paidSubscription'] ?? null)
                    ? ['Default', 'has_subscription']
                    : ['Default'];
            },
        ]);
    }
}
