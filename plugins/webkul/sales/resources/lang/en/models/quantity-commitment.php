<?php

return [
    'confirm'         => 'Use Confirm Order to record and validate the ordered quantities.',
    'unverified'      => 'This older order has no verified quantity record. Have its original quantities and conversions reviewed before changing its items or delivery details.',
    'company'         => 'The order, item and packaging must belong to the same company.',
    'unit'            => 'Choose compatible quantity units with a valid conversion and rounding value.',
    'quantity'        => 'Enter a positive quantity. An existing confirmed quantity may be reduced to zero to cancel its remainder.',
    'precision'       => 'This quantity cannot be represented in the item’s approved quantity units. Correct the quantity or unit.',
    'packaging'       => 'Choose packaging for this item with a valid number of stock units per pack.',
    'whole-pack'      => 'Enter whole packs and make sure their contents match the ordered quantity.',
    'locked'          => 'Unlock the sales order before changing its items or delivery details.',
    'conflict'        => 'This order has changed since you opened it. Keep a copy of your changes, reload the order, and try again.',
    'eligibility'     => 'A confirmed item with quantity history cannot become a note, expense or down payment, or have its product removed. Cancel its remaining quantity and add a separate line instead.',
    'source'          => 'A confirmed item cannot be moved to another order or company.',
    'delivery'        => 'Cancel the existing delivery and correct any invoice before changing the item, quantity unit, packaging, loading warehouse or delivery destination.',
    'delete'          => 'Confirmed items have a quantity history. Cancel their remaining quantity instead of deleting them.',
    'issued-quantity' => 'The ordered quantity cannot be lower than the goods already issued. Record an authorized return or onsite stock correction first.',
    'stock-unit'      => 'The item’s stock unit has changed. Review its confirmed quantity before requesting another delivery.',
];
