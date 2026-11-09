<?php

namespace App\Site;

use Symfony\Component\Form\FormInterface;

/** Pour les contrôleurs d'inscription, qui portent un logger du canal payment. */
trait LogsInvalidSubmit
{
    /** Journalise les seuls noms de champs refusés, jamais leur contenu. */
    private function logInvalidSubmit(FormInterface $form, string $message): void
    {
        if (!$form->isSubmitted() || $form->isValid()) {
            return;
        }

        $fields = \count($form->getErrors()) > 0 ? ['_form'] : [];
        foreach ($form as $name => $child) {
            if (!$child->isValid()) {
                $fields[] = $name;
            }
        }

        $this->logger->info($message, ['fields' => $fields]);
    }
}
