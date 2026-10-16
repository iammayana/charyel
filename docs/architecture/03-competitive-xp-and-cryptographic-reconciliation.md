# Architecture Document: Competitive XP Accounting & Cryptographic Reconciliation

## 1. Executive Summary & Context

This document details the architectural design, mathematical reconciliation models, and cryptographic proof-of-possession analysis for **Phase 3C.1** of the Charyel platform.

Charyel distinguishes between:
1. **Lifetime Progression XP:** Unbounded cumulative XP earned across all lifetime trivia sessions, achievements, and quest milestones. Reflected in `user_profiles.total_xp` and audit-verified by `user_xp_ledger`.
2. **Weekly Competitive XP:** Fair-play, anti-burnout competitive points calculated for weekly church, locality, regional, and national leaderboards. Capped strictly at **400 XP per calendar day (UTC)**, with an unbreachable weekly ceiling of **2,800 XP** (7 days × 400 XP).

---

## 2. Mathematical Reconciliation Model

```
                    ┌────────────────────────────┐
                    │ Quiz Session Completed     │
                    │ Gross XP Earned = E        │
                    └─────────────┬──────────────┘
                                  │
                                  ▼
         ┌──────────────────────────────────────────────────┐
         │ CompetitiveXpAccountingService                   │
         │ - Normalize to UTC Calendar Date: D              │
         │ - Fetch / Lock Daily Counter: C (<= 400)         │
         │ - Headroom H = max(0, 400 - C)                   │
         │ - Awarded A = min(E, H)                          │
         │ - Capped Excess X = E - A                        │
         └────────┬─────────────────────────┬───────────────┘
                  │                         │
                  ▼                         ▼
     [Competitive Track]          [Lifetime Progression Track]
  ┌───────────────────────┐      ┌─────────────────────────────┐
  │ user_daily_counters   │      │ user_xp_ledger              │
  │ Daily Total C' = C + A│      │ Records Gross XP (E)        │
  │ (CHECK C' <= 400)     │      │ Event UUIDv7                │
  └───────────┬───────────┘      └──────────────┬──────────────┘
              │                                 │
              ▼                                 ▼
  ┌───────────────────────┐      ┌─────────────────────────────┐
  │ Weekly Rollup         │      │ user_profiles               │
  │ sum(C') for 7 days    │      │ total_xp = sum(ledger)      │
  │ Max = 2,800 XP        │      │                             │
  └───────────────────────┘      └─────────────────────────────┘
```

### 2.1 Formula Definitions

| Metric | Symbol | Definition / Formula | Hard Constraints |
| :--- | :---: | :--- | :--- |
| **Gross Session XP** | $E$ | XP computed from quiz score & difficulty | $E > 0$ |
| **Daily Current Total** | $C$ | Sum of competitive XP awarded on date $D$ | $0 \le C \le 400$ |
| **Daily Headroom** | $H$ | Remaining award capacity for date $D$ | $H = \max(0, 400 - C)$ |
| **Competitive Award** | $A$ | XP added to daily competitive counter | $A = \min(E, H)$ |
| **Daily Capped Excess** | $X$ | Non-competitive progression XP | $X = E - A$ |
| **New Daily Total** | $C'$ | Updated counter for date $D$ | $C' = C + A \le 400$ |
| **Weekly Leaderboard XP**| $W$ | Aggregate competitive score for week $T$ | $W = \sum_{D \in T} C_D \le 2,800$ |
| **Lifetime Profile XP** | $L$ | Cumulative lifetime progression XP | $L = \sum_{\text{all}} E$ |

### 2.2 Invariant Verification Equations

1. **Daily Cap Ceiling Invariant:**
   $$\forall p \in \text{Principals}, \forall D \in \text{Dates}: \quad C(p, D) \le 400$$
   *Enforced in application logic via pessimistic locking and at the storage engine level via MySQL `CHECK (competitive_xp_total <= 400)`.*

2. **Weekly Bounded Ceiling Invariant:**
   $$W(p, T) = \sum_{d=1}^{7} C(p, D_d) \le 7 \times 400 = 2,800$$
   *Guarantees no botting or infinite grinding can distort leaderboard rankings.*

3. **Reconciliation Audit Invariant:**
   $$L(u) = \sum \text{user\_xp\_ledger.xp\_amount} = \text{user\_profiles.total\_xp}$$
   *Guarantees that individual session awards in the ledger match profile totals exactly.*

---

## 3. Cryptographic Proof-of-Possession Reconciliation: HMAC-SHA256 vs. Ed25519

### 3.1 Background & Protocol Status
Phase 3B.1 established a secure guest identity architecture:
- Guests generate a cryptographically strong 256-bit random secret on first launch.
- The server stores the hash `secret_hash` (via bcrypt/Argon2).
- When claiming guest XP to a registered account, the client submits the secret token, and the server validates possession via `Hash::check()`.

The development team identified two primary cryptographic approaches for signing offline sync events and establishing guest identity ownership:
1. **HMAC-SHA256 (Symmetric Proof-of-Possession)**
2. **Ed25519 (Asymmetric Public-Key Digital Signatures)**

### 3.2 Detailed Technical Comparison

| Dimension | HMAC-SHA256 (Symmetric) | Ed25519 (Asymmetric) |
| :--- | :--- | :--- |
| **Key Generation** | Client generates 256-bit random bytes via `crypto.getRandomValues()`. | Client generates Ed25519 keypair `(sk, pk)`. |
| **Server Storage** | Stores `secret_hash` (bcrypt) or encrypted symmetric key in DB. | Stores raw 32-byte public key `pk` (hex/base64). Zero secrets on server. |
| **Server Compromise Risk**| If server secret or DB is breached, attacker could authenticate as guest unless stored with salted KDF. | **Zero risk:** Server never sees private key `sk`. Full DB dump cannot forge guest events. |
| **Web Browser Support** | 100% native in `crypto.subtle` across all desktop and mobile browsers. | Native in modern Chrome/Edge (113+) and Firefox (129+). Requires lightweight polyfill (`@noble/ed25519` ~5KB) for older iOS Safari / legacy Android WebView. |
| **Verification Performance**| ~0.01 ms per signature in PHP (`hash_hmac`). | ~0.5 - 1.2 ms per signature in PHP (`sodium_crypto_sign_verify_detached`). |
| **Replay Protection** | Timestamp + Nonce + Payload Hash in HMAC message. | Timestamp + Nonce + Payload Hash signed by $sk$. |
| **Account Claiming Protocol**| Client sends plaintext secret over TLS; server verifies `Hash::check($secret, $hash)`. | Client signs a challenge or claim statement: `Sign(sk, "CLAIM:" + userId + ":" + timestamp)`. Server verifies with `pk`. |

### 3.3 Recommendation & Migration Strategy

> [!IMPORTANT]
> **Zero Protocol Disruption in Phase 3C.1:**
> In accordance with project guardrails, the accepted Phase 3B.1 HMAC / bcrypt protocol remains active in the database schema (`guest_identities.secret_hash`).

**Proposed Future Upgrade Path (Phase 4 / Production Hardening):**
If zero-knowledge client signing is desired before public PWA launch:
1. Schema can accept an optional `public_key` column (`VARCHAR(64)` hex representation of Ed25519 public key) alongside `secret_hash`.
2. The PWA client can generate an Ed25519 keypair in IndexedDB with `extractable: false`.
3. Offline sync packets can include an HTTP header:
   `X-Charyel-Signature: ed25519=<sig>,timestamp=<ts>,nonce=<uuid>`
4. The backend can verify signatures via PHP 8.5's native `sodium_crypto_sign_verify_detached()`.
5. This maintains 100% backward compatibility for simple guest tokens while enabling military-grade asymmetric non-repudiation for advanced clients.

---

## 4. Verification Evidence & Test Coverage

All invariants are continuously verified under PHPUnit against MySQL 9.7.2 (`charyel_schema_test`):
- `test_migration_9_creates_daily_counters_table_with_constraints`: Confirms table structure and foreign key mappings.
- `test_award_competitive_xp_increments_counter_and_ledger`: Verifies counter and ledger incrementation.
- `test_enforces_strict_400_xp_daily_cap_and_caps_overflow`: Tests exact 400 XP cutoff and headroom allocation.
- `test_utc_midnight_boundary_resets_headroom_cleanly`: Confirms date boundary segregation at 23:59:59 UTC -> 00:00:00 UTC.
- `test_duplicate_submission_idempotency_returns_without_double_award`: Verifies replay protection via `processed_events`.
- `test_guest_identity_counter_isolation_and_principal_key`: Validates independent `G:<id>` and `U:<id>` namespaces.
- `test_partial_transaction_failure_rolls_back_atomically`: Verifies atomic rollback under transactional failures.
- `test_database_check_constraint_blocks_counter_exceeding_400`: Verifies hardware/engine-level MySQL check constraint enforcement.
