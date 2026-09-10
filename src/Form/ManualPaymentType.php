<?php

namespace App\Form;

use App\Entity\PaymentMethod;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Constat manuel du paiement d'une inscription que Stripe n'a jamais
 * confirmée (voir RegistrationCrudController::manualPayment). Une seule
 * question : l'argent est-il déjà là ? La réponse décide de l'état de la
 * facture émise - acquittée, ou à régler avec les coordonnées bancaires.
 *
 * Rien n'est définitif : une facture non acquittée le devient dès que le
 * règlement est constaté, depuis ce même écran.
 *
 * Le montant n'est pas saisissable : c'est celui de l'inscription, sinon la
 * facture émise ne correspondrait plus au tarif choisi.
 *
 * Les champs sans objet pour l'issue choisie sont masqués par le gabarit ; le
 * contrôleur, lui, ne lit que ceux qui la concernent - une saisie laissée dans
 * un champ masqué n'a donc jamais d'effet.
 */
final class ManualPaymentType extends AbstractType
{
    /** Règlement encore attendu : facture émise non acquittée, inscription toujours non payée. */
    public const string OUTCOME_PENDING = 'pending';

    /** Règlement reçu hors Stripe : inscription confirmée et facture acquittée. */
    public const string OUTCOME_PAID = 'paid';

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('outcome', ChoiceType::class, [
                'label' => 'Où en est le règlement ?',
                // Libellés courts : sur deux lignes, le bouton radio se
                // retrouve au-dessus du texte et la liste devient illisible.
                // Ce qu'ils impliquent est expliqué juste en dessous.
                'choices' => [
                    'Déjà reçu' => self::OUTCOME_PAID,
                    'Attendu' => self::OUTCOME_PENDING,
                ],
                'expanded' => true,
                'data' => self::OUTCOME_PAID,
                'help' => $options['invoicing']
                    ? "« Déjà reçu » génère la facture acquittée. « Attendu » (virement annoncé, chèque en route) la génère non acquittée, avec les coordonnées bancaires : elle deviendra acquittée dès que vous reviendrez constater le règlement, sans changer de numéro."
                    : "« Déjà reçu » confirme l'inscription. « Attendu » (virement annoncé, chèque en route) la laisse en attente : revenez constater le règlement dès qu'il arrive.",
            ])
            ->add('method', EnumType::class, [
                'class' => PaymentMethod::class,
                'label' => 'Moyen de règlement',
                // La carte est exclue : elle ne peut venir que de Stripe (voir
                // PaymentMethod::manualChoices et ManualPaymentRecorder).
                'choices' => PaymentMethod::manualChoices(),
                'choice_label' => static fn (PaymentMethod $method) => $method->label(),
                'data' => PaymentMethod::TRANSFER,
                'help' => 'Imprimé sur la facture, en mode de règlement.',
            ])
            ->add('paidAt', DateType::class, [
                'label' => 'Date de réception du règlement',
                'widget' => 'single_text',
                'input' => 'datetime_immutable',
                'required' => false,
                'help' => "Date portée sur la facture : celle où l'argent a été reçu, pas celle de la saisie.",
                'constraints' => [
                    new Assert\NotNull(message: 'Indiquez la date de réception du règlement.', groups: ['paid']),
                    new Assert\LessThanOrEqual(value: 'today', message: 'Un règlement ne peut pas être reçu dans le futur.', groups: ['paid']),
                ],
            ])
            ->add('reference', TextType::class, [
                'label' => 'Référence ou motif',
                'required' => false,
                'help' => "N° de chèque, libellé du virement… Repris sur la facture, à côté du moyen de règlement.",
                'constraints' => [new Assert\Length(max: 190)],
            ])
            ->add('notifyParticipant', CheckboxType::class, [
                'label' => 'Envoyer le document au participant',
                'required' => false,
                'data' => true,
                'help' => "Facture à régler quand le règlement est attendu, confirmation d'inscription quand il est constaté. Décochez si la personne a déjà été prévenue : la facture est émise quand même et reste téléchargeable ici.",
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            // Pas de data_class : les valeurs saisies sont passées telles quelles
            // à ManualPaymentRecorder, qui construit le paiement.
            'data_class' => null,
            // Site sans facturation : parler d'acquittement n'aurait aucun sens,
            // aucune facture n'y est jamais émise (voir Site::invoicingEnabled).
            'invoicing' => true,
            // Moyen et date de règlement n'ont de sens que si l'argent est
            // arrivé : les exiger sur un classement en non-payé bloquerait une
            // saisie parfaitement légitime.
            'validation_groups' => static fn (FormInterface $form) => self::OUTCOME_PAID === $form->get('outcome')->getData()
                ? ['Default', 'paid']
                : ['Default'],
        ]);

        $resolver->setAllowedTypes('invoicing', 'bool');
    }
}
