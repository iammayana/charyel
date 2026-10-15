# Architecture Decision Record: Offline-First Synchronization Protocol

## 1. Context & Problem
Charyel is designed for uninterrupted gameplay in low-connectivity or fully offline environments. Users complete quizzes, earn XP, and level up without an active internet connection. When connectivity is restored, local session evidence must sync with the central server (`api.charyel.com`).

Naïve implementations (such as directly incrementing XP upon receiving a score payload) allow replay attacks, duplicate XP rewards during network retries, and data races.

## 2. Invariants & Guarantees

1. **Client-Generated Immutable Event IDs:**
   - Every completed quiz session generates a UUIDv7 `eventId` and is written to the local outbox queue in the **same transaction** as the local quiz history.
   - The payload includes `clientCreatedAt`, duration, question counts, and answers summary.
2. **Atomic Idempotency on Server (`processed_events`):**
   - The server wraps event processing in a database transaction.
   - It performs an atomic `INSERT INTO processed_events (event_id, device_id, user_id, event_type, status, created_at) VALUES (...)`.
   - If an event is re-submitted (e.g. timeout on client, retried sync), the unique constraint violation is caught:
     - The duplicate event is marked as `duplicate_ignored`.
     - XP is **never** re-credited.
     - The client receives an acknowledgment so it safely dequeues the outbox item.
3. **Delta-Based Question Bank Synchronization:**
   - Instead of redownloading the whole question bank, the server provides cursor-based deltas:
     - New or updated questions (`upsertedQuestions`).
     - Tombstones for retired/corrected questions (`tombstoneIds`).
4. **Anti-Abuse Verification:**
   - Minimum duration checks (e.g. impossible to answer 10 questions in 2 seconds).
   - Server validates score calculations against the question difficulty weights before committing to public leaderboards.
