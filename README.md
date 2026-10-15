# Charyel

> **Grace (*Charis*) & Abundance (*Yelwa*)**
> An offline-first, cross-platform Bible trivia and community league platform built with React, TypeScript, and Laravel.

---

## 1. Monorepo Architecture

This repository is organized as a unified monorepo containing the offline-first web/PWA client, backend REST API, shared schema contracts, and operational documentation:

```text
charyel/
├── client/                 # Offline-First PWA (React 19 + TypeScript + Vite)
│   ├── public/             # Static web assets & PWA manifest
│   ├── src/
│   │   ├── components/     # Accessible UI primitives
│   │   ├── features/       # Quiz runner, offline sync outbox, leaderboards
│   │   ├── db/             # Storage adapters (IndexedDB Dexie / Capacitor SQLite)
│   │   ├── services/       # Typed HTTP client (/api/v1/sync)
│   │   └── styles/         # Vanilla CSS design tokens (tokens.css, variables.css)
├── server/                 # Laravel 13 REST API + Inertia Admin
│   ├── app/                # Controllers, Models, Jobs, Policies
│   ├── database/           # Versioned schema migrations & verified seeders
│   └── routes/             # Versioned API routes (/api/v1/*)
├── packages/
│   └── contracts/          # Shared TypeScript interfaces & JSON schema specifications
├── docs/
│   ├── architecture/       # Architecture Decision Records (ADRs) & Sync Specifications
│   └── operations/         # VPS runbooks, backup protocols & deployment procedures
├── .github/
│   └── workflows/          # CI/CD: Automated linting, typechecking, and tests
└── .gitignore              # Zero secret exposure boundaries
```

---

## 2. Technology Stack

- **Client:** React 19, TypeScript, Vite, Vanilla CSS design tokens, Capacitor (native targets).
- **Server:** Laravel 13, PHP 8.5, Oracle MySQL 9.7.2 LTS.
- **Local Dev Database:** MySQL 9.7.2 LTS on `127.0.0.1:3308` (`charyel_dev`).
- **Production Infrastructure:** Ubuntu 24.04.5 LTS, Nginx 1.24, Cloudflare Full (Strict) SSL.

---

## 3. Local Development Quickstart

### Prerequisites
- Node.js 24 LTS & npm 11+
- PHP 8.5+ with `pdo_mysql`, `mbstring`, `intl`, `curl`, `zip`
- Composer 2.9+
- Oracle MySQL 9.7.2 running on port `3308`

### Client Setup (React PWA)
```bash
cd client
npm install
npm run dev
```

### Server Setup (Laravel API)
```bash
cd server
composer install
cp .env.example .env
php artisan key:generate
# Configure DB_PORT=3308, DB_DATABASE=charyel_dev, DB_USERNAME=charyel_dev in .env
php artisan test
```

---

## 4. Key Architectural Invariants

1. **Offline-First:** All gameplay and question answering happens locally without network dependency.
2. **Idempotent Sync:** Completed quiz sessions submit client-generated UUIDv7 `event_id` tokens. Duplicate submissions never award duplicate XP or corrupt leaderboards.
3. **Least-Privilege Security:** Runtime web workers operate with strictly scoped database permissions (DML only in production).
4. **Node Remains Off Production:** Frontend bundles are compiled in CI/CD or during staging. The 2 GB production VPS hosts only static pre-compiled distribution assets.
