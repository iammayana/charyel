/**
 * Charyel Storage Adapter Abstraction
 * Decouples the UI and sync engine from browser IndexedDB vs native SQLite.
 */

export interface StorageAdapter {
  get<T>(table: string, key: string): Promise<T | null>;
  set<T>(table: string, key: string, value: T): Promise<void>;
  delete(table: string, key: string): Promise<void>;
  getAll<T>(table: string): Promise<T[]>;
  transaction<T>(tables: string[], mode: 'readonly' | 'readwrite', callback: () => Promise<T>): Promise<T>;
}

/**
 * In-memory fallback adapter for testing and lightweight SSR execution
 */
export class InMemoryStorageAdapter implements StorageAdapter {
  private store: Map<string, Map<string, unknown>> = new Map();

  async get<T>(table: string, key: string): Promise<T | null> {
    const tableMap = this.store.get(table);
    if (!tableMap) return null;
    return (tableMap.get(key) as T) ?? null;
  }

  async set<T>(table: string, key: string, value: T): Promise<void> {
    if (!this.store.has(table)) {
      this.store.set(table, new Map());
    }
    this.store.get(table)!.set(key, value);
  }

  async delete(table: string, key: string): Promise<void> {
    this.store.get(table)?.delete(key);
  }

  async getAll<T>(table: string): Promise<T[]> {
    const tableMap = this.store.get(table);
    if (!tableMap) return [];
    return Array.from(tableMap.values()) as T[];
  }

  async transaction<T>(
    _tables: string[],
    _mode: 'readonly' | 'readwrite',
    callback: () => Promise<T>
  ): Promise<T> {
    return await callback();
  }
}
