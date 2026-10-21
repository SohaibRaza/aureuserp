<?php

return [
    'move' => [
        'no-journal-found-title'      => 'A contabilidade não está configurada',
        'no-journal-found'            => 'Nenhum diário foi encontrado na empresa :company para nenhum destes tipos: :types. Configure primeiro um plano de contas para esta empresa.',
        'cancel-draft-only'           => 'Somente lançamentos em rascunho podem ser cancelados.',
        'reset-posted-cancelled-only' => 'Somente lançamentos contabilizados ou cancelados podem voltar ao rascunho.',
        'reset-exchange-difference'   => 'Este ajuste de moeda não pode voltar diretamente ao rascunho. Remova primeiro a conciliação de pagamento vinculada.',
        'posting-identity-changed'    => 'O lançamento ou a origem da sua reversão mudou. Recarregue-o antes de contabilizar.',
        'completed-bank-period'       => 'Esta operação afeta um período de conciliação bancária concluído. Reabra primeiro a conciliação.',
        'bank-period-busy'            => 'Há uma operação contábil ou um fechamento de período bancário em andamento. Atualize e tente novamente.',
    ],

    'reconciliation' => [
        'unavailable'        => 'Os lançamentos contábeis não estão mais disponíveis. Atualize e tente novamente.',
        'changed'            => 'A conciliação mudou enquanto esta ação aguardava. Atualize e tente novamente.',
        'bank-match'         => 'Remova a conciliação bancária antes de alterar estes lançamentos.',
        'already-reconciled' => 'Alguns destes lançamentos já estão conciliados. Atualize a página e tente novamente.',
    ],

    'tax-formula' => [
        'empty'                => 'A fórmula não pode estar vazia.',
        'invalid-character'    => 'A fórmula contém um caractere inválido: :character',
        'unexpected-token'     => 'A fórmula contém um elemento inesperado: :token',
        'unexpected-end'       => 'A fórmula termina de forma inesperada.',
        'unclosed-parenthesis' => 'A fórmula contém um parêntese não fechado.',
        'unknown-variable'     => 'Variável desconhecida ":variable". Apenas estas variáveis estão disponíveis: :variables',
        'unknown-function'     => 'Função desconhecida ":function". Apenas estas funções estão disponíveis: :functions',
    ],

    'payment-registration' => [
        'connection-mismatch'       => 'Todos os registros devem usar a conexão do banco de dados de Contabilidade para registrar o pagamento.',
        'unsaved-instruction'       => 'Salve a instrução de pagamento antes de registrar o pagamento.',
        'missing-instruction'       => 'A instrução de pagamento não existe.',
        'instruction-company'       => 'A instrução de pagamento não pertence à empresa ativa.',
        'changed-instruction'       => 'A instrução de pagamento mudou. Recarregue-a antes de registrar o pagamento.',
        'completed-conflict'        => 'A instrução de pagamento não corresponde ao seu registro concluído.',
        'unverified-instruction'    => 'A instrução de pagamento antiga não tem resultado verificado. Concilie-a antes de tentar novamente.',
        'missing-source-lines'      => 'A instrução de pagamento não tem linhas de origem.',
        'ineligible-sources'        => 'As origens do pagamento não são mais válidas para esta empresa.',
        'invalid-amount'            => 'O valor do pagamento deve ser positivo e não superar o saldo da origem.',
        'invalid-journal-method'    => 'O diário e o método de pagamento devem pertencer à empresa da instrução.',
        'invalid-batch-amount'      => 'Os lotes de pagamento devem somar o valor solicitado. A alocação parcial dos lotes não é aceita.',
        'mixed-batch-currencies'    => 'Lotes de pagamento em moedas diferentes exigem instruções separadas.',
        'request-company'           => 'A solicitação de pagamento não pertence à empresa ativa.',
        'request-key-conflict'      => 'A chave da solicitação de pagamento já foi usada para outra instrução.',
        'invalid-request-lines'     => 'As linhas de origem do pagamento devem pertencer ao lançamento e à empresa solicitados.',
        'request-argument-conflict' => 'Os dados da solicitação de pagamento não correspondem à instrução concluída.',
        'invalid-request-key'       => 'A chave da solicitação de pagamento deve conter de 1 a 128 letras ASCII, dígitos, pontos, dois-pontos, sublinhados ou hífens.',
    ],

    'payment' => [
        'unavailable'                  => 'O pagamento não está mais disponível. Atualize e tente novamente.',
        'company'                      => 'Mude para a empresa do pagamento para continuar.',
        'changed'                      => 'O pagamento ou seu lançamento mudou enquanto esta ação aguardava. Atualize e tente novamente.',
        'journal-mismatch'             => 'O lançamento do pagamento está vinculado incorretamente. Peça ao administrador de Contabilidade para verificá-lo.',
        'cancel-draft-only'            => 'Somente pagamentos em rascunho podem ser cancelados.',
        'reset-non-draft-only'         => 'Este pagamento já está em rascunho.',
        'post-draft-process-paid-only' => 'Este pagamento não pode ser confirmado no estado atual. Atualize e verifique seu status.',
        'bank-validation'              => 'Para registrar pagamentos com :method, valide primeiro a conta bancária do destinatário :partner.',
    ],

    'payment-reversal' => [
        'posted-only' => 'Somente lançamentos contabilizados podem ser estornados.',
        'paid-cancel' => 'Um lançamento pago não pode ser estornado e cancelado. Emita uma nota de crédito ou um reembolso.',
    ],
];
