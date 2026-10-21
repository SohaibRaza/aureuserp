<?php

return [
    'inventory-manager' => [
        'result-immutable'    => 'No se puede modificar ni eliminar el resultado guardado de una transferencia.',
        'replay-conflict'     => 'Esta transferencia ya finalizó con otras instrucciones. Revise el resultado guardado antes de continuar.',
        'legacy-terminal'     => 'Esta transferencia ya está cerrada, pero no tiene un resultado guardado. Pida a Inventario que la revise.',
        'terminal-change'     => 'No se pueden modificar ni eliminar registros de stock completados o cancelados. Use una devolución o una corrección aprobada.',
        'canceled-completion' => 'No se pueden completar movimientos de stock cancelados. Cree otro movimiento si aún necesita trasladar el stock.',
        'stock-not-ready'     => 'El stock reservado ya no está disponible en este lugar de carga. Cuente de nuevo la mercancía y actualice la carga antes de dar salida al stock.',
        'invalid-counts'      => 'Compruebe las cantidades contadas y vuelva a cargar la transferencia. Deben ser cantidades finitas y no negativas de sus líneas existentes.',

        'stale-operation' => 'Esta transferencia cambió mientras trabajaba. Vuelva a cargarla e inténtelo de nuevo.',

        'check-availability' => [
            'no-moves' => 'No hay nada para comprobar la disponibilidad.',
        ],

        'cancel-move' => [
            'already-done' => 'No se puede cancelar un movimiento de existencias que se ha marcado como \'Hecho\'. Cree una devolución para revertir los movimientos que tuvieron lugar.',
        ],

        'unreserve-move' => [
            'already-done' => 'No se puede anular la reserva de un movimiento de existencias que se ha marcado como \'Hecho\'.',
        ],

        'validate' => [
            'quantity-rounding-mismatch' => 'La cantidad realizada para el producto ":product" no respeta la precisión de redondeo definida en la unidad de medida ":unit". Cambie la cantidad realizada o la precisión de redondeo de su unidad de medida.',
            'no-negative-quantities'     => 'No se permiten cantidades negativas',
            'missing-lot-serial-number'  => "Debe proporcionar un número de lote/serie para el producto:\n:products",
        ],

        'run-procurement' => [
            'no-rule-found'      => "No se ha encontrado ninguna regla para reabastecer \":product\" en \":location\".\nVerifique la configuración de las rutas en el producto.",
            'no-source-location' => '¡No hay ninguna ubicación de origen definida en la regla de existencias: :name!',
            'no-vendor-price'    => 'No hay ningún precio de proveedor coincidente para generar la orden de compra del producto :product (no hay proveedor definido, no se alcanza la cantidad mínima, fechas no válidas, ...). Vaya al formulario del producto y complete la lista de proveedores.',
        ],

        'return' => [
            'origin'               => 'Devolución de :operation_name',
            'completed-only'       => 'Solo se pueden devolver transferencias completadas.',
            'missing-source-moves' => 'La devolución requiere cantidades de movimientos de origen existentes.',
            'positive-quantity'    => 'Las cantidades devueltas deben ser positivas.',
            'quantity-exceeded'    => 'La cantidad devuelta supera la cantidad completada disponible para devolución.',
        ],
    ],

    'move-line' => [
        'negative-quantity-not-allowed' => 'No se permite reservar una cantidad negativa.',
        'stale-line'                    => 'La línea del movimiento de stock cambió. Vuelva a cargarla antes de guardar o eliminar.',
        'connection-mismatch'           => 'Las líneas de movimientos deben usar la conexión de base de datos de Inventario.',
    ],

    'product-quantity' => [
        'quantity-not-set'                 => 'Debe establecerse la cantidad o la cantidad reservada.',
        'removal-strategy-not-implemented' => 'La estrategia de retirada :strategy no está implementada.',
        'unreserve-more-than-stock'        => 'No es posible anular la reserva de más productos de :name que los que tiene en existencias.',
        'reservation-out-of-bounds'        => 'La reserva supera el stock disponible o libera más cantidad de la reservada.',
        'stale-count'                      => 'El stock cambió después de este recuento. Cuente de nuevo antes de aplicar el ajuste.',
        'duplicate-identity'               => 'Ya existe una cantidad para esta combinación de empresa, producto, ubicación, lote, paquete y propietario del stock.',
    ],

    'scrap' => [
        'done-update' => 'No se pueden modificar desechos completados.',
        'done-delete' => 'No se pueden eliminar desechos completados.',
    ],

    'product' => [
        'endless-loop-rule' => 'Configuración de regla no válida, la siguiente regla provoca un bucle infinito: :name',
    ],

    'move' => [
        'quantity-rounding-mismatch' => 'La cantidad realizada para el producto :product no respeta la precisión de redondeo definida en la unidad de medida :unit. Cambie la cantidad realizada o la precisión de redondeo de su unidad de medida.',
        'split-done-or-cancel'       => 'No se puede dividir un movimiento de existencias que se ha marcado como \'Hecho\' o \'Cancelado\'.',
        'split-draft'                => 'No se puede dividir un movimiento en borrador. Primero debe confirmarse.',
        'serial-already-assigned'    => 'El número de serie ya se ha asignado al producto: :product, número de serie: :serial_number',

        'cross-company' => [
            'title' => 'Transferencia entre empresas no permitida',
            'body'  => 'Una transferencia no puede mover existencias directamente entre ubicaciones que pertenecen a empresas diferentes (:source y :destination). Las transferencias entre empresas aún no son compatibles.',
        ],
    ],

    'rule' => [
        'delay-on'     => 'Retraso en :name',
        'days'         => '+ :days día(s)',
        'time-horizon' => 'Horizonte temporal',
    ],
];
