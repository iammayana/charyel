/**
 * Charyel Shared Schema Contracts
 * Used by both client (React/PWA/Capacitor) and server (Laravel/Inertia)
 */

export type UUID = string;
export type TimestampISO = string;

// --- 1. Quiz Session & Event Identifiers ---
export interface QuizSessionPayload {
  sessionId: UUID;
  quizId: string;
  category: string;
  difficulty: 'easy' | 'medium' | 'hard';
  score: number;
  maxScore: number;
  totalQuestions: number;
  correctAnswers: number;
  durationSeconds: number;
  completedAt: TimestampISO;
  deviceTimestamp: TimestampISO;
}

// --- 2. Offline Sync Protocol ---
export type SyncEventType = 'quiz_completed' | 'profile_updated' | 'streak_logged';

export interface SyncEvent<T = unknown> {
  eventId: UUID; // Immutable client-generated UUIDv7
  deviceId: string;
  userId?: string | null;
  type: SyncEventType;
  schemaVersion: number;
  payload: T;
  clientCreatedAt: TimestampISO;
  retryCount: number;
}

export interface SyncPayload {
  deviceId: string;
  events: SyncEvent<QuizSessionPayload | unknown>[];
  lastQuestionCursor?: string | null;
}

export interface SyncEventResult {
  eventId: UUID;
  status: 'accepted' | 'duplicate_ignored' | 'rejected';
  reason?: string;
  processedAt: TimestampISO;
}

export interface SyncResponse {
  success: boolean;
  results: SyncEventResult[];
  questionDeltas?: QuestionDeltaBatch | null;
  serverTimestamp: TimestampISO;
}

// --- 3. Question Bank & Deltas ---
export interface QuestionOption {
  id: string;
  text: string;
}

export interface QuestionRecord {
  id: string;
  category: string;
  difficulty: 'easy' | 'medium' | 'hard';
  verseReference: string;
  questionText: string;
  options: QuestionOption[];
  // Note: correctOptionId is kept server-side or encrypted for competitive modes
  version: number;
  deletedAt?: TimestampISO | null;
}

export interface QuestionDeltaBatch {
  nextCursor: string;
  hasMore: boolean;
  upsertedQuestions: QuestionRecord[];
  tombstoneIds: string[]; // Soft-deleted question IDs
}

// --- 4. Leaderboard Standings ---
export type LeaderboardScope = 'neighborhood' | 'district' | 'state' | 'country' | 'church' | 'global';

export interface LeaderboardEntry {
  rank: number;
  userId: string;
  displayName: string;
  churchName?: string | null;
  regionCode: string;
  xp: number;
  weeklyScore: number;
  avatarSeed?: string;
}

export interface LeaderboardResponse {
  scope: LeaderboardScope;
  scopeIdentifier: string;
  period: 'all_time' | 'weekly' | 'monthly';
  entries: LeaderboardEntry[];
  totalParticipants: number;
  lastUpdated: TimestampISO;
}

// --- 5. Standardized API Error Payload ---
export interface ApiErrorResponse {
  success: false;
  error: {
    code: string;
    message: string;
    details?: Record<string, string[]>;
  };
  timestamp: TimestampISO;
}
