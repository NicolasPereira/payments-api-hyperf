# Feature Specification: PicPay Simplificado — Transferências

**Feature Branch**: `001-picpay-simplificado-transferencias`

**Created**: 2026-08-30

**Status**: Draft

**Input**: User description: "PicPay Simplificado é uma plataforma de pagamentos simplificada. Nela é possível depositar e realizar transferências de dinheiro entre usuários. Temos 2 tipos de usuários, os comuns e lojistas, ambos têm carteira com dinheiro e realizam transferências entre eles. Requisitos: Nome Completo, CPF, e-mail e Senha com CPF/CNPJ e e-mails únicos; Usuários comuns podem enviar para lojistas e entre usuários; Lojistas só recebem; Validar saldo antes da transferência; Consultar serviço autorizador externo GET https://util.devi.tools/api/v2/authorize antes de finalizar; Transferência deve ser transacional e revertida em inconsistência; Notificação de recebimento via POST https://util.devi.tools/api/v1/notify (pode estar indisponível); RESTFul; Endpoint POST /transfer {value, payer, payee}. Revisão: Usuários comuns devem possuir CPF válido, lojistas CNPJ válido, validação conforme regras oficiais brasileiras incluindo formato, normalização e dígitos verificadores; documentos normalizados antes de validação/persistência removendo formatação; inválido MUST ser rejeitado antes de persistência; validação é regra de domínio independente de infraestrutura."

## Clarifications

### Session 2026-08-30

- Q: O escopo desta feature deve incluir um endpoint de depósito para creditar carteiras, ou o saldo inicial será provido apenas via seed/carga manual para testes? → A: Opção A — Apenas seed/carga manual, sem endpoint de depósito nesta feature; carteiras iniciam com 0 e saldo para testes é provido via seed/migração.
- Q: Qual política mínima de senha deve ser exigida no cadastro de usuários? → A: Opção A — Mínimo 8 caracteres, sem complexidade obrigatória, senha armazenada com hash seguro.
- Q: O POST /transfer deve exigir Idempotency-Key do cliente ou o servidor deve gerar chave interna? → A: Opção C — Chave interna via hash de payer+payee+value, persistida em Redis com TTL de 3 minutos. **Nota**: hash puro de conteúdo com TTL curto não garante idempotência completa (risco de falso positivo); recomendado evoluir para Idempotency-Key do cliente + TTL longo e persistência MySQL (ver discussão).
- Q: Qual deve ser o comportamento quando a notificação POST /notify falha após transferência concluída? → A: Opção A com Outbox Pattern — fire-and-forget, transferência retorna sucesso imediatamente, notificação registrada via Outbox na mesma transação e retentada async (até 3x) sem reverter saldo.
- Q: O campo value do POST /transfer deve ser aceito como número ou string decimal exata? → A: Opção B — Exigir string decimal exata (ex: "100.00"), rejeitar number, para garantir exatidão e evitar imprecisão de ponto flutuante.

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Transferência entre usuários comuns (Priority: P1)

Usuário comum com saldo transfere um valor para outro usuário comum via `POST /transfer`. O sistema valida saldo, consulta autorizador externo, executa débito/crédito atomicamente e dispara notificação ao recebedor.

**Why this priority**: É o fluxo primário de valor da plataforma e o contrato explicitamente exigido (`POST /transfer`). Sem ele não há MVP.

**Independent Test**: Criar dois usuários comuns com carteiras (ex: payer com 100, payee com 50), `POST /transfer` com `value="10.00", payer=1, payee=2`, autorizador retorna autorizado, e verificar saldos finais (90 e 60) e notificação disparada. Pode ser testado sem lojistas.

**Acceptance Scenarios**:

1. **Given** payer comum com saldo 100 e payee comum com saldo 20, **When** `POST /transfer {value:"10.00", payer:1, payee:2}` e autorizador retorna `authorized`, **Then** resposta 200/201 com confirmação, saldos 90 e 30, transferência registrada.
2. **Given** payer com saldo 5, **When** `POST /transfer {value:"10.00", payer:1, payee:2}`, **Then** resposta 422 com erro de saldo insuficiente e saldos inalterados.
3. **Given** autorizador retorna não autorizado, **When** `POST /transfer {value:"10.00", payer:1, payee:2}` com saldo suficiente, **Then** resposta 403/422 e saldos inalterados.
4. **Given** payer e payee são o mesmo usuário, **When** `POST /transfer {value:"10.00", payer:1, payee:2}`, **Then** resposta 422.

---

### User Story 2 - Transferência de usuário comum para lojista (Priority: P2)

Usuário comum transfere para lojista. Lojista recebe crédito e notificação, mas nunca pode ser payer.

**Why this priority**: Estende P1 para cobrir o segundo tipo de ator, essencial para regra de negócio mas dependente de P1.

**Independent Test**: Criar usuário comum (saldo 100) e lojista (saldo 0), `POST /transfer` com lojista como payee, autorizador ok → saldos 90 e 10.

**Acceptance Scenarios**:

1. **Given** usuário comum saldo 50 e lojista saldo 10, **When** `POST /transfer {value:"20.00", payer:usuario, payee:lojista}` autorizado, **Then** saldos 30 e 30.
2. **Given** lojista como payer, **When** `POST /transfer {value:"10.00", payer:lojista, payee:usuario}`, **Then** resposta 403/422 "lojista não pode enviar".

---

### User Story 3 - Cadastro de usuários e carteira com validação de documento (Priority: P3)

Cadastro de usuários comuns (CPF) e lojistas (CNPJ) com Nome Completo, documento, e-mail, Senha. Documento é validado por regras oficiais brasileiras (formato, normalização, dígitos verificadores) antes de qualquer persistência. CPF/CNPJ e e-mail únicos. Cada usuário recebe carteira com saldo.

**Why this priority**: Pré-requisito para transferências e identidade financeira; validação de documento garante integridade cadastral e é regra de domínio crítica.

**Independent Test**: `POST /users` com CPF válido normalizado ou formatado → 201; com CPF inválido (formato, dígitos, todos iguais) → 422 sem persistência; mesmo para CNPJ de lojista; tentativa com documento ou e-mail duplicado (em forma normalizada) → 409/422.

**Acceptance Scenarios**:

1. **Given** nenhum usuário com CPF 529.982.247-25 e email a@b.com, **When** cadastrar usuário comum com CPF `529.982.247-25` (formatado), **Then** 201 com usuário e carteira criada com saldo 0 e documento persistido normalizado `52998224725`.
2. **Given** nenhum usuário com CNPJ 11.222.333/0001-81, **When** cadastrar lojista com CNPJ `11.222.333/0001-81`, **Then** 201 com lojista criado e documento normalizado `11222333000181`.
3. **Given** usuário comum tenta cadastro com CPF `529.982.247-25` já cadastrado como `52998224725`, **When** enviar requisição, **Then** 409/422 por duplicidade (unicidade sobre forma normalizada).
4. **Given** cadastro com CPF com formato inválido `123.456`, **When** enviar, **Then** 422 "documento inválido — formato".
5. **Given** cadastro com CPF com dígitos verificadores inválidos `529.982.247-26`, **When** enviar, **Then** 422 "documento inválido — dígitos verificadores".
6. **Given** cadastro com CPF com todos dígitos iguais `111.111.111-11`, **When** enviar, **Then** 422.
7. **Given** cadastro de usuário comum com CNPJ válido `11.222.333/0001-81`, **When** enviar como `common`, **Then** 422 "tipo de documento incompatível com tipo de usuário" (common exige CPF).
8. **Given** cadastro de lojista com CPF válido `529.982.247-25`, **When** enviar como `merchant`, **Then** 422 "lojista exige CNPJ".
9. **Given** cadastro com CPF válido formatado ` 529.982.247-25 ` com espaços/pontuação, **When** enviar, **Then** normalizado antes de validar e persiste sem formatação, 201.

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

- `value` não-string, string com formato inválido, zero, negativo ou com mais de 2 casas decimais → 422 (contrato exige string exata ex: "100.00").
- `value` ausente ou `number` (ex: 100.0) → 422.
- `payer` ou `payee` inexistente → 404.
- `payer == payee` → 422.
- Saldo exatamente igual ao valor → deve permitir (saldo final 0).
- Saldo insuficiente por concorrência (duas transferências simultâneas) → apenas uma sucede, outra falha por saldo, sem saldo negativo e sem dupla dedução.
- Documento inválido por formato (tamanho incorreto, caracteres não numéricos após normalização) → 422 antes de persistência.
- Documento com dígitos verificadores inválidos (CPF 11 dígitos ou CNPJ 14 dígitos com DV incorreto) → 422.
- Documento com todos dígitos iguais (ex: `00000000000`, `11111111111`, `00000000000000`) → 422 mesmo que DV coincida.
- Documento com formatação (pontos, traços, barras, espaços) → deve ser normalizado removendo formatação antes de validação; `529.982.247-25` e `52998224725` são equivalentes para validação, unicidade e persistência.
- Tipo de usuário incompatível com documento (common com CNPJ, merchant com CPF) → 422.
- CPF válido mas já cadastrado como mesmo CPF normalizado → 409/422 por unicidade.
- Autorizador externo retorna payload inesperado → tratar como não autorizado/erro.
- Notificação externa lenta (latência alta) → não deve bloquear resposta da transferência além de timeout configurado; processamento assíncrono/retentativa se necessário.
- Depósito está fora do escopo desta feature; saldos para testes devem ser providos via seed/migração.

## Requirements *(mandatory)*

### Functional Requirements

- **FR-001**: Sistema MUST permitir cadastro de usuários com Nome Completo, documento, e-mail e Senha (mínimo 8 caracteres, armazenada com hash seguro), onde documento é CPF para `common` e CNPJ para `merchant`.
- **FR-002**: Sistema MUST garantir unicidade de documento (CPF/CNPJ normalizado) e unicidade de e-mail entre todos os usuários (um registro por documento normalizado ou e-mail).
- **FR-003**: Sistema MUST classificar usuários em dois tipos: `common` (usuário comum) e `merchant` (lojista), persistindo o tipo no cadastro.
- **FR-004**: Sistema MUST criar e manter uma carteira (balance) por usuário, com saldo não-negativo, inicializado em zero.
- **FR-005**: Sistema MUST expor endpoint REST `POST /transfer` com payload `{value: string, payer: id, payee: id}` conforme contrato e Clarification Q5.
- **FR-006**: Sistema MUST permitir que usuários `common` enviem transferências para usuários `common` e para `merchant`.
- **FR-007**: Sistema MUST impedir que `merchant` realize transferências como payer (apenas recebe).
- **FR-008**: Sistema MUST exigir que `value` seja string decimal exata com 2 casas decimais (ex: `"100.00"`), positiva e com no máximo 2 casas; valores `number` (ex: `100.0`) MUST ser rejeitados com 422; `payer != payee` MUST ser validado (Clarifications 2026-08-30).
- **FR-009**: Sistema MUST validar que `payer` e `payee` existem; caso contrário retornar 404.
- **FR-010**: Sistema MUST validar que `payer` possui saldo suficiente antes de autorizar; caso contrário retornar erro de saldo insuficiente e não alterar saldos.
- **FR-011**: Sistema MUST consultar serviço autorizador externo `GET https://util.devi.tools/api/v2/authorize` antes de finalizar a transferência; somente com resposta de autorização prosseguir.
- **FR-012**: Sistema MUST tratar resposta não autorizada do autorizador como rejeição da transferência sem alteração de saldos.
- **FR-013**: Sistema MUST tratar indisponibilidade/timeout/5xx do autorizador como falha da transferência sem alteração de saldos e com erro mapeado (não 2xx).
- **FR-014**: Sistema MUST executar débito do payer e crédito do payee de forma atômica/transacional; qualquer inconsistência MUST reverter a operação e devolver saldo ao payer.
- **FR-015**: Sistema MUST registrar transferência com valor, payer, payee, status (success/failed), timestamp e correlação.
- **FR-016**: Sistema MUST disparar notificação de recebimento ao payee via `POST https://util.devi.tools/api/v1/notify` após transferência concluída com sucesso usando Outbox Pattern; entrada de Outbox MUST ser criada na mesma transação da transferência, worker assíncrono MUST publicar para `POST /notify` com retentativa até 3x; falha/indisponibilidade MUST NOT reverter a transferência, mantendo status `pending`/`failed` para observabilidade (Clarifications 2026-08-30).
- **FR-017**: Sistema MUST tornar operação de transferência idempotente via chave interna (hash de `payer+payee+value`) persistida em Redis com TTL de 3 minutos; repetição dentro da janela MUST retornar mesmo resultado sem duplicar débito/crédito (Clarifications 2026-08-30).
- **FR-018**: Sistema MUST expor códigos HTTP e mensagens de erro consistentes e em português ou inglês padronizado (422 para validação, 403 para lojista payer, 404 para não encontrado, 503/502 para autorizador indisponível).
- **FR-019**: Sistema MUST validar unicidade e formato básico de e-mail no cadastro e retornar erro apropriado em duplicidade.
- **FR-020**: Sistema MUST exigir que usuários `common` possuam CPF válido (11 dígitos) e que `merchant` possuam CNPJ válido (14 dígitos), conforme regras oficiais brasileiras.
- **FR-021**: Sistema MUST validar CPF e CNPJ conforme regras oficiais brasileiras, incluindo verificação de formato (tamanho após normalização), rejeição de sequências com todos dígitos iguais e validação de dígitos verificadores.
- **FR-022**: Sistema MUST normalizar documentos antes de validação e antes de persistência, removendo caracteres de formatação (`.`, `-`, `/`, espaços) quando aplicável, e usar forma normalizada para validação, unicidade e armazenamento.
- **FR-023**: Sistema MUST rejeitar CPF ou CNPJ inválido (formato inválido ou dígitos verificadores inválidos) com 422 antes de qualquer persistência, sem criar usuário ou carteira e sem efeitos colaterais.
- **FR-024**: Sistema MUST implementar validação de CPF/CNPJ como regra de domínio pura, sem depender de infraestrutura, banco de dados ou HTTP; validação MUST ocorrer em camada de domínio e ser testável isoladamente.
- **FR-025**: Sistema MUST rejeitar incompatibilidade entre tipo de usuário e tipo de documento (ex: `common` com CNPJ ou `merchant` com CPF) com 422.

### Key Entities

- **User**: Representa pessoa no sistema. Atributos: id, nome completo, documento (CPF para `common` — 11 dígitos normalizados — ou CNPJ para `merchant` — 14 dígitos normalizados, único), e-mail (único), senha (hash seguro, mínimo 8 caracteres plain antes de hash), tipo (`common` | `merchant`), timestamps. Documento é armazenado normalizado (apenas dígitos).
- **Wallet**: Representa carteira financeira de um User. Atributos: id, user_id (FK único), balance (decimal não-negativo com 2 casas, moeda BRL implícita), updated_at. Relacionamento 1:1 com User.
- **Transfer**: Representa movimentação financeira. Atributos: id, value (string decimal exata convertida para decimal positivo com 2 casas, ex: "100.00"), payer_id (FK User), payee_id (FK User), status (pending/authorized/completed/failed), idempotency_key (interno hash payer+payee+value, opcional), authorized_at, completed_at, external_authorizer_response, created_at. Relaciona payer e payee.
- **Notification**: Representa tentativa de notificação ao payee. Atributos: id, transfer_id (FK), payee_id, channel (email/sms - abstrato), status (pending/sent/failed), attempts, last_response, created_at.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: Usuários conseguem concluir cadastro com documento válido em < 5s e tentativas com CPF/CNPJ/e-mail duplicado são rejeitadas em 100% dos casos (unicidade sobre forma normalizada).
- **SC-002**: Cadastro com CPF válido (common) ou CNPJ válido (merchant) é aceito em 100% dos casos quando formato e dígitos verificadores estão corretos, inclusive com formatação (`529.982.247-25` ≡ `52998224725`).
- **SC-003**: Cadastro com CPF/CNPJ com formato inválido, dígitos verificadores inválidos ou todos dígitos iguais é rejeitado em 100% dos casos com 422 e sem persistência.
- **SC-004**: Cadastro com incompatibilidade tipo-documento (common com CNPJ ou merchant com CPF) é rejeitado em 100% dos casos.
- **SC-005**: Transferência válida entre usuários comuns (saldo suficiente, autorizador autorizado) é concluída em < 3s em 95% das requisições com saldos atualizados corretamente.
- **SC-006**: Transferência com saldo insuficiente é rejeitada sem alteração de saldos em 100% dos casos.
- **SC-007**: Transferência onde payer é lojista é rejeitada em 100% dos casos.
- **SC-008**: Quando autorizador retorna não autorizado ou indisponível, nenhuma transferência altera saldos (0% de débito fantasma).
- **SC-009**: Em falha durante débito/crédito, transação é revertida e saldos permanecem consistentes (sem saldo negativo ou duplo crédito) em 100% das execuções.
- **SC-010**: Transferência concluída dispara notificação ao payee; falha da notificação não reverte saldo e é registrada para observabilidade/retentativa.
- **SC-011**: Operação idempotente: repetição de `POST /transfer` com o mesmo fingerprint interno de `payer+payee+value` dentro da janela de 3 minutos retorna o mesmo resultado sem segundo débito/crédito.
- **SC-012**: 90% dos usuários conseguem completar transferência válida na primeira tentativa sem assistência.
- **SC-013**: Sistema mantém taxa de erro < 1% para transferências válidas sob carga de 100 transferências/minuto simuladas.

## Assumptions

- Depósito de saldo está FORA do escopo desta feature (Clarifications 2026-08-30, Opção A); carteiras são inicializadas com saldo 0 e saldo para testes é provido via seed/migração — endpoint `POST /deposit` não será implementado nesta feature.
- Autenticacão para `POST /transfer` não exigida no contrato; assume-se endpoint aberto para MVP, podendo adicionar auth em iteração futura sem quebrar contrato.
- E-mail é validado por formato básico e unicidade; normalização de e-mail é case-insensitive para unicidade (ex: `A@b.com` ≡ `a@b.com`).
- Autorizador `GET https://util.devi.tools/api/v2/authorize` espera resposta com campo indicando autorização (ex: `{status:"authorized"}` ou `{message:"Autorizado"}`); implementação deve tratar variações comuns e mapear não autorizado vs erro.
- Notificação `POST https://util.devi.tools/api/v1/notify` é fire-and-forget após commit financeiro; timeout curto (ex: 2s) e retentativa assíncrona ou log são aceitáveis para lidar com instabilidade.
- Valores monetários em BRL (R$) com 2 casas decimais; persistência como `DECIMAL(15,2)` ou inteiro em centavos.
- Idempotência nesta versão usa fingerprint interno de `payer+payee+value` em Redis com TTL de 3 minutos; `Idempotency-Key` do cliente não faz parte do contrato desta feature.
- Sistema é RESTFul e stateless; sem necessidade de frontend nesta feature.
- Carga esperada baixa para MVP; requisitos de performance são para validação, não SLA de produção.
- Validação de CPF/CNPJ segue regras oficiais brasileiras (formato, normalização e dígitos verificadores); detalhes de implementação serão definidos no plan sem expor biblioteca específica nesta spec.
