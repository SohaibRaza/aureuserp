<?php

return [
    'inventory-manager' => [
        'result-immutable'    => 'O resultado salvo de uma transferência não pode ser alterado nem excluído.',
        'replay-conflict'     => 'Esta transferência já terminou com outras instruções. Revise o resultado salvo antes de continuar.',
        'legacy-terminal'     => 'Esta transferência já está encerrada, mas não tem resultado de execução salvo. Peça ao responsável pelo estoque para revisá-la.',
        'terminal-change'     => 'Registros de estoque concluídos ou cancelados não podem ser alterados nem excluídos. Use uma devolução ou correção aprovada.',
        'canceled-completion' => 'Movimentos de estoque cancelados não podem ser concluídos. Crie outro movimento se ainda precisar mover o estoque.',
        'stock-not-ready'     => 'O estoque reservado não está mais disponível neste local de carga. Reconte as mercadorias e atualize a carga antes de dar saída no estoque.',
        'invalid-counts'      => 'Confira as quantidades contadas e recarregue a transferência. Devem ser quantidades finitas e não negativas das linhas existentes.',

        'stale-operation' => 'Esta transferência mudou enquanto você trabalhava. Recarregue-a e tente novamente.',

        'check-availability' => [
            'no-moves' => 'Nada para verificar disponibilidade.',
        ],

        'cancel-move' => [
            'already-done' => 'Você não pode cancelar uma movimentação de estoque definida como \'Concluída\'. Crie uma devolução para reverter as movimentações realizadas.',
        ],

        'unreserve-move' => [
            'already-done' => 'Você não pode cancelar a reserva de uma movimentação de estoque definida como \'Concluída\'.',
        ],

        'validate' => [
            'quantity-rounding-mismatch' => 'A quantidade concluída para o produto ":product" não respeita a precisão de arredondamento definida na unidade de medida ":unit". Altere a quantidade concluída ou a precisão de arredondamento da sua unidade de medida.',
            'no-negative-quantities'     => 'Quantidades negativas não são permitidas',
            'missing-lot-serial-number'  => "Informe um número de lote/série para o produto:\n:products",
        ],

        'run-procurement' => [
            'no-rule-found'      => "Nenhuma regra foi encontrada para reabastecer \":product\" em \":location\".\nVerifique a configuração das rotas no produto.",
            'no-source-location' => 'Nenhum local de origem definido na regra de estoque: :name!',
            'no-vendor-price'    => 'Não há preço de fornecedor correspondente para gerar o pedido de compra do produto :product (nenhum fornecedor definido, quantidade mínima não atingida, datas inválidas, ...). Acesse o formulário do produto e complete a lista de fornecedores.',
        ],

        'return' => [
            'origin'               => 'Devolução de :operation_name',
            'completed-only'       => 'Somente transferências concluídas podem ser devolvidas.',
            'missing-source-moves' => 'Uma devolução exige quantidades de movimentos de origem existentes.',
            'positive-quantity'    => 'As quantidades devolvidas devem ser positivas.',
            'quantity-exceeded'    => 'A quantidade devolvida supera a quantidade concluída disponível para devolução.',
        ],
    ],

    'move-line' => [
        'negative-quantity-not-allowed' => 'Não é permitido reservar uma quantidade negativa.',
        'stale-line'                    => 'A linha do movimento de estoque mudou. Recarregue-a antes de salvar ou excluir.',
        'connection-mismatch'           => 'As linhas dos movimentos devem usar a conexão do banco de dados de Estoque.',
    ],

    'product-quantity' => [
        'quantity-not-set'                 => 'Quantidade ou quantidade reservada deve ser definida.',
        'removal-strategy-not-implemented' => 'Estratégia de remoção :strategy não implementada.',
        'unreserve-more-than-stock'        => 'Não é possível cancelar a reserva de mais produtos de :name do que você tem em estoque.',
        'reservation-out-of-bounds'        => 'A reserva supera o estoque disponível ou libera mais do que a quantidade reservada.',
        'stale-count'                      => 'O estoque mudou após este registro de contagem. Reconte antes de aplicar o ajuste.',
        'duplicate-identity'               => 'Já existe uma quantidade para esta combinação de empresa, produto, local, lote, pacote e proprietário do estoque.',
    ],

    'scrap' => [
        'done-update' => 'Descartes concluídos não podem ser alterados.',
        'done-delete' => 'Descartes concluídos não podem ser excluídos.',
    ],

    'product' => [
        'endless-loop-rule' => 'Configuração de regra inválida; a seguinte regra causa um loop infinito: :name',
    ],

    'move' => [
        'quantity-rounding-mismatch' => 'A quantidade concluída para o produto :product não respeita a precisão de arredondamento definida na unidade de medida :unit. Altere a quantidade concluída ou a precisão de arredondamento da sua unidade de medida.',
        'split-done-or-cancel'       => 'Você não pode dividir uma movimentação de estoque definida como \'Concluída\' ou \'Cancelada\'.',
        'split-draft'                => 'Você não pode dividir uma movimentação em rascunho. Ela precisa ser confirmada primeiro.',
        'serial-already-assigned'    => 'O número de série já foi atribuído ao produto: :product, número de série: :serial_number',

        'cross-company' => [
            'title' => 'Transferência entre empresas não permitida',
            'body'  => 'Uma transferência não pode mover estoque diretamente entre locais que pertencem a empresas diferentes (:source e :destination). Transferências entre empresas ainda não são suportadas.',
        ],
    ],

    'rule' => [
        'delay-on'     => 'Atraso em :name',
        'days'         => '+ :days dia(s)',
        'time-horizon' => 'Horizonte de tempo',
    ],
];
