# Feature Specification: PicPay Simplificado — Transferências

**Feature Branch**: `001-picpay-simplificado-transferencias`

**Created**: 2026-08-30

**Status**: Draft

**Input**: User description: "PicPay Simplificado é uma plataforma de pagamentos simplificada. Nela é possível depositar e realizar transferências de dinheiro entre usuários. Temos 2 tipos de usuários, os comuns e lojistas, ambos têm carteira com dinheiro e realizam transferências entre eles. Requisitos: Nome Completo, CPF, e-mail e Senha com CPF/CNPJ e e-mails únicos; Usuários comuns podem enviar para lojistas e entre usuários; Lojistas só recebem; Validar saldo antes da transferência; Consultar serviço autorizador externo GET https://util.devi.tools/api/v2/authorize antes de finalizar; Transferência deve ser transacional e revertida em inconsistência; Notificação de recebimento via POST https://util.devi.tools/api/v1/notify (pode estar indisponível); RESTFul; Endpoint POST /transfer {value, payer, payee}"

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Transferência entre usuários comuns (Priority: P1)

Usuário comum com saldo transfere um valor para outro usuário comum via `POST /transfer`. O sistema valida saldo, consulta autorizador externo, executa débito/crédito atomicamente e dispara notificação ao recebedor.

**Why this priority**: É o fluxo primário de valor da plataforma e o contrato explicitamente exigido (`POST /transfer`). Sem ele não há MVP.

**Independent Test**: Criar dois usuários comuns com carteiras (ex: payer com 100, payee com 50), `POST /transfer` com `value=10, payer=1, payee=2`, autorizador retorna autorizado, e verificar saldos finais (90 e 60) e notificação disparada. Pode ser testado sem lojistas.

**Acceptance Scenarios**:

1. **Given** payer comum com saldo 100 e payee comum com saldo 20, **When** `POST /transfer {value:10, payer:1, payee:2}` e autorizador retorna `authorized`, **Then** resposta 200/201 com confirmação, saldos 90 e 30, transferência registrada.
2. **Given** payer com saldo 5, **When** `POST /transfer {value:10, payer:1, payee:2}`, **Then** resposta 422 com erro de saldo insuficiente e saldos inalterados.
3. **Given** autorizador retorna não autorizado, **When** `POST /transfer {value:10, payer:1, payee:2}` com saldo suficiente, **Then** resposta 403/422 e saldos inalterados.
4. **Given** payer e payee são o mesmo usuário, **When** `POST /transfer`, **Then** resposta 422.

---

### User Story 2 - Transferência de usuário comum para lojista (Priority: P2)

Usuário comum transfere para lojista. Lojista recebe crédito e notificação, mas nunca pode ser payer.

**Why this priority**: Estende P1 para cobrir o segundo tipo de ator, essencial para regra de negócio mas dependente de P1.

**Independent Test**: Criar usuário comum (saldo 100) e lojista (saldo 0), `POST /transfer` com lojista como payee, autorizador ok → saldos 90 e 10.

**Acceptance Scenarios**:

1. **Given** usuário comum saldo 50 e lojista saldo 10, **When** `POST /transfer {value:20, payer:usuario, payee:lojista}` autorizado, **Then** saldos 30 e 30.
2. **Given** lojista como payer, **When** `POST /transfer {value:10, payer:lojista, payee:usuario}`, **Then** resposta 403/422 "lojista não pode enviar".

---

### User Story 3 - Cadastro de usuários e carteira (Priority: P3)

Cadastro de usuários comuns e lojistas com Nome Completo, CPF, e-mail, Senha. CPF e e-mail únicos. Cada usuário recebe carteira com saldo.

**Why this priority**: Pré-requisito para transferências, mas pode ser previamente seeded para testar P1/P2; portanto P3.

**Independent Test**: `POST /users` (ou equivalente) com dados válidos → 201 com usuário e carteira; tentativa com CPF ou e-mail duplicado → 409/422.

**Acceptance Scenarios**:

1. **Given** nenhum usuário com CPF 123.456.789-00 e email a@b.com, **When** cadastrar usuário comum com esses dados, **Then** sucesso e carteira criada com saldo inicial 0.
2. **Given** usuário já existe com email a@b.com, **When** tentar cadastrar outro com mesmo email, **Then** erro 409/422.
3. **Given** CPF já cadastrado, **When** tentar novo cadastro com mesmo CPF, **Then** erro 409/422.
4. **Given** tipo `lojista`, **When** cadastrado, **Then** marcado como lojista e regra de não-envio aplicada.

---

### User Story 4 - Consulta e resiliência de serviços externos (Priority: P3)

Transferência depende de autorizador externo e notificação assíncrona. Falhas devem ser tratadas sem corrupção financeira.

**Why this priority**: Garante transação e observabilidade, mas é transversal a P1/P2.

**Independent Test**: Mock autorizador `GET https://util.devi.tools/api/v2/authorize` retornando autorizado/negado/timeout; mock notificação `POST https://util.devi.tools/api/v1/notify` com sucesso/falha/timeout; verificar que transferência é revertida em falha e notificação não bloqueia confirmação financeira.

**Acceptance Scenarios**:

1. **Given** autorizador indisponível (timeout/5xx), **When** `POST /transfer`, **Then** transferência não efetivada, saldos inalterados, erro 503/502 mapeado.
2. **Given** transferência autorizada e efetivada, **When** notificação retorna 5xx/timeout, **Then** transferência permanece concluída, notificação é retentada ou registrada como pendente sem reverter saldo.
3. **Given** notificação sucede, **When** transferência concluída, **Then** payee marcado como notificado.

---

### Edge Cases

- Valor zero, negativo ou com mais de 2 casas decimais → 422.
- `value` não numérico ou ausente → 422.
- `payer` ou `payee` inexistente → 404.
- `payer == payee` → 422.
- Saldo exatamente igual ao valor → deve permitir (saldo final 0).
- Saldo insuficiente por concorrência (duas transferências simultâneas) → apenas uma sucede, outra falha por saldo, sem saldo negativo e sem dupla dedução.
- CPF inválido (formato) → 422.
- Autorizador externo retorna payload inesperado → tratar como não autorizado/erro.
- Notificação externa lenta (latência alta) → não deve bloquear resposta da transferência além de timeout configurado; processamento assíncrono/retentativa se necessário.
- Depósito (fora do escopo P1) — se implementado, deve creditar carteira e ser auditável, mas não é obrigatório para `POST /transfer`.

## Requirements *(mandatory)*

### Functional Requirements

- **FR-001**: Sistema MUST permitir cadastro de usuários com Nome Completo, CPF, e-mail e Senha.
- **FR-002**: Sistema MUST garantir unicidade de CPF e unicidade de e-mail entre todos os usuários (um registro por CPF ou e-mail).
- **FR-003**: Sistema MUST classificar usuários em dois tipos: `common` (usuário comum) e `merchant` (lojista), persistindo o tipo no cadastro.
- **FR-004**: Sistema MUST criar e manter uma carteira (balance) por usuário, com saldo não-negativo, inicializado em zero.
- **FR-005**: Sistema MUST expor endpoint REST `POST /transfer` com payload `{value: number, payer: id, payee: id}` conforme contrato.
- **FR-006**: Sistema MUST permitir que usuários `common` enviem transferências para usuários `common` e para `merchant`.
- **FR-007**: Sistema MUST impedir que `merchant` realize transferências como payer (apenas recebe).
- **FR-008**: Sistema MUST validar que `value` é positivo, com no máximo 2 casas decimais, e que `payer != payee`.
- **FR-009**: Sistema MUST validar que `payer` e `payee` existem; caso contrário retornar 404.
- **FR-010**: Sistema MUST validar que `payer` possui saldo suficiente antes de autorizar; caso contrário retornar erro de saldo insuficiente e não alterar saldos.
- **FR-011**: Sistema MUST consultar serviço autorizador externo `GET https://util.devi.tools/api/v2/authorize` antes de finalizar a transferência; somente com resposta de autorização prosseguir.
- **FR-012**: Sistema MUST tratar resposta não autorizada do autorizador como rejeição da transferência sem alteração de saldos.
- **FR-013**: Sistema MUST tratar indisponibilidade/timeout/5xx do autorizador como falha da transferência sem alteração de saldos e com erro mapeado (não 2xx).
- **FR-014**: Sistema MUST executar débito do payer e crédito do payee de forma atômica/transacional; qualquer inconsistência MUST reverter a operação e devolver saldo ao payer.
- **FR-015**: Sistema MUST registrar transferência com valor, payer, payee, status (success/failed), timestamp e correlação.
- **FR-016**: Sistema MUST disparar notificação de recebimento ao payee via `POST https://util.devi.tools/api/v1/notify` após transferência concluída com sucesso; falha/indisponibilidade da notificação MUST NOT reverter a transferência.
- **FR-017**: Sistema MUST tornar operação de transferência idempotente quando cliente fornecer chave de idempotência (ex: `Idempotency-Key` header); repetição com mesma chave MUST retornar mesmo resultado sem duplicar side effects.
- **FR-018**: Sistema MUST expor códigos HTTP e mensagens de erro consistentes e em português ou inglês padronizado (422 para validação, 403 para lojista payer, 404 para não encontrado, 503/502 para autorizador indisponível).
- **FR-019**: Sistema MUST validar unicidade e formato de CPF e e-mail no cadastro e retornar erro apropriado em duplicidade.

### Key Entities

- **User**: Representa pessoa no sistema. Atributos: id, nome completo, CPF (único), e-mail (único), senha (hash), tipo (`common` | `merchant`), timestamps.
- **Wallet**: Representa carteira financeira de um User. Atributos: id, user_id (FK único), balance (decimal não-negativo com 2 casas, moeda BRL implícita), updated_at. Relacionamento 1:1 com User.
- **Transfer**: Representa movimentação financeira. Atributos: id, value (decimal positivo), payer_id (FK User), payee_id (FK User), status (pending/authorized/completed/failed), idempotency_key (opcional, único), authorized_at, completed_at, external_authorizer_response, created_at. Relaciona payer e payee.
- **Notification**: Representa tentativa de notificação ao payee. Atributos: id, transfer_id (FK), payee_id, channel (email/sms - abstrato), status (pending/sent/failed), attempts, last_response, created_at.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: Usuários conseguem concluir cadastro com dados válidos em < 5s e tentativas com CPF/e-mail duplicado são rejeitadas em 100% dos casos.
- **SC-002**: Transferência válida entre usuários comuns (saldo suficiente, autorizador autorizado) é concluída em < 3s em 95% das requisições com saldos atualizados corretamente.
- **SC-003**: Transferência com saldo insuficiente é rejeitada sem alteração de saldos em 100% dos casos.
- **SC-004**: Transferência onde payer é lojista é rejeitada em 100% dos casos.
- **SC-005**: Quando autorizador retorna não autorizado ou indisponível, nenhuma transferência altera saldos (0% de débito fantasma).
- **SC-006**: Em falha durante débito/crédito, transação é revertida e saldos permanecem consistentes (sem saldo negativo ou duplo crédito) em 100% das execuções.
- **SC-007**: Transferência concluída dispara notificação ao payee; falha da notificação não reverte saldo e é registrada para observabilidade/retentativa.
- **SC-008**: Operação idempotente: repetição de `POST /transfer` com mesma `Idempotency-Key` retorna mesmo resultado sem segundo débito/crédito.
- **SC-009**: 90% dos usuários conseguem completar transferência válida na primeira tentativa sem assistência.
- **SC-010**: Sistema mantém taxa de erro < 1% para transferências válidas sob carga de 100 transferências/minuto simuladas.

## Assumptions

- Depósito de saldo não é detalhado no contrato; assume-se que carteiras podem ser inicializadas via seed, endpoint `POST /deposit` opcional ou ajuste manual para testes — não obrigatório para validar `POST /transfer`, mas necessário para prover saldo.
- Autenticacão para `POST /transfer` não exigida no contrato; assume-se endpoint aberto para MVP, podendo adicionar auth em iteração futura sem quebrar contrato.
- CPF e e-mail são validados por formato básico e unicidade; validação completa de CPF (dígitos verificadores) é desejável mas não bloqueante para MVP.
- Autorizador `GET https://util.devi.tools/api/v2/authorize` espera resposta com campo indicando autorização (ex: `{status:"authorized"}` ou `{message:"Autorizado"}`); implementação deve tratar variações comuns e mapear não autorizado vs erro.
- Notificação `POST https://util.devi.tools/api/v1/notify` é fire-and-forget após commit financeiro; timeout curto (ex: 2s) e retentativa assíncrona ou log são aceitáveis para lidar com instabilidade.
- Valores monetários em BRL (R$) com 2 casas decimais; persistência como `DECIMAL(15,2)` ou inteiro em centavos.
- Idempotência via `Idempotency-Key` header é opcional para cliente mas MUST ser suportada pelo servidor quando fornecida.
- Sistema é RESTFul e stateless; sem necessidade de frontend nesta feature.
- Carga esperada baixa para MVP; requisitos de performance são para validação, não SLA de produção.

