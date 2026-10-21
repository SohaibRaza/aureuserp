<?php

return [
    'inventory-manager' => [
        'result-immutable'    => 'A saved transfer result cannot be changed or deleted.',
        'replay-conflict'     => 'This transfer already finished with different instructions. Review its saved result before continuing.',
        'legacy-terminal'     => 'This transfer is already closed but has no saved execution result. Ask Inventory to review it.',
        'terminal-change'     => 'Completed or canceled stock records cannot be changed or deleted. Use a return or an approved correction.',
        'canceled-completion' => 'Canceled stock movements cannot be completed. Create a new movement if stock still needs to move.',
        'stock-not-ready'     => 'The reserved stock is no longer available at this loading location. Recount the goods and update the load before issuing stock.',
        'invalid-counts'      => 'Check the counted quantities and reload the transfer. Counts must be finite, nonnegative quantities for its existing lines.',

        'stale-operation' => 'This transfer changed while you were working. Reload it and try again.',

        'check-availability' => [
            'no-moves' => 'Nothing to check the availability for.',
        ],

        'cancel-move' => [
            'already-done' => 'You cannot cancel a stock move that has been set to \'Done\'. Create a return in order to reverse the moves which took place.',
        ],

        'unreserve-move' => [
            'already-done' => "You can not unreserve a stock move that has been set to 'Done'.",
        ],

        'validate' => [
            'quantity-rounding-mismatch' => 'The quantity done for the product ":product" doesn\'t respect the rounding precision defined on the unit of measure ":unit". Please change the quantity done or the rounding precision of your unit of measure.',
            'no-negative-quantities'     => 'No negative quantities allowed',
            'missing-lot-serial-number'  => "You need to supply a Lot/Serial Number for product:\n:products",
        ],

        'run-procurement' => [
            'no-rule-found'      => "No rule has been found to replenish \":product\" in \":location\".\nVerify the routes configuration on the product.",
            'no-source-location' => 'No source location defined on stock rule: :name!',
            'no-vendor-price'    => 'There is no matching vendor price to generate the purchase order for product :product (no vendor defined, minimum quantity not reached, dates not valid, ...). Go on the product form and complete the list of vendors.',
        ],

        'return' => [
            'origin'               => 'Return of :operation_name',
            'completed-only'       => 'Only a completed transfer can be returned.',
            'missing-source-moves' => 'A return requires quantities for existing source moves.',
            'positive-quantity'    => 'Return quantities must be positive.',
            'quantity-exceeded'    => 'The return quantity exceeds the completed quantity available to return.',
        ],
    ],

    'move-line' => [
        'negative-quantity-not-allowed' => 'Reserving a negative quantity is not allowed.',
        'stale-line'                    => 'The stock movement line changed. Reload it before saving or deleting.',
        'connection-mismatch'           => 'Stock movement lines must use the Inventory database connection.',
    ],

    'product-quantity' => [
        'quantity-not-set'                 => 'Quantity or Reserved Quantity should be set.',
        'removal-strategy-not-implemented' => 'Removal strategy :strategy not implemented.',
        'unreserve-more-than-stock'        => 'It is not possible to unreserve more products of :name than you have in stock.',
        'reservation-out-of-bounds'        => 'The reservation exceeds available stock or releases more than is reserved.',
        'stale-count'                      => 'Stock changed after this count was entered. Recount before applying the adjustment.',
        'duplicate-identity'               => 'A quantity already exists for this company, product, location, lot, package, and stock owner combination.',
    ],

    'scrap' => [
        'done-update' => 'Done scraps cannot be updated.',
        'done-delete' => 'Done scraps cannot be deleted.',
    ],

    'product' => [
        'endless-loop-rule' => "Invalid rule's configuration, the following rule causes an endless loop: :name",
    ],

    'move' => [
        'quantity-rounding-mismatch' => 'The quantity done for the product :product doesn\'t respect the rounding precision defined on the unit of measure :unit. Please change the quantity done or the rounding precision of your unit of measure.',
        'split-done-or-cancel'       => 'You cannot split a stock move that has been set to \'Done\' or \'Cancel\'.',
        'split-draft'                => 'You cannot split a draft move. It needs to be confirmed first.',
        'serial-already-assigned'    => 'The serial number has already been assigned to the Product: :product, Serial Number: :serial_number',

        'cross-company' => [
            'title' => 'Cross-company transfer not allowed',
            'body'  => 'A transfer cannot move stock directly between locations that belong to different companies (:source and :destination). Inter-company transfers are not supported yet.',
        ],
    ],

    'rule' => [
        'delay-on'     => 'Delay on :name',
        'days'         => '+ :days day(s)',
        'time-horizon' => 'Time Horizon',
    ],
];
