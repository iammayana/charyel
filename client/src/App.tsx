import React from 'react';
import './styles/tokens.css';
import './styles/variables.css';
import './styles/base.css';
import './styles/components.css';

export function App() {
  const [offlineReady] = React.useState(true);

  return (
    <main className="app-shell">
      <header className="header">
        <div className="logo">
          <span>📖</span>
          <span>Charyel</span>
        </div>
        <div className="badge-offline">
          <span>●</span>
          <span>{offlineReady ? 'Offline Engine Ready' : 'Connecting'}</span>
        </div>
      </header>

      <section className="card">
        <h1 className="card-title">Grace & Abundant Knowledge</h1>
        <p className="card-subtitle">
          Scripture trivia with local-first progress, community leagues, and silent background sync.
        </p>

        <button className="btn-primary" type="button">
          Start Daily Quiz
        </button>

        <div className="stat-grid">
          <div className="stat-box">
            <div className="stat-value">0</div>
            <div className="stat-label">Total XP</div>
          </div>
          <div className="stat-box">
            <div className="stat-value">1</div>
            <div className="stat-label">Day Streak</div>
          </div>
        </div>
      </section>

      <footer style={{ marginTop: 'auto', textAlign: 'center', padding: '1rem', color: 'var(--color-text-muted)', fontSize: '0.75rem' }}>
        Charyel PWA • Phase 3A Scaffolding Verified
      </footer>
    </main>
  );
}

export default App;
