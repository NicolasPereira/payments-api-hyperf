# Data Model: PicPay Simplificado — Transferências

**Feature**: `001-picpay-simplificado-transferencias`

## User

Represents a person or merchant allowed to hold a wallet.

| Field | Type/constraint | Rules |
|-------|----------------|-------|
| `id` | positive identifier | Primary identity; referenced by wallets and transfers |
| `full_name` | non-empty text | Required for all user types |
| `document_type` | `cpf` or `cnpj` | `cpf` for `common`; `cnpj` for `merchant` |
| `document` | normalized digits | 11 digits for CPF or 14 for CNPJ; unique globally |
| `email` | normalized email | Required and unique globally; comparison is case-insensitive |
| `password_hash` | opaque secret hash | Plain password is never persisted or returned; input minimum is 8 characters |
| `type` | `common` or `merchant` | `merchant` can receive but cannot initiate transfers |
| `created_at` | timestamp | Creation audit |
| `updated_at` | timestamp | Last change audit |

### User Invariants

- Document is normalized before validation and persistence.
- CPF/CNPJ format and official check digits are valid before any persistence.
- A common user has CPF and a merchant has CNPJ.
- Duplicate normalized documents and duplicate normalized emails are rejected.
- A failed document validation creates neither a User nor a Wallet.

## Wallet

Represents the balance belonging to exactly one User.

| Field | Type/constraint | Rules |
|-------|----------------|-------|
| `id` | positive identifier | Primary identity |
| `user_id` | unique User reference | Exactly one wallet per user |
| `balance` | exact BRL amount, 2 decimals | Never negative; initialized to `0.00` |
| `created_at` | timestamp | Creation audit |
| `updated_at` | timestamp | Balance mutation audit |

## Transfer

Represents a requested or completed movement between two users.

| Field | Type/constraint | Rules |
|-------|----------------|-------|
| `id` | positive identifier | Primary identity |
| `value` | exact positive BRL amount | Input is a string with exactly 2 decimal places |
| `payer_id` | User reference | Must reference `common` |
| `payee_id` | User reference | Must differ from payer |
| `status` | state enum | `pending`, `authorized`, `completed`, or `failed` |
| `idempotency_key` | internal fingerprint | Derived from payer, payee, and normalized value; Redis is the request-window store |
| `authorization_result` | sanitized external result | Must not contain secrets or unnecessary sensitive data |
| `correlation_id` | trace/correlation value | Links logs, traces, and audit records |
| `authorized_at` | nullable timestamp | Set only after external authorization |
| `completed_at` | nullable timestamp | Set after atomic balance mutation |
| `created_at` | timestamp | Creation audit |

### Transfer State Transitions

```text
pending -> authorized -> completed
pending -> failed
authorized -> failed
```

- `completed` is terminal for the financial mutation.
- Authorization failure, insufficient balance, invalid payer role, or external
  authorizer failure results in no balance mutation.
- A database error during debit/credit rolls the transaction back and leaves
  no partial balance change.
- Notification failure does not change `completed` back to `failed`.

## Notification Outbox

Durable delivery intent for a payee notification. This is an implementation
support entity derived from FR-016 and does not represent a separate financial
operation.

| Field | Type/constraint | Rules |
|-------|----------------|-------|
| `id` | positive identifier | Primary identity |
| `transfer_id` | unique Transfer reference | One notification intent per transfer |
| `payee_id` | User reference | Recipient of the notification |
| `payload` | sanitized JSON document | Contains only data needed by notifier |
| `status` | state enum | `pending`, `processing`, `sent`, or `failed` |
| `attempts` | non-negative integer | Maximum 3 delivery attempts |
| `available_at` | timestamp | Next eligible processing time |
| `lease_until` | nullable timestamp | Prevents stuck processing ownership |
| `last_response` | sanitized text/metadata | Must not contain secrets or sensitive financial data |
| `created_at` | timestamp | Created in the transfer transaction |
| `sent_at` | nullable timestamp | Set on successful notification |

### Outbox State Transitions

```text
pending -> processing -> sent
pending -> processing -> pending   (retry available)
pending -> processing -> failed    (after attempt 3)
processing -> pending               (lease expired)
```

## Relationships

```text
User 1 ----- 1 Wallet
User 1 ----- * Transfer (as payer)
User 1 ----- * Transfer (as payee)
Transfer 1 - 1 Notification Outbox
User 1 ----- * Notification Outbox (as payee)
```

## Transaction Boundaries

1. Registration validates the domain object before opening persistence work;
   User and Wallet are created atomically.
2. Transfer authorization is external and occurs before the financial DB
   transaction.
3. The financial transaction locks both wallets, rechecks balance, persists
   Transfer, updates both Wallets, and inserts the Notification Outbox row.
4. Outbox delivery is independent of the financial transaction after commit.
