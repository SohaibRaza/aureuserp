<?php

return [
    'move' => [
        'no-journal-found-title'      => 'La contabilidad no está configurada',
        'no-journal-found'            => 'No se encontró ningún diario en la empresa :company para ninguno de estos tipos: :types. Configure primero un plan de cuentas para esta empresa.',
        'cancel-draft-only'           => 'Solo se pueden cancelar asientos en borrador.',
        'reset-posted-cancelled-only' => 'Solo los asientos contabilizados o cancelados pueden volver a borrador.',
        'reset-exchange-difference'   => 'Este ajuste de moneda no puede volver a borrador directamente. Quite primero su conciliación de pago.',
        'posting-identity-changed'    => 'El asiento o su origen de reversión ha cambiado. Vuelva a cargarlo antes de contabilizar.',
        'completed-bank-period'       => 'Esta operación afecta a un período de conciliación bancaria completado. Reabra primero la conciliación.',
        'bank-period-busy'            => 'Hay una operación contable o un cierre de período bancario en curso. Actualice e inténtelo de nuevo.',
    ],

    'reconciliation' => [
        'unavailable'        => 'Los asientos contables ya no están disponibles. Actualice e inténtelo de nuevo.',
        'changed'            => 'La conciliación cambió mientras esta acción esperaba. Actualice e inténtelo de nuevo.',
        'bank-match'         => 'Quite la conciliación bancaria antes de modificar estos asientos.',
        'already-reconciled' => 'Algunos de estos asientos ya están conciliados. Actualice la página e inténtelo de nuevo.',
    ],

    'tax-formula' => [
        'empty'                => 'La fórmula no puede estar vacía.',
        'invalid-character'    => 'La fórmula contiene un carácter no válido: :character',
        'unexpected-token'     => 'La fórmula contiene un elemento inesperado: :token',
        'unexpected-end'       => 'La fórmula termina de forma inesperada.',
        'unclosed-parenthesis' => 'La fórmula contiene un paréntesis sin cerrar.',
        'unknown-variable'     => 'Variable desconocida ":variable". Solo están disponibles estas variables: :variables',
        'unknown-function'     => 'Función desconocida ":function". Solo están disponibles estas funciones: :functions',
    ],

    'payment-registration' => [
        'connection-mismatch'       => 'Todos los registros deben usar la conexión de base de datos de Contabilidad para registrar el pago.',
        'unsaved-instruction'       => 'Guarde la instrucción de pago antes de registrarla.',
        'missing-instruction'       => 'La instrucción de pago no existe.',
        'instruction-company'       => 'La instrucción de pago no pertenece a la empresa activa.',
        'changed-instruction'       => 'La instrucción de pago ha cambiado. Vuelva a cargarla antes de registrarla.',
        'completed-conflict'        => 'La instrucción de pago no coincide con su registro completado.',
        'unverified-instruction'    => 'La instrucción de pago antigua no tiene un resultado verificado. Concíliela antes de reintentar.',
        'missing-source-lines'      => 'La instrucción de pago no tiene líneas de origen.',
        'ineligible-sources'        => 'Los orígenes del pago ya no son válidos para esta empresa.',
        'invalid-amount'            => 'El importe del pago debe ser positivo y no superar el importe pendiente del origen.',
        'invalid-journal-method'    => 'El diario y el método de pago deben pertenecer a la empresa de la instrucción.',
        'invalid-batch-amount'      => 'Los lotes de pago deben sumar el importe solicitado. No se admite la asignación parcial de lotes.',
        'mixed-batch-currencies'    => 'Los lotes en distintas monedas requieren instrucciones separadas.',
        'request-company'           => 'La solicitud de pago no pertenece a la empresa activa.',
        'request-key-conflict'      => 'La clave de solicitud de pago ya se utilizó para otra instrucción.',
        'invalid-request-lines'     => 'Las líneas de origen del pago deben pertenecer al asiento y a la empresa solicitados.',
        'request-argument-conflict' => 'Los datos de la solicitud de pago no coinciden con la instrucción completada.',
        'invalid-request-key'       => 'La clave de solicitud de pago debe contener entre 1 y 128 letras ASCII, dígitos, puntos, dos puntos, guiones bajos o guiones.',
    ],

    'payment' => [
        'unavailable'                  => 'El pago ya no está disponible. Actualice e inténtelo de nuevo.',
        'company'                      => 'Cambie a la empresa del pago para continuar.',
        'changed'                      => 'El pago o su asiento cambió mientras esta acción esperaba. Actualice e inténtelo de nuevo.',
        'journal-mismatch'             => 'El asiento del pago está vinculado incorrectamente. Pida al administrador de Contabilidad que lo revise.',
        'cancel-draft-only'            => 'Solo se pueden cancelar pagos en borrador.',
        'reset-non-draft-only'         => 'Este pago ya está en borrador.',
        'post-draft-process-paid-only' => 'No se puede confirmar este pago en su estado actual. Actualice y compruebe su estado.',
        'bank-validation'              => 'Para registrar pagos con :method, valide primero la cuenta bancaria del destinatario :partner.',
    ],

    'payment-reversal' => [
        'posted-only' => 'Solo se pueden revertir asientos contabilizados.',
        'paid-cancel' => 'No se puede revertir y cancelar un asiento pagado. Emita una nota de crédito o un reembolso.',
    ],
];
