<?php

return [
    'move' => [
        'no-journal-found-title'      => 'La comptabilité n\'est pas configurée',
        'no-journal-found'            => 'Aucun journal n\'a été trouvé dans la société :company pour l\'un des types suivants : :types. Veuillez d\'abord configurer un plan comptable pour cette société.',
        'cancel-draft-only'           => 'Seules les écritures au brouillon peuvent être annulées.',
        'reset-posted-cancelled-only' => 'Seules les écritures comptabilisées ou annulées peuvent être remises au brouillon.',
        'reset-exchange-difference'   => 'Cet ajustement de devise ne peut pas être remis directement au brouillon. Retirez d’abord le rapprochement de paiement associé.',
        'posting-identity-changed'    => 'L’écriture ou la source de sa contrepassation a changé. Rechargez-la avant de la comptabiliser.',
        'completed-bank-period'       => 'Cette opération concerne une période de rapprochement bancaire terminée. Rouvrez d’abord le rapprochement.',
        'bank-period-busy'            => 'Une opération comptable ou une clôture de période bancaire est en cours. Actualisez et réessayez.',
    ],

    'reconciliation' => [
        'unavailable'        => 'Les écritures comptables ne sont plus disponibles. Actualisez et réessayez.',
        'changed'            => 'Le rapprochement a changé pendant l’attente de cette action. Actualisez et réessayez.',
        'bank-match'         => 'Retirez le rapprochement bancaire avant de modifier ces écritures.',
        'already-reconciled' => 'Certaines écritures sont déjà rapprochées. Actualisez la page et réessayez.',
    ],

    'tax-formula' => [
        'empty'                => 'La formule ne peut pas être vide.',
        'invalid-character'    => 'La formule contient un caractère invalide : :character',
        'unexpected-token'     => 'La formule contient un jeton inattendu : :token',
        'unexpected-end'       => 'La formule se termine de manière inattendue.',
        'unclosed-parenthesis' => 'La formule contient une parenthèse non fermée.',
        'unknown-variable'     => 'Variable inconnue « :variable ». Seules ces variables sont disponibles : :variables',
        'unknown-function'     => 'Fonction inconnue « :function ». Seules ces fonctions sont disponibles : :functions',
    ],

    'payment-registration' => [
        'connection-mismatch'       => 'Tous les enregistrements doivent utiliser la connexion à la base de données Comptabilité pour enregistrer le paiement.',
        'unsaved-instruction'       => 'Enregistrez l’instruction de paiement avant de traiter le paiement.',
        'missing-instruction'       => 'L’instruction de paiement n’existe pas.',
        'instruction-company'       => 'L’instruction de paiement ne concerne pas l’entreprise active.',
        'changed-instruction'       => 'L’instruction de paiement a changé. Rechargez-la avant de traiter le paiement.',
        'completed-conflict'        => 'L’instruction de paiement ne correspond pas à son enregistrement terminé.',
        'unverified-instruction'    => 'L’instruction de paiement historique n’a pas de résultat vérifié. Rapprochez-la avant de réessayer.',
        'missing-source-lines'      => 'L’instruction de paiement n’a aucune ligne source.',
        'ineligible-sources'        => 'Les sources du paiement ne sont plus admissibles pour cette entreprise.',
        'invalid-amount'            => 'Le montant du paiement doit être positif et ne pas dépasser le montant restant à la source.',
        'invalid-journal-method'    => 'Le journal et le mode de paiement doivent appartenir à l’entreprise de l’instruction.',
        'invalid-batch-amount'      => 'Le total des lots de paiement doit correspondre au montant demandé. L’affectation partielle des lots n’est pas prise en charge.',
        'mixed-batch-currencies'    => 'Les lots de paiement en devises différentes nécessitent des instructions distinctes.',
        'request-company'           => 'La demande de paiement ne concerne pas l’entreprise active.',
        'request-key-conflict'      => 'La clé de demande de paiement a déjà été utilisée pour une autre instruction.',
        'invalid-request-lines'     => 'Les lignes sources du paiement doivent appartenir à l’écriture et à l’entreprise demandées.',
        'request-argument-conflict' => 'Les paramètres de la demande de paiement ne correspondent pas à l’instruction terminée.',
        'invalid-request-key'       => 'La clé de demande de paiement doit contenir de 1 à 128 lettres ASCII, chiffres, points, deux-points, traits de soulignement ou traits d’union.',
    ],

    'payment' => [
        'unavailable'                  => 'Le paiement n’est plus disponible. Actualisez et réessayez.',
        'company'                      => 'Passez à l’entreprise du paiement pour continuer.',
        'changed'                      => 'Le paiement ou son écriture a changé pendant l’attente. Actualisez et réessayez.',
        'journal-mismatch'             => 'L’écriture du paiement est mal associée. Demandez au responsable comptable de la vérifier.',
        'cancel-draft-only'            => 'Seuls les paiements au brouillon peuvent être annulés.',
        'reset-non-draft-only'         => 'Ce paiement est déjà au brouillon.',
        'post-draft-process-paid-only' => 'Ce paiement ne peut pas être confirmé dans son état actuel. Actualisez et vérifiez son statut.',
        'bank-validation'              => 'Pour enregistrer des paiements avec :method, validez d’abord le compte bancaire du bénéficiaire :partner.',
    ],

    'payment-reversal' => [
        'posted-only' => 'Seules les écritures comptabilisées peuvent être contrepassées.',
        'paid-cancel' => 'Une écriture payée ne peut pas être contrepassée et annulée. Émettez un avoir ou un remboursement.',
    ],
];
