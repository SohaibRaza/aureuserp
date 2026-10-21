<?php

return [
    'inventory-manager' => [
        'result-immutable'    => 'Le résultat enregistré d’un transfert ne peut être ni modifié ni supprimé.',
        'replay-conflict'     => 'Ce transfert est déjà terminé avec d’autres instructions. Consultez son résultat enregistré avant de continuer.',
        'legacy-terminal'     => 'Ce transfert est déjà clôturé, mais aucun résultat d’exécution n’est enregistré. Demandez au responsable des stocks de le vérifier.',
        'terminal-change'     => 'Les mouvements de stock terminés ou annulés ne peuvent être ni modifiés ni supprimés. Utilisez un retour ou une correction approuvée.',
        'canceled-completion' => 'Un mouvement de stock annulé ne peut pas être terminé. Créez un nouveau mouvement si le stock doit encore être déplacé.',
        'stock-not-ready'     => 'Le stock réservé n’est plus disponible à ce lieu de chargement. Recomptez les marchandises et mettez le chargement à jour avant la sortie de stock.',
        'invalid-counts'      => 'Vérifiez les quantités comptées et rechargez le transfert. Les quantités des lignes existantes doivent être finies et positives ou nulles.',

        'stale-operation' => 'Ce transfert a changé pendant votre travail. Rechargez-le et réessayez.',

        'check-availability' => [
            'no-moves' => 'Rien à vérifier pour la disponibilité.',
        ],

        'cancel-move' => [
            'already-done' => 'Vous ne pouvez pas annuler un mouvement de stock défini sur \'Terminé\'. Créez un retour afin d\'inverser les mouvements effectués.',
        ],

        'unreserve-move' => [
            'already-done' => 'Vous ne pouvez pas annuler la réservation d\'un mouvement de stock défini sur \'Terminé\'.',
        ],

        'validate' => [
            'quantity-rounding-mismatch' => 'La quantité effectuée pour le produit ":product" ne respecte pas la précision d\'arrondi définie sur l\'unité de mesure ":unit". Veuillez modifier la quantité effectuée ou la précision d\'arrondi de votre unité de mesure.',
            'no-negative-quantities'     => 'Les quantités négatives ne sont pas autorisées',
            'missing-lot-serial-number'  => "Vous devez fournir un numéro de lot/série pour le produit :\n:products",
        ],

        'run-procurement' => [
            'no-rule-found'      => "Aucune règle n'a été trouvée pour réapprovisionner \":product\" dans \":location\".\nVérifiez la configuration des routes sur le produit.",
            'no-source-location' => 'Aucun emplacement source défini sur la règle de stock : :name !',
            'no-vendor-price'    => 'Il n\'existe aucun prix fournisseur correspondant permettant de générer le bon de commande pour le produit :product (aucun fournisseur défini, quantité minimale non atteinte, dates non valides, ...). Rendez-vous sur la fiche produit et complétez la liste des fournisseurs.',
        ],

        'return' => [
            'origin'               => 'Retour de :operation_name',
            'completed-only'       => 'Seul un transfert terminé peut faire l’objet d’un retour.',
            'missing-source-moves' => 'Un retour nécessite des quantités issues de mouvements sources existants.',
            'positive-quantity'    => 'Les quantités retournées doivent être positives.',
            'quantity-exceeded'    => 'La quantité retournée dépasse la quantité terminée disponible pour un retour.',
        ],
    ],

    'move-line' => [
        'negative-quantity-not-allowed' => 'La réservation d\'une quantité négative n\'est pas autorisée.',
        'stale-line'                    => 'La ligne du mouvement de stock a changé. Rechargez-la avant de l’enregistrer ou de la supprimer.',
        'connection-mismatch'           => 'Les lignes de mouvement doivent utiliser la connexion à la base de données Stocks.',
    ],

    'product-quantity' => [
        'quantity-not-set'                 => 'La quantité ou la quantité réservée doit être définie.',
        'removal-strategy-not-implemented' => 'La stratégie de retrait :strategy n\'est pas implémentée.',
        'unreserve-more-than-stock'        => 'Il n\'est pas possible d\'annuler la réservation de plus de produits :name que vous n\'en avez en stock.',
        'reservation-out-of-bounds'        => 'La réservation dépasse le stock disponible ou libère plus que la quantité réservée.',
        'stale-count'                      => 'Le stock a changé après la saisie de ce comptage. Recomptez avant d’appliquer l’ajustement.',
        'duplicate-identity'               => 'Une quantité existe déjà pour cette combinaison d’entreprise, produit, emplacement, lot, colis et propriétaire du stock.',
    ],

    'scrap' => [
        'done-update' => 'Les rebuts terminés ne peuvent pas être modifiés.',
        'done-delete' => 'Les rebuts terminés ne peuvent pas être supprimés.',
    ],

    'product' => [
        'endless-loop-rule' => 'Configuration de règle invalide, la règle suivante provoque une boucle infinie : :name',
    ],

    'move' => [
        'quantity-rounding-mismatch' => 'La quantité effectuée pour le produit :product ne respecte pas la précision d\'arrondi définie sur l\'unité de mesure :unit. Veuillez modifier la quantité effectuée ou la précision d\'arrondi de votre unité de mesure.',
        'split-done-or-cancel'       => 'Vous ne pouvez pas diviser un mouvement de stock défini sur \'Terminé\' ou \'Annulé\'.',
        'split-draft'                => 'Vous ne pouvez pas diviser un mouvement à l\'état brouillon. Il doit d\'abord être confirmé.',
        'serial-already-assigned'    => 'Le numéro de série a déjà été attribué au produit : :product, Numéro de série : :serial_number',

        'cross-company' => [
            'title' => 'Transfert inter-société non autorisé',
            'body'  => 'Un transfert ne peut pas déplacer directement du stock entre des emplacements appartenant à des sociétés différentes (:source et :destination). Les transferts inter-sociétés ne sont pas encore pris en charge.',
        ],
    ],

    'rule' => [
        'delay-on'     => 'Délai sur :name',
        'days'         => '+ :days jour(s)',
        'time-horizon' => 'Horizon temporel',
    ],
];
